<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Services\CustomerService;
use App\Services\ProductService;
use App\Services\SupplierService;
use App\Support\Api\ApiResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Import products, customers or suppliers from a spreadsheet (CSV rows sent
 * as JSON by the screen).
 *
 * Every row is checked with exactly the same rules as the "Add" form and
 * created through the same service, so an imported record is identical to one
 * typed in by hand. Send dry_run=true first to see which rows are fine and
 * which are not, without saving anything.
 */
class ImportController extends Controller
{
    use ApiResponse;

    private const MAX_ROWS = 2000;

    private const ENTITIES = [
        'products'  => ['permission' => 'products.create',  'request' => StoreProductRequest::class,  'label' => 'product'],
        'customers' => ['permission' => 'customers.create', 'request' => StoreCustomerRequest::class, 'label' => 'customer'],
        'suppliers' => ['permission' => 'suppliers.create', 'request' => StoreSupplierRequest::class, 'label' => 'supplier'],
    ];

    public function import(Request $request, string $entity): JsonResponse
    {
        abort_unless(isset(self::ENTITIES[$entity]), 404);
        $conf = self::ENTITIES[$entity];
        $company = $request->attributes->get('company');
        abort_unless($request->user()->hasPermission($conf['permission'], $company->id), 403);

        $data = $request->validate([
            'rows'    => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*'  => ['array'],
            'dry_run' => ['boolean'],
        ], ['rows.max' => 'At most '.self::MAX_ROWS.' rows can be imported at a time. Split the file.']);
        $dryRun = $request->boolean('dry_run');

        // A check run does the real import inside a transaction and then rolls
        // it back, so it reports exactly what a real import would do.
        if ($dryRun) {
            DB::beginTransaction();
        }

        $results = [];
        $seen = [];
        $created = 0;
        $newCategories = [];
        $newUnits = [];

        foreach (array_values($data['rows']) as $i => $raw) {
            $line = $i + 2; // row 1 of the sheet is the header
            $row = $this->clean($raw);
            $name = (string) ($row['name'] ?? '');
            $messages = [];

            // Same name twice in one file.
            $key = mb_strtolower(trim($name));
            if ($key !== '' && isset($seen[$key])) {
                $messages[] = "Same name as row {$seen[$key]} in this file.";
            } elseif ($key !== '') {
                $seen[$key] = $line;
            }

            try {
                if ($entity === 'products') {
                    $row = $this->productRow($company->id, $row, $newCategories, $newUnits);
                } else {
                    $row += ['status' => 'active'];
                    if ($entity === 'customers') {
                        $row += ['type' => 'retail'];
                    }
                }

                if ($messages === []) {
                    $validated = $this->validateRow($request, $conf['request'], $row);
                    DB::transaction(fn () => $this->create($entity, $company->id, $validated, $request->user()->id));
                    $created++;
                }
            } catch (ValidationException $e) {
                $messages = array_merge($messages, collect($e->errors())->flatten()->unique()->values()->all());
            } catch (\Throwable $e) {
                report($e);
                $messages[] = 'Could not save this row: '.$e->getMessage();
            }

            $results[] = ['row' => $line, 'name' => $name, 'ok' => $messages === [], 'messages' => $messages];
        }

        if ($dryRun) {
            DB::rollBack();
            $created = 0;
        }

        $failed = collect($results)->where('ok', false)->values();

        return $this->ok([
            'total'          => count($results),
            'valid'          => count($results) - $failed->count(),
            'created'        => $created,
            'dry_run'        => $dryRun,
            'new_categories' => array_values(array_unique($newCategories)),
            'new_units'      => array_values(array_unique($newUnits)),
            'errors'         => $failed->all(),
        ], $dryRun
            ? count($results).' rows checked: '.($failed->count() ? $failed->count().' need fixing.' : 'all ready to import.')
            : "{$created} {$conf['label']}(s) imported".($failed->count() ? ", {$failed->count()} skipped." : '.'));
    }

    /** Trim text, turn blanks into null, accept yes/no for true/false. */
    private function clean(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            $k = Str::snake(trim((string) $k));
            if ($k === '') {
                continue;
            }
            if (is_string($v)) {
                $v = trim($v);
                if ($v === '') {
                    $v = null;
                } elseif (in_array(mb_strtolower($v), ['yes', 'true'], true)) {
                    $v = true;
                } elseif (in_array(mb_strtolower($v), ['no', 'false'], true)) {
                    $v = false;
                }
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /** Category and unit are given by name in the sheet; missing ones are created (once). */
    private function productRow(int|string $companyId, array $row, array &$newCategories, array &$newUnits): array
    {
        $row += ['status' => 'active'];

        $category = $row['category'] ?? null;
        $subcategory = isset($row['subcategory']) && trim((string) $row['subcategory']) !== '' ? trim((string) $row['subcategory']) : null;
        unset($row['category'], $row['subcategory']);
        if ($category !== null && empty($row['category_id'])) {
            $found = ProductCategory::query()->where('company_id', $companyId)->where('status', 'active')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($category)])->value('id');
            if (! $found) {
                $newCategories[] = $category;
                $found = ProductCategory::create([
                    'company_id' => $companyId, 'name' => $category, 'status' => 'active',
                    'code' => $this->uniqueCode(ProductCategory::class, $companyId, $category),
                ])->id;
            }
            $row['category_id'] = $found;

            // Optional subcategory under that category: the product is saved under it.
            if ($subcategory !== null) {
                $sub = ProductCategory::query()->where('company_id', $companyId)->where('parent_id', $found)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($subcategory)])->value('id');
                if (! $sub) {
                    $newCategories[] = "{$category} › {$subcategory}";
                    $sub = ProductCategory::create([
                        'company_id' => $companyId, 'parent_id' => $found, 'name' => $subcategory, 'status' => 'active',
                        'code' => $this->uniqueCode(ProductCategory::class, $companyId, $subcategory),
                    ])->id;
                }
                $row['category_id'] = $sub;
            }
        }

        $unit = $row['unit'] ?? null;
        unset($row['unit']);
        if ($unit !== null && empty($row['unit_id'])) {
            $found = ProductUnit::query()->where('company_id', $companyId)
                ->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [mb_strtolower($unit)])->orWhereRaw('LOWER(short_name) = ?', [mb_strtolower($unit)]))
                ->value('id');
            if (! $found) {
                $newUnits[] = $unit;
                $found = ProductUnit::create([
                    'company_id' => $companyId, 'name' => $unit, 'short_name' => $unit, 'status' => 'active',
                    'code' => $this->uniqueCode(ProductUnit::class, $companyId, $unit),
                ])->id;
            }
            $row['unit_id'] = $found;
        }

        return $row;
    }

    private function uniqueCode(string $model, int|string $companyId, string $name): string
    {
        $base = Str::upper(Str::limit(Str::slug($name, ''), 10, '')) ?: 'ITEM';
        $code = $base;
        for ($n = 2; $model::query()->where('company_id', $companyId)->where('code', $code)->exists(); $n++) {
            $code = $base.$n;
        }

        return $code;
    }

    /** Run the "Add" form's own validation on one row. */
    private function validateRow(Request $request, string $class, array $row): array
    {
        /** @var FormRequest $form */
        $form = $class::createFrom($request);
        $form->replace($row);
        $form->setContainer(app())->setRedirector(app(Redirector::class));
        $form->validateResolved();

        return $form->validated();
    }

    private function create(string $entity, int|string $companyId, array $data, ?int $userId): void
    {
        match ($entity) {
            'products'  => app(ProductService::class)->create($companyId, $data, $userId),
            'customers' => app(CustomerService::class)->create($companyId, $data),
            'suppliers' => app(SupplierService::class)->create($companyId, $data),
        };
    }
}
