# Nursery ERP — Foundation + Suppliers + Products + Lookups

JavaScript-authored modules on top of your Laravel React Starter Kit, using your design system and Heroicons. This drop contains:

- the JavaScript setup, design system, and app-shell components,
- the **Supplier** module (rich, explicit layered stack — the reference pattern),
- the **Product** module (rich: pricing, stock, multi-supplier sourcing, images),
- **Categories, Brands, Units** (simple lookup masters on a shared engine).

Every file mirrors the Laravel project structure, so each drops straight into the matching path.

> **This drop was syntax-checked.** All PHP passes `php -l`; all JSX/JS parses under esbuild. That catches syntax errors, not runtime/wiring issues — you'll still integrate and run it.

---

## 1. Two levels of ceremony (important design decision)

Not every master deserves the same machinery. This codebase uses two tiers:

- **Rich modules — Supplier, Product** — full explicit stack: Model -> Repository (interface + Eloquent) -> Service -> Action classes -> FormRequests -> Policy -> Resource -> thin Controller. They carry real logic (encryption, supplier sync, stock seeding, future ledger/barcode hooks), so the ceremony pays off.
- **Lookup masters — Category, Brand, Unit** — a shared engine in `app/Support/Lookup/` (`LookupRepository`, `LookupService`, abstract `LookupController`). Each concrete controller is ~30 lines declaring its model, routes key, permission prefix, validation rules, and row shape. No per-module repository/service/request/resource/policy files.

This is deliberate: copy-pasting nine near-identical files for a two-field table is the kind of thing good architecture avoids. Match ceremony to complexity. If a lookup later grows real logic, promote it to the rich stack (copy the Supplier shape).

---

## 2. JavaScript, not TypeScript

Your kit is TypeScript throughout (shadcn/ui, Wayfinder, `.d.ts`). Every file I wrote is `.js`/`.jsx`; the kit's TS vendor/infra stays as-is and Vite compiles both. See the previous drop's notes for the full rationale — nothing changed here.

---

## 3. Install & wire up

```bash
pnpm add @heroicons/react react-hook-form @tanstack/react-table @tanstack/react-query \
         apexcharts react-apexcharts framer-motion sweetalert2
```

1. Copy files into the matching paths (section 4).
2. `bootstrap/providers.php` -> add `App\Providers\RepositoryServiceProvider::class`.
3. `routes/web.php` -> add `require __DIR__.'/nursery.php';`.
4. `App\Models\User` -> `use App\Models\Concerns\InteractsWithPermissions;`.
5. `php artisan migrate`
6. `pnpm dev`

Routes now available (team-scoped): `/{team}/suppliers`, `/{team}/products`, `/{team}/categories`, `/{team}/brands`, `/{team}/units`.

Rich-module policies (Supplier, Product) are auto-discovered. The lookup engine authorizes inline via `hasPermission()` — no policy class needed.

---

## 4. File map (new + changed this update)

**New backend**
```
app/Support/Lookup/{LookupRepository,LookupService,LookupController}.php   <- shared engine
app/Enums/ProductStatus.php
app/Models/{Product,ProductCategory,ProductBrand,ProductUnit}.php
app/Repositories/Contracts/ProductRepositoryInterface.php
app/Repositories/Eloquent/ProductRepository.php
app/Services/ProductService.php
app/Actions/Products/{CreateProduct,UpdateProduct,DeleteProduct}.php
app/Http/Requests/Product/{StoreProductRequest,UpdateProductRequest}.php
app/Http/Resources/ProductResource.php
app/Http/Controllers/ProductController.php
app/Http/Controllers/{ProductCategory,ProductBrand,ProductUnit}Controller.php
app/Policies/ProductPolicy.php
database/migrations/2026_01_01_0000{02,03,04,05,06}_*.php
```

**New frontend**
```
resources/js/components/nursery/LookupResource.jsx     <- config-driven CRUD (table + modal)
resources/js/pages/products/{index,form}.jsx
resources/js/pages/{categories,brands,units}/index.jsx
```

**Changed**
```
app/Providers/RepositoryServiceProvider.php   <- added Product binding
routes/nursery.php                            <- added products + lookup routes
```

Supplier files and the foundation (theme, jsconfig, vite, DataTable, PageHeader, StatusBadge, format.js) are unchanged from the previous drop and included for completeness.

---

## 5. Permissions this expects

The stub grants everything until you build RBAC. When you do, these are the strings in play:

```
suppliers.view|create|update|delete|force-delete
products.view|create|update|delete|force-delete
categories.view|create|update|delete
brands.view|create|update|delete
units.view|create|update|delete
```

---

## 6. Notable module details

- **Product / Supplier** is many-to-many (`product_supplier` pivot with `supplier_price` + `is_primary`). The form has repeatable supplier rows; the action syncs them.
- **Stock**: `opening_stock` seeds `current_stock` on create and is read-only on edit — real movements belong to inventory transactions (Milestone 2), not the product form.
- **Low stock**: `current_stock <= reorder_level` drives the amber indicator and the "Low stock only" filter (a `whereColumn` at the query layer).
- **Images**: stored as a JSON array of paths. The form takes URLs/paths for now; a real upload pipeline (Dropzone + `Storage` + validation) is a follow-up — flagged, not faked.
- **Categories** carry a `parent_id` column for hierarchy; the tree UI isn't built yet, so the form keeps them flat for now.

---

## 7. What's left of the global-CRUD checklist

Present across these modules: create, edit, soft delete, restore, search, filters, sorting, pagination, bulk-select (UI), responsive card view, validation + inline errors, confirm-on-delete, modal forms.

Not yet wired (shared work, best done once as reusable pieces): Excel/PDF export, Excel import, real image/attachment upload, activity log, timeline/notes, bulk update, column visibility, saved filters, duplicate. Say the word and I'll build the export/import/activity-log trio as reusable traits + components that every module inherits.

---

## 8. Honest caveats

- **Syntax-verified, not run.** No database or Vite build here, so wiring issues (a shared-prop name, the `current_team` binding, a missing import alias) can still surface on first boot.
- **RBAC is stubbed** (`hasPermission()` returns `true`). Build it before any real deployment.
- **Resource wrapping.** Single resources may arrive with or without a `data` key depending on your Inertia config; the forms handle both via `record?.data ?? record`.
