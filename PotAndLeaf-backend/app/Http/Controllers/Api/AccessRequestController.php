<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Models\Permission;
use App\Models\User;
use App\Support\Api\ApiResponse;
use App\Support\Rbac\AccessRequestTables;
use App\Support\Rbac\PermissionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Request access": a user picks permissions they do not have and says why;
 * an admin of that company (or HO) approves some or all of them, or rejects.
 * Approved permissions are added for that user in that company only.
 */
class AccessRequestController extends Controller
{
    use ApiResponse;

    /** Every permission a user may ask for, grouped, with what they already have. */
    public function formData(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $this->company($request)->id;
        $have = $user->is_super_admin ? collect(['*']) : $user->permissionNamesForCompany($companyId);
        $full = $have->contains('*');

        $groups = [];
        foreach ($this->requestable() as $module => $perms) {
            $groups[] = [
                'module' => $module,
                'permissions' => collect($perms)->map(fn ($label, $name) => [
                    'name' => $name,
                    'label' => $label,
                    'has' => $full || $have->contains($name) || $have->contains(explode('.', $name)[0].'.*'),
                ])->values(),
            ];
        }

        return $this->ok([
            'groups' => $groups,
            'ready' => AccessRequestTables::ready(),
            'mine' => ! AccessRequestTables::ready() ? [] : AccessRequest::query()->where('company_id', $companyId)->where('user_id', $user->id)
                ->with('decider:id,name')->latest()->limit(50)->get()
                ->map(fn ($r) => $this->present($r)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(AccessRequestTables::ready(), 503, AccessRequestTables::MESSAGE);
        $user = $request->user();
        $companyId = $this->company($request)->id;
        $data = $request->validate([
            'permissions'   => ['required', 'array', 'min:1', 'max:100'],
            'permissions.*' => ['required', 'string', 'distinct'],
            'reason'        => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'permissions.required' => 'Tick at least one thing you need.',
            'reason.required'      => 'Say why you need it, so the admin can decide.',
        ]);

        $allowed = collect($this->requestable())->flatMap(fn ($p) => array_keys($p));
        $have = $user->permissionNamesForCompany($companyId);
        $wanted = collect($data['permissions'])->filter(fn ($p) => $allowed->contains($p))
            ->reject(fn ($p) => $have->contains($p) || $have->contains('*'))->values();
        if ($wanted->isEmpty()) {
            throw ValidationException::withMessages(['permissions' => 'You already have everything you ticked.']);
        }
        $pending = AccessRequest::query()->where('company_id', $companyId)->where('user_id', $user->id)->where('status', 'pending')->get();
        $already = $pending->flatMap(fn ($r) => $r->permissions)->unique();
        if ($wanted->diff($already)->isEmpty()) {
            throw ValidationException::withMessages(['permissions' => 'You have already asked for these. Wait for the admin to answer.']);
        }

        $req = AccessRequest::create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'permissions' => $wanted->all(),
            'reason' => $data['reason'],
            'status' => 'pending',
        ]);

        return $this->created($this->present($req), 'Request sent. An admin will approve or reject it.');
    }

    /** Requests the current user may decide on (not their own). */
    public function index(Request $request): JsonResponse
    {
        abort_unless(AccessRequestTables::ready(), 503, AccessRequestTables::MESSAGE);
        $user = $request->user();
        $this->assertApprover($request);
        $status = $request->query('status', 'pending');

        $rows = AccessRequest::query()
            // HO sees every company's requests; a company admin sees their company.
            ->when(! $user->is_super_admin, fn ($q) => $q->where('company_id', $this->company($request)->id))
            ->where('user_id', '!=', $user->id)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['user:id,name,email', 'decider:id,name', 'company:id,name'])
            ->latest()->paginate(25);

        $rows->getCollection()->transform(fn ($r) => $this->present($r, $request));

        return $this->ok($rows);
    }

    public function approve(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        abort_unless(AccessRequestTables::ready(), 503, AccessRequestTables::MESSAGE);
        $this->assertCanDecide($request, $accessRequest);
        $data = $request->validate([
            'permissions'   => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string'],
            'note'          => ['nullable', 'string', 'max:500'],
        ], ['permissions.required' => 'Tick at least one permission to give, or reject the request.']);

        $give = collect($data['permissions'])->intersect($accessRequest->permissions)->values();
        if ($give->isEmpty()) {
            throw ValidationException::withMessages(['permissions' => 'Tick at least one of the requested permissions.']);
        }
        $approver = $request->user();
        if (! $approver->is_super_admin) {
            $mine = $approver->permissionNamesForCompany($accessRequest->company_id);
            $cannot = $give->reject(fn ($p) => $mine->contains('*') || $mine->contains($p));
            if ($cannot->isNotEmpty()) {
                throw ValidationException::withMessages(['permissions' => 'You can only give permissions you have yourself: '.$this->labels($cannot)->join(', ').'. Ask HO to approve those.']);
            }
        }

        DB::transaction(function () use ($accessRequest, $give, $approver, $data) {
            $ids = Permission::query()->whereIn('name', $give)->pluck('id');
            foreach ($ids as $pid) {
                DB::table('user_permissions')->updateOrInsert(
                    ['user_id' => $accessRequest->user_id, 'company_id' => $accessRequest->company_id, 'permission_id' => $pid],
                    ['granted_by' => $approver->id, 'access_request_id' => $accessRequest->id, 'created_at' => now(), 'updated_at' => now()],
                );
            }
            $accessRequest->update([
                'status' => 'approved',
                'granted' => $give->all(),
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'decision_note' => $data['note'] ?? null,
            ]);
        });

        $all = $give->count() === count($accessRequest->permissions);

        return $this->ok($this->present($accessRequest->fresh(['user:id,name,email', 'decider:id,name']), $request),
            $all ? 'Approved. The user has the new access now (they may need to refresh the page).' : 'Approved the ticked permissions; the rest were not given.');
    }

    public function reject(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        abort_unless(AccessRequestTables::ready(), 503, AccessRequestTables::MESSAGE);
        $this->assertCanDecide($request, $accessRequest);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $accessRequest->update([
            'status' => 'rejected',
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'decision_note' => $data['note'] ?? null,
        ]);

        return $this->ok($this->present($accessRequest->fresh(['user:id,name,email', 'decider:id,name']), $request), 'Request rejected.');
    }

    /** The person who asked can withdraw a request that is still waiting. */
    public function destroy(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        abort_unless(AccessRequestTables::ready(), 503, AccessRequestTables::MESSAGE);
        abort_unless((int) $accessRequest->user_id === (int) $request->user()->id, 403);
        abort_unless($accessRequest->status === 'pending', 422, 'Only a waiting request can be withdrawn.');
        $accessRequest->update(['status' => 'cancelled']);

        return $this->message('Request withdrawn.');
    }

    /** Small numbers for the bell: requests waiting for me, and answers to mine. */
    public function notices(Request $request): JsonResponse
    {
        if (! AccessRequestTables::ready()) {
            return $this->ok(['waiting_for_me' => 0, 'my_answers' => []]);
        }
        $user = $request->user();
        $companyId = $this->company($request)->id;
        $waiting = $this->isApprover($request)
            ? AccessRequest::query()->when(! $user->is_super_admin, fn ($q) => $q->where('company_id', $companyId))
                ->where('status', 'pending')->where('user_id', '!=', $user->id)->count()
            : 0;
        $answers = AccessRequest::query()->where('company_id', $companyId)->where('user_id', $user->id)
            ->whereIn('status', ['approved', 'rejected'])->where('decided_at', '>=', now()->subDays(7))
            ->with('decider:id,name')->latest('decided_at')->limit(5)->get()->map(fn ($r) => $this->present($r));

        return $this->ok(['waiting_for_me' => $waiting, 'my_answers' => $answers]);
    }

    /** Extra permissions given to a user in this company (shown on the user page). */
    public function userExtras(Request $request, User $user): JsonResponse
    {
        abort_unless(AccessRequestTables::ready(), 503, AccessRequestTables::MESSAGE);
        $this->assertApprover($request);
        $companyId = $this->company($request)->id;

        return $this->ok($this->extrasFor($user, $companyId));
    }

    public function removeExtra(Request $request, User $user, string $permission): JsonResponse
    {
        abort_unless(AccessRequestTables::ready(), 503, AccessRequestTables::MESSAGE);
        $this->assertApprover($request);
        $companyId = $this->company($request)->id;
        $pid = Permission::query()->where('name', $permission)->value('id');
        abort_unless($pid, 404);
        DB::table('user_permissions')->where(['user_id' => $user->id, 'company_id' => $companyId, 'permission_id' => $pid])->delete();

        return $this->ok($this->extrasFor($user, $companyId), 'Permission removed.');
    }

    // ---------------------------------------------------------------------

    private function extrasFor(User $user, int|string $companyId): array
    {
        $labels = collect(PermissionRegistry::flat())->keyBy('name');

        return DB::table('user_permissions')
            ->join('permissions', 'permissions.id', '=', 'user_permissions.permission_id')
            ->leftJoin('users as g', 'g.id', '=', 'user_permissions.granted_by')
            ->where('user_permissions.user_id', $user->id)->where('user_permissions.company_id', $companyId)
            ->orderBy('permissions.name')
            ->get(['permissions.name', 'g.name as granted_by', 'user_permissions.created_at'])
            ->map(fn ($r) => [
                'name' => $r->name,
                'label' => $labels[$r->name]['label'] ?? $r->name,
                'module' => $labels[$r->name]['module'] ?? '',
                'granted_by' => $r->granted_by,
                'granted_at' => $r->created_at,
            ])->values()->all();
    }

    /** Permission groups a user may ask for: everything except "Full access". */
    private function requestable(): array
    {
        $groups = PermissionRegistry::groups();
        unset($groups['System']);

        return $groups;
    }

    private function labels($names)
    {
        $labels = collect(PermissionRegistry::flat())->keyBy('name');

        return collect($names)->map(fn ($n) => $labels[$n]['label'] ?? $n)->values();
    }

    private function present(AccessRequest $r, ?Request $request = null): array
    {
        $labels = collect(PermissionRegistry::flat())->keyBy('name');
        $approver = $request?->user();
        $mine = $approver && ! $approver->is_super_admin ? $approver->permissionNamesForCompany($r->company_id) : null;

        return [
            'id' => $r->id,
            'company_id' => $r->company_id,
            'company' => $r->relationLoaded('company') ? $r->company?->name : null,
            'user' => $r->relationLoaded('user') ? ['id' => $r->user?->id, 'name' => $r->user?->name, 'email' => $r->user?->email] : null,
            'status' => $r->status,
            'reason' => $r->reason,
            'permissions' => collect($r->permissions)->map(fn ($n) => [
                'name' => $n,
                'label' => $labels[$n]['label'] ?? $n,
                'module' => $labels[$n]['module'] ?? '',
                'granted' => in_array($n, $r->granted ?? [], true),
                // Can the person looking at it give this one?
                'can_grant' => $mine === null ? true : ($mine->contains('*') || $mine->contains($n)),
            ])->values(),
            'decided_by' => $r->relationLoaded('decider') ? $r->decider?->name : null,
            'decided_at' => optional($r->decided_at)->toIso8601String(),
            'decision_note' => $r->decision_note,
            'created_at' => optional($r->created_at)->toIso8601String(),
        ];
    }

    private function company(Request $request)
    {
        return $request->attributes->get('company');
    }

    private function isApprover(Request $request): bool
    {
        $user = $request->user();

        return $user->is_super_admin || $user->hasPermission('users.update', $this->company($request)->id);
    }

    private function assertApprover(Request $request): void
    {
        abort_unless($this->isApprover($request), 403, 'Only admins who can edit users can answer access requests.');
    }

    private function assertCanDecide(Request $request, AccessRequest $r): void
    {
        $this->assertApprover($request);
        $user = $request->user();
        abort_unless($user->is_super_admin || (string) $r->company_id === (string) $this->company($request)->id, 404);
        abort_if((int) $r->user_id === (int) $user->id, 403, 'You cannot approve your own request.');
        abort_unless($r->status === 'pending', 422, 'This request has already been answered.');
    }
}
