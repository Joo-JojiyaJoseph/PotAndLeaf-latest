<?php

namespace App\Actions\Suppliers;

use App\Models\Supplier;
use App\Repositories\Contracts\SupplierRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * One write use-case = one action. Actions own the transaction boundary
 * and side effects (events, notifications, activity log). Reuse them from
 * controllers, jobs, console commands, or tests — anywhere.
 */
class CreateSupplier
{
    public function __construct(
        private readonly SupplierRepositoryInterface $suppliers,
    ) {}

    /** @param array<string,mixed> $data */
    public function handle(int|string $companyId, array $data): Supplier
    {
        foreach (['credit_days', 'credit_limit', 'opening_balance', 'outstanding'] as $k) {
            if (! isset($data[$k]) || $data[$k] === '' || $data[$k] === null) $data[$k] = 0;
        }
        if (blank($data['country'] ?? null)) $data['country'] = 'India';
        if (blank($data['status'] ?? null)) $data['status'] = 'active';

        if (empty($data['supplier_code'])) {
            $data['supplier_code'] = self::nextSupplierCode($companyId);
        }

        return DB::transaction(function () use ($companyId, $data) {
            $supplier = $this->suppliers->create([
                ...$data,
                'company_id' => $companyId,
                'outstanding' => $data['opening_balance'] ?? 0,
            ]);

            return $supplier;
        });
    }

    /** Next sequential code per company — avoids collisions from count-based gaps. */
    public static function nextSupplierCode(int|string $companyId): string
    {
        $max = Supplier::withTrashed()
            ->forCompany($companyId)
            ->pluck('supplier_code')
            ->map(function ($code) {
                if (preg_match('/^SUP-(\d+)$/i', (string) $code, $m)) {
                    return (int) $m[1];
                }

                return 0;
            })
            ->max() ?? 0;

        return 'SUP-'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }
}
