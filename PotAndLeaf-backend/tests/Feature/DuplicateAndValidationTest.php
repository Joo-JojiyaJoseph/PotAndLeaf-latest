<?php

/**
 * Duplicates, bad formats, edits and saved-value checks for every Add / Edit
 * form. Each check that fails is listed by name, so one run shows them all.
 * (Same-name-different-capitals is left to MySQL, whose collation already
 * treats them as equal; the test database is SQLite.)
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesErpFixtures;

uses(RefreshDatabase::class, CreatesErpFixtures::class);

function probeCompare(array $sent, array $got, array $skip = []): array
{
    $bad = [];
    foreach ($sent as $k => $v) {
        if (in_array($k, $skip, true) || is_array($v)) continue;
        if (! array_key_exists($k, $got)) { $bad[] = "$k: not returned"; continue; }
        $g = $got[$k];
        $same = (is_numeric($v) && is_numeric($g)) ? abs((float) $v - (float) $g) < 0.001
            : (is_bool($v) ? (bool) $g === $v : trim((string) $g) === trim((string) $v));
        if (! $same) $bad[] = "$k: sent ".json_encode($v).' got '.json_encode($g);
    }
    return $bad;
}

it('refuses duplicates and bad values, and saves exactly what was entered', function () {
    $perms = \App\Support\Rbac\PermissionRegistry::flat();
    $names = collect($perms)->flatten()->filter(fn ($p) => is_string($p) && str_contains($p, '.'))->values()->all();
    $this->createCompanyWithUser($names ?: ['products.create']);
    $this->user->forceFill(['is_super_admin' => true])->save();
    app('auth')->forgetGuards();
    $h = $this->apiHeaders();
    $issues = [];
    $expect422 = function (string $label, $res) use (&$issues) {
        if ($res->status() !== 422) $issues[] = "$label: expected refusal, got {$res->status()}";
    };
    $expectOk = function (string $label, $res) use (&$issues) {
        if (! in_array($res->status(), [200, 201], true)) $issues[] = "$label: expected OK, got {$res->status()} ".json_encode($res->json('errors') ?? $res->json('message'));
    };

    // ---------- Companies ----------
    $c = ['name' => 'Probe Nursery', 'legal_name' => 'Probe Nursery Pvt Ltd', 'gst_number' => '32ABCDE1234F1Z5', 'state' => 'Kerala', 'state_code' => '32',
        'address' => 'MG Road', 'phone' => '9876543210', 'email' => 'probe@x.com', 'description' => 'd', 'is_active' => true];
    $r = $this->postJson('/api/companies', $c, $h); $expectOk('company create', $r);
    $cid = $r->json('data.id');
    $issues = array_merge($issues, array_map(fn ($x) => "company create round-trip $x", probeCompare($c, $r->json('data') ?? [])));
    $expect422('company duplicate (identical)', $this->postJson('/api/companies', $c, $h));
    $expect422('company duplicate GST', $this->postJson('/api/companies', ['name' => 'Other', 'gst_number' => '32ABCDE1234F1Z5'], $h));
    $expect422('company bad GST 123456789', $this->postJson('/api/companies', ['name' => 'G1', 'gst_number' => '123456789'], $h));
    $expect422('company GST state ≠ state code', $this->postJson('/api/companies', ['name' => 'G2', 'gst_number' => '29ABCDE1234F1Z5', 'state_code' => '32'], $h));
    $expect422('company bad state code', $this->postJson('/api/companies', ['name' => 'G3', 'state_code' => 'KL'], $h));
    $expect422('company bad email', $this->postJson('/api/companies', ['name' => 'G4', 'email' => 'abc'], $h));
    $r2 = $this->postJson('/api/companies', ['name' => 'Second Nursery'], $h); $expectOk('company 2 create', $r2);
    $expect422('company edit to existing name', $this->putJson('/api/companies/'.$r2->json('data.id'), ['name' => 'Probe Nursery'], $h));
    $expect422('company edit to existing GST', $this->putJson('/api/companies/'.$r2->json('data.id'), ['name' => 'Second Nursery', 'gst_number' => '32ABCDE1234F1Z5'], $h));
    $r = $this->putJson("/api/companies/$cid", $c, $h); $expectOk('company save unchanged', $r);
    $c2 = array_merge($c, ['legal_name' => 'New Legal', 'address' => 'New addr', 'phone' => '9123456780', 'email' => 'n@x.com', 'description' => 'nd']);
    $r = $this->putJson("/api/companies/$cid", $c2, $h); $expectOk('company edit', $r);
    $issues = array_merge($issues, array_map(fn ($x) => "company edit round-trip $x", probeCompare($c2, $this->getJson("/api/companies/$cid", $h)->json('data') ?? [])));

    // ---------- Masters ----------
    foreach (['categories', 'units'] as $t) {
        $m = ['name' => 'Probe '.$t, 'code' => strtoupper(substr($t, 0, 3)).'P', 'status' => 'active'] + ($t === 'units' ? ['short_name' => 'pc'] : []);
        $r = $this->postJson("/api/masters/$t", $m, $h); $expectOk("$t create", $r);
        $mid = $r->json('data.id');
        $issues = array_merge($issues, array_map(fn ($x) => "$t create round-trip $x", probeCompare($m, $r->json('data') ?? [])));
        $expect422("$t duplicate", $this->postJson("/api/masters/$t", $m, $h));
        $r2 = $this->postJson("/api/masters/$t", ['name' => 'Other '.$t, 'code' => 'OTH', 'status' => 'active'] + ($t === 'units' ? ['short_name' => 'o'] : []), $h);
        $expect422("$t edit to existing name", $this->putJson("/api/masters/$t/".$r2->json('data.id'), ['name' => $m['name'], 'code' => 'OTH', 'status' => 'active'] + ($t === 'units' ? ['short_name' => 'o'] : []), $h));
        $expectOk("$t save unchanged", $this->putJson("/api/masters/$t/$mid", $m, $h));
    }
    $catId = \App\Models\ProductCategory::where('name', 'Probe categories')->value('id');
    $unitId = \App\Models\ProductUnit::where('name', 'Probe units')->value('id');

    // ---------- Customers ----------
    $cu = ['name' => 'Anu Traders', 'type' => 'wholesale', 'phone' => '9876543210', 'whatsapp' => '9876543211', 'email' => 'anu@x.com', 'gst_number' => '32AAAAA1111A1Z5',
        'address_line1' => 'Line 1', 'city' => 'Kochi', 'state' => 'Kerala', 'pincode' => '682001', 'credit_days' => 15, 'credit_limit' => 5000, 'opening_balance' => 250, 'notes' => 'n', 'status' => 'active'];
    $r = $this->postJson('/api/customers', $cu, $h); $expectOk('customer create', $r); $cuid = $r->json('data.id');
    $issues = array_merge($issues, array_map(fn ($x) => "customer create round-trip $x", probeCompare($cu, $this->getJson("/api/customers/$cuid", $h)->json('data') ?? [])));
    $expect422('customer duplicate', $this->postJson('/api/customers', $cu, $h));
    $expect422('customer duplicate GST', $this->postJson('/api/customers', ['name' => 'Xyz', 'type' => 'retail', 'status' => 'active', 'gst_number' => '32AAAAA1111A1Z5'], $h));
    $expect422('customer bad GST', $this->postJson('/api/customers', ['name' => 'G', 'type' => 'retail', 'status' => 'active', 'gst_number' => '12345'], $h));
    $expect422('customer bad pincode', $this->postJson('/api/customers', ['name' => 'P', 'type' => 'retail', 'status' => 'active', 'pincode' => '12'], $h));
    $expect422('customer negative credit limit', $this->postJson('/api/customers', ['name' => 'N', 'type' => 'retail', 'status' => 'active', 'credit_limit' => -1], $h));
    $r2 = $this->postJson('/api/customers', ['name' => 'Other Cust', 'type' => 'retail', 'status' => 'active'], $h);
    $expect422('customer edit to existing name', $this->putJson('/api/customers/'.$r2->json('data.id'), ['name' => 'Anu Traders', 'type' => 'retail', 'status' => 'active'], $h));
    $expectOk('customer save unchanged', $this->putJson("/api/customers/$cuid", $cu, $h));
    $cu2 = array_merge($cu, ['type' => 'dealer', 'phone' => '9000000001', 'city' => 'Thrissur', 'credit_days' => 30, 'credit_limit' => 8000, 'notes' => 'changed']);
    $expectOk('customer edit', $this->putJson("/api/customers/$cuid", $cu2, $h));
    $issues = array_merge($issues, array_map(fn ($x) => "customer edit round-trip $x", probeCompare($cu2, $this->getJson("/api/customers/$cuid", $h)->json('data') ?? [], ['opening_balance'])));

    // ---------- Suppliers ----------
    $su = ['name' => 'Green Pots', 'address' => 'Ind Estate', 'phone' => '9123456780', 'email' => 'gp@x.com', 'gst_number' => '32BBBBB2222B1Z5', 'pan_number' => 'BBBBB2222B',
        'city' => 'Aluva', 'state' => 'Kerala', 'pincode' => '683101', 'credit_days' => 30, 'credit_limit' => 10000, 'opening_balance' => 500,
        'bank_name' => 'SBI', 'bank_account_no' => '123456789012', 'bank_ifsc' => 'SBIN0001234', 'notes' => 'n', 'status' => 'active'];
    $r = $this->postJson('/api/suppliers', $su, $h); $expectOk('supplier create', $r); $suid = $r->json('data.id');
    $issues = array_merge($issues, array_map(fn ($x) => "supplier create round-trip $x", probeCompare($su, $this->getJson("/api/suppliers/$suid", $h)->json('data') ?? [])));
    $expect422('supplier duplicate', $this->postJson('/api/suppliers', $su, $h));
    $expect422('supplier duplicate GST', $this->postJson('/api/suppliers', ['name' => 'Q', 'address' => 'a', 'status' => 'active', 'gst_number' => '32BBBBB2222B1Z5'], $h));
    $expect422('supplier bad GST', $this->postJson('/api/suppliers', ['name' => 'Q2', 'address' => 'a', 'status' => 'active', 'gst_number' => 'ABC'], $h));
    $expect422('supplier bad PAN', $this->postJson('/api/suppliers', ['name' => 'Q3', 'address' => 'a', 'status' => 'active', 'pan_number' => '1234'], $h));
    $expect422('supplier bad pincode', $this->postJson('/api/suppliers', ['name' => 'Q4', 'address' => 'a', 'status' => 'active', 'pincode' => 'abcdef'], $h));
    $r2 = $this->postJson('/api/suppliers', ['name' => 'Other Sup', 'address' => 'a', 'status' => 'active'], $h);
    $expect422('supplier edit to existing name', $this->putJson('/api/suppliers/'.$r2->json('data.id'), ['name' => 'Green Pots', 'address' => 'a', 'status' => 'active'], $h));
    $expectOk('supplier save unchanged', $this->putJson("/api/suppliers/$suid", $su, $h));
    $su2 = array_merge($su, ['phone' => '9000000002', 'city' => 'Kochi', 'credit_days' => 45, 'credit_limit' => 20000, 'bank_name' => 'HDFC', 'bank_ifsc' => 'HDFC0001234', 'notes' => 'changed', 'address' => 'New addr']);
    $expectOk('supplier edit', $this->putJson("/api/suppliers/$suid", $su2, $h));
    $issues = array_merge($issues, array_map(fn ($x) => "supplier edit round-trip $x", probeCompare($su2, $this->getJson("/api/suppliers/$suid", $h)->json('data') ?? [], ['opening_balance'])));

    // ---------- Products ----------
    $p = ['name' => 'Areca Palm', 'sku' => 'ARECA-1', 'category_id' => $catId, 'unit_id' => $unitId, 'hsn_code' => '0602', 'gst_rate' => 5, 'cost_price' => 200, 'mrp' => 450,
        'retail_price' => 380, 'wholesale_price' => 330, 'dealer_price' => 300, 'reorder_level' => 10, 'description' => 'd', 'status' => 'active'];
    $r = $this->postJson('/api/products', $p, $h); $expectOk('product create', $r); $pid = $r->json('data.id');
    $issues = array_merge($issues, array_map(fn ($x) => "product create round-trip $x", probeCompare($p, $this->getJson("/api/products/$pid", $h)->json('data') ?? [], ['category_id', 'unit_id'])));
    $expect422('product duplicate', $this->postJson('/api/products', $p, $h));
    $expect422('product duplicate SKU', $this->postJson('/api/products', array_merge($p, ['name' => 'Other', 'sku' => 'ARECA-1']), $h));
    $expect422('product retail above MRP', $this->postJson('/api/products', array_merge($p, ['name' => 'M1', 'sku' => null, 'retail_price' => 500]), $h));
    $expect422('product bad GST rate 7', $this->postJson('/api/products', array_merge($p, ['name' => 'M2', 'sku' => null, 'gst_rate' => 7]), $h));
    $expect422('product negative cost', $this->postJson('/api/products', array_merge($p, ['name' => 'M3', 'sku' => null, 'cost_price' => -5]), $h));
    $r2 = $this->postJson('/api/products', array_merge($p, ['name' => 'Snake Plant', 'sku' => 'SNK-1']), $h);
    $expect422('product edit to existing name', $this->putJson('/api/products/'.$r2->json('data.id'), array_merge($p, ['sku' => 'SNK-1']), $h));
    $expectOk('product save unchanged', $this->putJson("/api/products/$pid", $p, $h));
    $p2 = array_merge($p, ['hsn_code' => '0603', 'gst_rate' => 12, 'cost_price' => 210, 'mrp' => 500, 'retail_price' => 400, 'wholesale_price' => 350, 'dealer_price' => 320, 'reorder_level' => 15, 'description' => 'changed']);
    $expectOk('product edit', $this->putJson("/api/products/$pid", $p2, $h));
    $issues = array_merge($issues, array_map(fn ($x) => "product edit round-trip $x", probeCompare($p2, $this->getJson("/api/products/$pid", $h)->json('data') ?? [], ['category_id', 'unit_id'])));

    // ---------- Users ----------
    $role = \App\Models\Role::first();
    $u = ['name' => 'Ravi', 'email' => 'ravi@x.com', 'password' => 'password123', 'phone' => '9876500000', 'role_id' => $role?->id, 'is_active' => true];
    $r = $this->postJson('/api/users', $u, $h); $expectOk('user create', $r); $uid = $r->json('data.id');
    $expect422('user duplicate email', $this->postJson('/api/users', $u, $h));
    $expect422('user short password', $this->postJson('/api/users', array_merge($u, ['email' => 'z@x.com', 'password' => '123']), $h));
    $u2 = ['name' => 'Ravi K', 'email' => 'ravi@x.com', 'phone' => '9876500001', 'role_id' => $role?->id, 'is_active' => true];
    $expectOk('user edit', $this->putJson("/api/users/$uid", $u2, $h));
    $issues = array_merge($issues, array_map(fn ($x) => "user edit round-trip $x", probeCompare($u2, $this->getJson("/api/users/$uid", $h)->json('data') ?? [], ['role_id'])));

    // ---------- Roles ----------
    $ro = ['name' => 'Probe Role', 'slug' => 'probe-role', 'description' => 'x', 'permissions' => []];
    $expectOk('role create', $this->postJson('/api/roles', $ro, $h));
    $expect422('role duplicate', $this->postJson('/api/roles', $ro, $h));
    $expect422('role duplicate name, new slug', $this->postJson('/api/roles', array_merge($ro, ['slug' => 'probe-role-2']), $h));

    // ---------- Purchases: same supplier invoice twice ----------
    $pu = ['supplier_id' => $suid, 'purchase_date' => now()->toDateString(), 'invoice_no' => 'INV-77', 'items' => [['product_id' => $pid, 'qty' => 5, 'rate' => 200, 'discount' => 0, 'gst_rate' => 5]]];
    $expectOk('purchase create', $this->postJson('/api/purchases', $pu, $h));
    $expect422('purchase same supplier invoice no.', $this->postJson('/api/purchases', $pu, $h));
    $expect422('purchase zero qty', $this->postJson('/api/purchases', array_merge($pu, ['invoice_no' => 'INV-78', 'items' => [['product_id' => $pid, 'qty' => 0, 'rate' => 200]]]), $h));
    $expect422('purchase discount above line value', $this->postJson('/api/purchases', array_merge($pu, ['invoice_no' => 'INV-79', 'items' => [['product_id' => $pid, 'qty' => 1, 'rate' => 100, 'discount' => 150]]]), $h));
    $expect422('purchase future date', $this->postJson('/api/purchases', array_merge($pu, ['invoice_no' => 'INV-80', 'purchase_date' => now()->addDays(10)->toDateString()]), $h));

    // ---------- Sales ----------
    $sa = ['customer_id' => $cuid, 'sale_date' => now()->toDateString(), 'payment_mode' => 'cash', 'items' => [['product_id' => $pid, 'qty' => 1, 'rate' => 380, 'discount' => 0, 'gst_rate' => 5]]];
    $expect422('sale zero qty', $this->postJson('/api/sales', array_merge($sa, ['items' => [['product_id' => $pid, 'qty' => 0, 'rate' => 380]]]), $h));
    $expect422('sale negative rate', $this->postJson('/api/sales', array_merge($sa, ['items' => [['product_id' => $pid, 'qty' => 1, 'rate' => -1]]]), $h));
    $expect422('sale amount paid above total', $this->postJson('/api/sales', array_merge($sa, ['amount_paid' => 99999]), $h));
    $expect422('sale future date', $this->postJson('/api/sales', array_merge($sa, ['sale_date' => now()->addDays(10)->toDateString()]), $h));

    // Lower-case GST is accepted and stored in capitals; a saved GST rate like "18.00" still passes on edit.
    $r = $this->postJson('/api/companies', ['name' => 'Lower Gst Nursery', 'gst_number' => '32pqrst1234f1z5 ', 'state_code' => '32'], $h);
    $expectOk('company lower-case GST', $r);
    if ($r->json('data.gst_number') !== '32PQRST1234F1Z5') $issues[] = 'GST not stored in capitals: '.json_encode($r->json('data.gst_number'));
    $expectOk('product edit with gst "18.00"', $this->putJson("/api/products/$pid", array_merge($p2, ['gst_rate' => '18.00']), $h));
    $expectOk('purchase different invoice no.', $this->postJson('/api/purchases', array_merge($pu, ['invoice_no' => 'INV-81']), $h));
    $expectOk('sale paid exactly the total', $this->postJson('/api/sales', array_merge($sa, ['amount_paid' => 399]), $h));

    // Edit a draft purchase: header and lines must come back exactly as changed.
    $draft = $this->postJson('/api/purchases', array_merge($pu, ['invoice_no' => 'INV-90']), $h)->json('data.id');
    $edit = ['supplier_id' => $suid, 'purchase_date' => now()->subDay()->toDateString(), 'invoice_no' => 'INV-90A', 'landed_cost_total' => 120, 'notes' => 'edited',
        'items' => [['product_id' => $pid, 'qty' => 7, 'rate' => 210, 'discount' => 35, 'gst_rate' => 12]]];
    $expectOk('purchase edit', $this->putJson("/api/purchases/$draft", $edit, $h));
    $got = $this->getJson("/api/purchases/$draft", $h)->json('data') ?? [];
    $issues = array_merge($issues, array_map(fn ($x) => "purchase edit round-trip $x", probeCompare(['invoice_no' => 'INV-90A', 'landed_cost_total' => 120, 'notes' => 'edited'], $got)));
    $line = $got['items'][0] ?? [];
    $issues = array_merge($issues, array_map(fn ($x) => "purchase edit line $x", probeCompare(['qty' => 7, 'rate' => 210, 'discount' => 35, 'gst_rate' => 12], $line)));
    if (count($got['items'] ?? []) !== 1) $issues[] = 'purchase edit: expected 1 line, got '.count($got['items'] ?? []);
    if (abs((float) ($got['grand_total'] ?? 0) - (7 * 210 - 35 + (7 * 210 - 35) * 0.12 + 120)) > 0.01) $issues[] = 'purchase edit total wrong: '.json_encode($got['grand_total'] ?? null);
    if (substr((string) ($got['purchase_date'] ?? ''), 0, 10) !== now()->subDay()->toDateString()) $issues[] = 'purchase edit date not saved: '.json_encode($got['purchase_date'] ?? null);

    expect($issues)->toBe([]);
});
