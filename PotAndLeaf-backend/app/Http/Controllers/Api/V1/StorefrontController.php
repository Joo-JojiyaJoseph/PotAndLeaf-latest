<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\CreateSale;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Support\Api\ApiResponse;
use App\Support\Media\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** External company storefront API — scoped by API key, wraps existing ERP services. */
class StorefrontController extends Controller
{
    use ApiResponse;

    public function company(Request $request): JsonResponse
    {
        $c = $request->attributes->get('company');

        return $this->ok([
            'id'         => $c->id,
            'name'       => $c->name,
            'legal_name' => $c->legal_name,
            'phone'      => $c->phone,
            'email'      => $c->email,
            'address'    => $c->address,
            'gst_number' => $c->gst_number,
            'logo'       => MediaUrl::resolve($c->logo),
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company')->id;
        $page = Product::forCompany($companyId)
            ->where('status', 'active')
            ->with(['category:id,name', 'unit:id,short_name,name'])
            ->when(filled($request->query('search')), fn ($q) => $q->search($request->query('search')))
            ->when(filled($request->query('category_id')), fn ($q) => $q->where('category_id', $request->query('category_id')))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        $page->getCollection()->transform(fn (Product $p) => $this->productPayload($p));

        return $this->ok($page);
    }

    public function product(Request $request, string $id): JsonResponse
    {
        $product = Product::forCompany($request->attributes->get('company')->id)
            ->where('status', 'active')
            ->with(['category:id,name', 'unit:id,short_name,name'])
            ->findOrFail($id);

        return $this->ok($this->productPayload($product));
    }

    public function categories(Request $request): JsonResponse
    {
        $rows = ProductCategory::query()
            ->where('company_id', $request->attributes->get('company')->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        return $this->ok($rows);
    }

    public function branches(Request $request): JsonResponse
    {
        $rows = Location::forCompany($request->attributes->get('company')->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'type', 'is_default']);

        return $this->ok($rows);
    }

    public function stock(Request $request): JsonResponse
    {
        $companyId = $request->attributes->get('company')->id;
        $q = Product::forCompany($companyId)->where('status', 'active')
            ->when(filled($request->query('product_id')), fn ($qq) => $qq->where('id', $request->query('product_id')));

        $rows = $q->orderBy('name')->get(['id', 'sku', 'name', 'current_stock', 'reorder_level'])
            ->map(fn (Product $p) => [
                'product_id'     => $p->id,
                'sku'            => $p->sku,
                'name'           => $p->name,
                'available'      => (float) $p->current_stock > 0,
                'current_stock'  => (float) $p->current_stock,
            ]);

        return $this->ok($rows);
    }

    public function storeOrder(Request $request, CreateCustomer $createCustomer, CreateSale $createSale, ConfirmSale $confirmSale): JsonResponse
    {
        $company = $request->attributes->get('company');
        $data = $request->validate([
            'customer'              => ['required', 'array'],
            'customer.name'         => ['required', 'string', 'max:160'],
            'customer.phone'        => ['nullable', 'string', 'max:20'],
            'customer.email'        => ['nullable', 'email'],
            'branch_id'             => ['nullable', 'uuid'],
            'payment_mode'          => ['nullable', 'in:cash,card,upi,bank,credit'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.product_id'    => ['required', 'uuid'],
            'items.*.qty'           => ['required', 'numeric', 'min:0.001'],
            'items.*.rate'          => ['nullable', 'numeric', 'min:0'],
            'confirm'               => ['sometimes', 'boolean'],
        ]);

        $customer = $this->resolveCustomer($company->id, $data['customer'], $createCustomer);
        $locationId = $data['branch_id']
            ?? Location::forCompany($company->id)->where('is_default', true)->value('id');
        if (! empty($data['branch_id'])) {
            Location::forCompany($company->id)->where('is_active', true)->findOrFail($data['branch_id']);
        }

        $items = collect($data['items'])->map(function (array $line) use ($company) {
            $product = Product::forCompany($company->id)->where('status', 'active')->findOrFail($line['product_id']);

            return [
                'product_id' => $product->id,
                'qty'        => $line['qty'],
                'rate'       => $line['rate'] ?? $product->retail_price,
                'discount'   => 0,
                'gst_rate'   => (float) $product->gst_rate,
            ];
        })->all();

        $sale = $createSale->handle($company->id, [
            'customer_id'   => $customer->id,
            'location_id'   => $locationId,
            'sale_date'     => now()->toDateString(),
            'payment_mode'  => $data['payment_mode'] ?? 'cash',
            'bill_kind'     => 'tax_invoice',
            'is_interstate' => false,
            'items'         => $items,
        ]);

        if ($request->boolean('confirm', true)) {
            $sale = $confirmSale->handle($sale);
        }

        return $this->created($this->orderPayload($sale->fresh(['items'])), 'Order received.');
    }

    public function order(Request $request, string $id): JsonResponse
    {
        $sale = Sale::forCompany($request->attributes->get('company')->id)
            ->with('items')
            ->findOrFail($id);

        return $this->ok($this->orderPayload($sale));
    }

    public function openapi(): JsonResponse
    {
        return response()->json([
            'openapi' => '3.0.3',
            'info'    => [
                'title'       => 'Pot & Leaf Company Storefront API',
                'version'     => '1.0.0',
                'description' => 'Company-scoped catalogue and order API. Authenticate with X-Api-Key or Bearer plk_… . Company is taken from the key, never from the request body.',
            ],
            'servers' => [['url' => url('/api/v1')]],
            'security' => [['apiKey' => []]],
            'components' => [
                'securitySchemes' => [
                    'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
                ],
            ],
            'paths' => [
                '/company'     => ['get' => ['summary' => 'Public company profile']],
                '/products'    => ['get' => ['summary' => 'List active products', 'parameters' => [
                    ['name' => 'search', 'in' => 'query'],
                    ['name' => 'category_id', 'in' => 'query'],
                    ['name' => 'page', 'in' => 'query'],
                    ['name' => 'per_page', 'in' => 'query'],
                ]]],
                '/products/{id}' => ['get' => ['summary' => 'Product detail']],
                '/categories'  => ['get' => ['summary' => 'Product categories']],
                '/branches'    => ['get' => ['summary' => 'Company locations/branches']],
                '/stock'       => ['get' => ['summary' => 'Stock availability']],
                '/orders'      => ['post' => ['summary' => 'Create an order (uses existing sales pipeline)']],
                '/orders/{id}' => ['get' => ['summary' => 'Fetch an order']],
            ],
        ]);
    }

    private function productPayload(Product $p): array
    {
        return [
            'id'           => $p->id,
            'sku'          => $p->sku,
            'name'         => $p->name,
            'description'  => $p->description,
            'category_id'  => $p->category_id,
            'category'     => $p->category?->name,
            'unit'         => $p->unit?->short_name ?? $p->unit?->name,
            'price'        => (float) $p->retail_price,
            'mrp'          => (float) $p->mrp,
            'gst_rate'     => (float) $p->gst_rate,
            'in_stock'     => (float) $p->current_stock > 0,
            'images'       => MediaUrl::resolveMany($p->images ?? []),
        ];
    }

    private function orderPayload(Sale $sale): array
    {
        return [
            'id'           => $sale->id,
            'sale_no'      => $sale->sale_no,
            'status'       => $sale->status,
            'sale_date'    => optional($sale->sale_date)->toDateString(),
            'customer_name'=> $sale->customer_name,
            'subtotal'     => (float) $sale->subtotal,
            'tax_total'    => (float) $sale->tax_total,
            'grand_total'  => (float) $sale->grand_total,
            'items'        => $sale->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'name'       => $i->product_name,
                'qty'        => (float) $i->qty,
                'rate'       => (float) $i->rate,
                'amount'     => (float) $i->amount,
            ])->all(),
        ];
    }

    private function resolveCustomer(int|string $companyId, array $payload, CreateCustomer $createCustomer): Customer
    {
        $phone = $payload['phone'] ?? null;
        if ($phone) {
            $existing = Customer::forCompany($companyId)->where('phone', $phone)->first();
            if ($existing) {
                return $existing;
            }
        }

        return $createCustomer->handle($companyId, [
            'name'  => $payload['name'],
            'phone' => $phone,
            'email' => $payload['email'] ?? null,
            'type'  => 'retail',
        ]);
    }
}
