<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use App\Support\ProtectedRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * User management within a company. Each user is a real login (email + password)
 * and gets one company-scoped role. HO super admins can manage users in any
 * company; everyone else needs users.* permissions in the active company.
 */
class UserController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->listCompanyId($request);
        $this->allow($request, 'users.view');

        $users = User::query()
            ->activeMembers($companyId)
            ->with([
                'companies:id,name',
                'roles' => fn ($q) => $q->when($companyId !== null, fn ($q2) => $q2->wherePivot('company_id', $companyId)),
            ])
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->withQueryString();

        return $this->ok(UserResource::collection($users));
    }

    /** Roles available to assign in this company. */
    public function formData(Request $request): JsonResponse
    {
        $company = $this->listCompany($request);
        $this->allow($request, 'users.view');

        $roles = Role::query()->orderBy('name')->get(['id', 'name']);

        return $this->ok(['roles' => $roles]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->allow($request, 'users.view');
        $companyId = $this->resolveUserCompanyContext($request, $user);

        return $this->ok($this->present($user, $companyId));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validated();

        $user = DB::transaction(function () use ($company, $data) {
            $user = User::create([
                'name'      => $data['name'],
                'email'     => $data['email'],
                'password'  => Hash::make($data['password']),
                'phone'     => $data['phone'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $user->companies()->syncWithoutDetaching([$company->id => ['is_default' => true]]);
            $this->syncCompanyRole($user, $company->id, $data['role_id'] ?? null);

            return $user;
        });

        return $this->created($this->present($user, $company->id), 'User created.');
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $companyId = $this->resolveUserCompanyContext($request, $user);
        $data = $request->validated();

        DB::transaction(function () use ($user, $companyId, $data) {
            $user->fill([
                'name'      => $data['name'],
                'email'     => $data['email'],
                'phone'     => $data['phone'] ?? null,
                'is_active' => $data['is_active'] ?? $user->is_active,
            ]);
            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }
            $user->save();
            $this->syncCompanyRole($user, $companyId, $data['role_id'] ?? null);
        });

        return $this->ok($this->present($user->refresh(), $companyId), 'User updated.');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $companyId = $this->resolveUserCompanyContext($request, $user);
        $this->allow($request, 'users.delete');

        abort_if(ProtectedRecords::isProtectedUser($user) && $this->company($request)->code === ProtectedRecords::HO_COMPANY_CODE, 403, 'The default HO admin cannot be removed from this company.');

        DB::transaction(function () use ($user, $companyId) {
            $this->syncCompanyRole($user, $companyId, null);
            $user->companies()->detach($companyId);

            if (! $user->is_super_admin && ! $user->companies()->exists()) {
                $user->tokens()->delete();
                $user->update(['is_active' => false]);
            }
        });

        return $this->message('User removed from this company.');
    }

    /** Replace the user's role for this company with (at most) one global role. */
    private function syncCompanyRole(User $user, int|string $companyId, ?string $roleId): void
    {
        DB::table('role_user')
            ->where('user_id', $user->id)
            ->where('company_id', $companyId)
            ->delete();

        if ($roleId) {
            DB::table('role_user')->insert([
                'user_id'    => $user->id,
                'company_id' => $companyId,
                'role_id'    => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function present(User $user, int|string $companyId): UserResource
    {
        $user->load([
            'companies:id,name',
            'roles' => fn ($q) => $q->wherePivot('company_id', $companyId),
        ]);

        return new UserResource($user);
    }

    /** Resolve which company context to use for show/update/delete. */
    private function resolveUserCompanyContext(Request $request, User $user): int|string
    {
        $headerCompanyId = $this->company($request)->id;

        if ($user->companies()->whereKey($headerCompanyId)->exists()) {
            return $headerCompanyId;
        }

        $param = $request->query('company_id');
        if (filled($param) && $user->companies()->whereKey($param)->exists()) {
            return $param;
        }

        if ($request->user()->is_super_admin) {
            $memberCompany = $user->companies()->value('companies.id');
            abort_unless($memberCompany, 404, 'User has no company membership.');

            return $memberCompany;
        }

        abort(404, 'User is not in this company.');
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }

    public function toggleStatus(Request $request, User $user): JsonResponse
    {
        $companyId = $this->resolveUserCompanyContext($request, $user);
        $this->allow($request, 'users.update');
        abort_if((bool) $user->is_super_admin, 403, 'A super admin cannot be deactivated here.');
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $user->update(['is_active' => $data['is_active']]);

        return $this->ok(['id' => $user->id, 'is_active' => (bool) $user->is_active], 'Status updated.');
    }
}
