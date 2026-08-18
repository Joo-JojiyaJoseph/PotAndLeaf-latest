<?php

namespace App\Support\Lookup;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable data layer for simple lookup masters (Category, Brand, Unit …).
 *
 * Rich modules (Supplier, Product) keep an explicit Repository/Service/Action
 * stack because they carry real business logic. Trivial masters would just be
 * nine copy-pasted files each, so they share this engine instead. Match the
 * ceremony to the complexity.
 */
class LookupRepository
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int,string>     $sortable
     * @param  array<int,string>     $searchable
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly array $sortable = ['name', 'code', 'status', 'created_at'],
        private readonly array $searchable = ['name', 'code'],
    ) {}

    /** @param array<string,mixed> $filters */
    public function paginateForCompany(int|string $companyId, array $filters): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? '', $this->sortable, true)
            ? $filters['sort'] : 'name';
        $dir = strtolower($filters['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        $term = $filters['search'] ?? null;

        return $this->query($companyId)
            ->when(filled($term), function ($q) use ($term) {
                $q->where(function ($inner) use ($term) {
                    foreach ($this->searchable as $col) {
                        $inner->orWhere($col, 'like', "%{$term}%");
                    }
                });
            })
            ->when(
                filled($filters['status'] ?? null),
                fn ($q) => $q->where('status', $filters['status'])
            )
            ->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForCompany(int|string $companyId, string $id): ?Model
    {
        return $this->query($companyId)->whereKey($id)->first();
    }

    /** @param array<string,mixed> $data */
    public function create(int|string $companyId, array $data): Model
    {
        return ($this->modelClass)::create([...$data, 'company_id' => $companyId]);
    }

    /** @param array<string,mixed> $data */
    public function update(Model $model, array $data): Model
    {
        $model->update($data);

        return $model->refresh();
    }

    public function delete(Model $model): void
    {
        $model->delete();
    }

    public function restore(int|string $companyId, string $id): ?Model
    {
        $model = ($this->modelClass)::onlyTrashed()
            ->where('company_id', $companyId)->whereKey($id)->first();
        $model?->restore();

        return $model;
    }

    public function codeExists(int|string $companyId, string $code, ?string $ignoreId = null): bool
    {
        return $this->query($companyId)
            ->where('code', $code)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    private function query(int|string $companyId)
    {
        return ($this->modelClass)::query()->where('company_id', $companyId);
    }
}
