<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LandscapeProject;
use App\Models\Location;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesFilterCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Landscaping jobs of the company's landscape division(s). */
class LandscapeProjectController extends Controller
{
    use ApiResponse, ResolvesFilterCompany;

    public function formData(Request $request): JsonResponse
    {
        $this->allow($request, 'landscape.view');
        $companyId = $this->company($request)->id;

        return $this->ok([
            'divisions' => Location::forCompany($companyId)->where('is_landscape', true)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name]),
            'services'  => collect(LandscapeProject::SERVICES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'statuses'  => LandscapeProject::STATUSES,
            'customers' => Customer::forCompany($companyId)->orderBy('name')->limit(500)->get(['id', 'name', 'phone'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone]),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->allow($request, 'landscape.view');
        $companyId = $this->company($request)->id;
        $search = trim((string) $request->query('search', ''));

        $page = LandscapeProject::query()->forCompany($companyId)->with('location:id,name')
            ->when(filled($request->query('status')), fn ($q) => $q->where('status', $request->query('status')))
            ->when(filled($request->query('location_id')), fn ($q) => $q->where('location_id', $request->query('location_id')))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', "%{$search}%")->orWhere('project_no', 'like', "%{$search}%")->orWhere('customer_name', 'like', "%{$search}%")->orWhere('site_address', 'like', "%{$search}%")))
            ->orderByDesc('created_at')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 20))));

        return response()->json([
            'data' => collect($page->items())->map(fn ($p) => $this->row($p))->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
            'summary' => [
                'open'      => LandscapeProject::forCompany($companyId)->whereIn('status', ['enquiry', 'quoted', 'approved', 'in_progress'])->count(),
                'quoted'    => (float) LandscapeProject::forCompany($companyId)->whereIn('status', ['quoted', 'approved', 'in_progress'])->sum('quoted_amount'),
                'completed' => LandscapeProject::forCompany($companyId)->where('status', 'completed')->count(),
            ],
            'message' => null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        $companyId = $this->company($request)->id;
        $data = $this->validated($request, $companyId);
        $project = LandscapeProject::create($data + [
            'company_id' => $companyId,
            'project_no' => $this->nextNo($companyId),
            'created_by' => $request->user()->id,
        ]);

        return $this->created($this->row($project->load('location:id,name')), 'Project saved.');
    }

    public function update(Request $request, LandscapeProject $landscapeProject): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        abort_unless((string) $landscapeProject->company_id === (string) $this->company($request)->id, 404);
        $landscapeProject->update($this->validated($request, $landscapeProject->company_id));

        return $this->ok($this->row($landscapeProject->fresh('location:id,name')), 'Project updated.');
    }

    public function destroy(Request $request, LandscapeProject $landscapeProject): JsonResponse
    {
        $this->allow($request, 'landscape.manage');
        abort_unless((string) $landscapeProject->company_id === (string) $this->company($request)->id, 404);
        if (in_array($landscapeProject->status, ['in_progress', 'completed'], true)) {
            throw ValidationException::withMessages(['status' => 'A project that has started or finished cannot be deleted. Mark it cancelled instead.']);
        }
        $landscapeProject->delete();

        return $this->message('Project deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int|string $companyId): array
    {
        $data = $request->validate([
            'title'            => ['required', 'string', 'max:160'],
            'location_id'      => ['required', 'uuid', Rule::exists('locations', 'id')->where('company_id', $companyId)->where('is_landscape', true)],
            'customer_id'      => ['nullable', 'uuid', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'customer_name'    => ['nullable', 'string', 'max:160'],
            'customer_phone'   => ['nullable', 'string', 'max:30'],
            'service_type'     => ['required', Rule::in(array_keys(LandscapeProject::SERVICES))],
            'site_address'     => ['nullable', 'string', 'max:255'],
            'site_area_sqft'   => ['nullable', 'numeric', 'min:0'],
            'visit_date'       => ['nullable', 'date'],
            'start_date'       => ['nullable', 'date'],
            'end_date'         => ['nullable', 'date', 'after_or_equal:start_date'],
            'quoted_amount'    => ['nullable', 'numeric', 'min:0'],
            'advance_received' => ['nullable', 'numeric', 'min:0'],
            'status'           => ['required', Rule::in(LandscapeProject::STATUSES)],
            'notes'            => ['nullable', 'string', 'max:3000'],
        ], [
            'location_id.exists' => 'Choose a branch that is marked as a landscape division.',
        ]);

        if (! filled($data['customer_name'] ?? null) && filled($data['customer_id'] ?? null)) {
            $c = Customer::forCompany($companyId)->find($data['customer_id']);
            $data['customer_name'] = $c?->name;
            $data['customer_phone'] = $data['customer_phone'] ?? $c?->phone;
        }
        $data['quoted_amount'] = (float) ($data['quoted_amount'] ?? 0);
        $data['advance_received'] = (float) ($data['advance_received'] ?? 0);

        return $data;
    }

    private function nextNo(int|string $companyId): string
    {
        $n = LandscapeProject::forCompany($companyId)->count() + 1;
        do {
            $no = sprintf('LSP-%04d', $n++);
        } while (LandscapeProject::forCompany($companyId)->where('project_no', $no)->exists());

        return $no;
    }

    /** @return array<string, mixed> */
    private function row(LandscapeProject $p): array
    {
        return [
            'id' => $p->id, 'project_no' => $p->project_no, 'title' => $p->title,
            'location_id' => $p->location_id, 'location' => $p->location?->name,
            'customer_id' => $p->customer_id, 'customer_name' => $p->customer_name, 'customer_phone' => $p->customer_phone,
            'service_type' => $p->service_type, 'service' => LandscapeProject::SERVICES[$p->service_type] ?? $p->service_type,
            'site_address' => $p->site_address, 'site_area_sqft' => $p->site_area_sqft !== null ? (float) $p->site_area_sqft : null,
            'visit_date' => optional($p->visit_date)->toDateString(), 'start_date' => optional($p->start_date)->toDateString(), 'end_date' => optional($p->end_date)->toDateString(),
            'quoted_amount' => (float) $p->quoted_amount, 'advance_received' => (float) $p->advance_received,
            'balance' => round((float) $p->quoted_amount - (float) $p->advance_received, 2),
            'status' => $p->status, 'notes' => $p->notes,
        ];
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission, $this->company($request)->id), 403);
    }
}
