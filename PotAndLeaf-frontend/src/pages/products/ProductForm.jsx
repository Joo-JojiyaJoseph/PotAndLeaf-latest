import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeftIcon, PrinterIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { defaultCreateCompanyId } from '../../lib/recordCompany';
import { useAuth } from '../../context/AuthContext';
import { fieldError } from '../../lib/formErrors';
import { useToast } from '../../lib/toast';
import { Button, Card, Field, Input, Spinner } from '../../components/ui';
import { ImageGallery } from '../../components/media';
import { Barcode, printBarcodeLabel } from '../../components/Barcode';

const selectCls =
  'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const STATUSES = ['active', 'inactive'];

const blank = {
  sku: '', name: '', hsn_code: '', barcode: '', description: '',
  category_id: '', subcategory_id: '', unit_id: '',
  gst_rate: '', cost_price: '', mrp: '', dealer_price: '', wholesale_price: '', retail_price: '',
  reorder_level: '', opening_stock: '', status: 'active', images: [],
  length_cm: '', width_cm: '', height_cm: '',
  is_rental: false, rental_daily_rate: '',
};

export default function ProductForm() {
  const { id } = useParams();
  const [searchParams] = useSearchParams();
  const { activeCompany, isSuperAdmin, companies, companyId } = useAuth();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const toast = useToast();
  const isEdit = Boolean(id);
  const [form, setForm] = useState(blank);
  const presetCompanyId = searchParams.get('company_id') ?? '';
  const [formCompanyId, setFormCompanyId] = useState(() => defaultCreateCompanyId({ filterCompanyId: presetCompanyId, companyId }));
  const [barcode, setBarcode] = useState('');
  const [errors, setErrors] = useState({});
  const err = (k) => fieldError(errors, k);
  const [saving, setSaving] = useState(false);
  const [uploadBusy, setUploadBusy] = useState(false);
  const [margin, setMargin] = useState('40');
  const companyReady = isEdit || !isSuperAdmin || Boolean(formCompanyId);

  const { data: existing, isLoading: loadingExisting } = useQuery({
    queryKey: ['product', id, searchParams.get('company_id')],
    queryFn: () => api.get(`/products/${id}`, withCompany(searchParams.get('company_id') || companyId)).then((r) => r.data.data),
    enabled: isEdit && Boolean(companyId),
  });

  const editCompanyId = existing?.company_id ?? searchParams.get('company_id') ?? companyId;
  const targetCompanyId = isEdit ? editCompanyId : (isSuperAdmin ? formCompanyId : companyId);
  const companyCfg = targetCompanyId ? withCompany(targetCompanyId) : {};

  const applyMargin = () => {
    const cost = Number(form.cost_price) || 0;
    const m = Number(margin) || 0;
    const price = Math.round(cost * (1 + m / 100) * 100) / 100;
    setForm((f) => ({ ...f, retail_price: price, mrp: f.mrp || price }));
  };

  const { data: formData, isLoading: loadingForm } = useQuery({
    queryKey: ['product-form-data', targetCompanyId || activeCompany?.id],
    enabled: Boolean(activeCompany) && companyReady && Boolean(targetCompanyId || !isSuperAdmin),
    queryFn: () => api.get('/products/form-data', companyCfg).then((r) => r.data.data),
  });

  const categories = formData?.categories ?? [];
  const rootCategories = useMemo(() => categories.filter((c) => !c.parent_id), [categories]);
  const subcategories = useMemo(
    () => categories.filter((c) => c.parent_id && c.parent_id === form.category_id),
    [categories, form.category_id],
  );

  const seededRef = useRef(null);

  useEffect(() => {
    if (isEdit || !isSuperAdmin) return;
    setFormCompanyId(defaultCreateCompanyId({ filterCompanyId: presetCompanyId, companyId }));
  }, [isEdit, isSuperAdmin, presetCompanyId, companyId]);

  useEffect(() => {
    if (!existing) return;
    if (seededRef.current === existing.id) return;
    if (existing.category_id && categories.length === 0) return;

    const cat = categories.find((c) => c.id === existing.category_id);
    const isSub = Boolean(cat?.parent_id);
    setForm({
      sku: existing.sku ?? '', name: existing.name ?? '', hsn_code: existing.hsn_code ?? '',
      barcode: existing.barcode ?? '', description: existing.description ?? '',
      category_id: isSub ? cat.parent_id : (existing.category_id ?? ''),
      subcategory_id: isSub ? existing.category_id : '',
      unit_id: existing.unit_id ?? '',
      gst_rate: existing.gst_rate ?? '', cost_price: existing.cost_price ?? '', mrp: existing.mrp ?? '',
      dealer_price: existing.dealer_price ?? '', wholesale_price: existing.wholesale_price ?? '',
      retail_price: existing.retail_price ?? '', reorder_level: existing.reorder_level ?? '',
      opening_stock: existing.opening_stock ?? '', status: existing.status ?? 'active', images: existing.images ?? [],
      length_cm: existing.length_cm ?? '', width_cm: existing.width_cm ?? '', height_cm: existing.height_cm ?? '',
      is_rental: Boolean(existing.is_rental), rental_daily_rate: existing.rental_daily_rate ?? '',
    });
    setBarcode(existing.barcode ?? '');
    seededRef.current = existing.id;
  }, [existing, categories]);

  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  async function save() {
    if (saving || uploadBusy) return;

    const clientErrors = {};
    if (!form.name?.trim()) clientErrors.name = ['Name is required.'];
    if (!form.category_id && !form.subcategory_id) clientErrors.category_id = ['Category is required.'];
    if (form.cost_price === '' || form.cost_price == null || Number.isNaN(Number(form.cost_price))) {
      clientErrors.cost_price = ['Cost price is required.'];
    }
    if (Object.keys(clientErrors).length) {
      setErrors(clientErrors);
      toast.error('Please fix the highlighted fields.');
      return;
    }

    setErrors({});
    setSaving(true);
    const payload = { ...form };
    payload.category_id = form.subcategory_id || form.category_id || null;
    delete payload.subcategory_id;
    delete payload.sku;
    delete payload.brand_id;
    ['gst_rate', 'cost_price', 'mrp', 'dealer_price', 'wholesale_price', 'retail_price', 'reorder_level', 'opening_stock'].forEach(
      (k) => (payload[k] = payload[k] === '' || payload[k] == null ? 0 : Number(payload[k])),
    );
    ['length_cm', 'width_cm', 'height_cm'].forEach(
      (k) => (payload[k] = payload[k] === '' ? null : Number(payload[k])),
    );
    payload.is_rental = Boolean(form.is_rental);
    payload.rental_daily_rate = form.is_rental && form.rental_daily_rate !== '' ? Number(form.rental_daily_rate) : null;
    ['hsn_code', 'barcode', 'description', 'category_id', 'unit_id'].forEach(
      (k) => (payload[k] = payload[k] === '' ? null : payload[k]),
    );

    try {
      const res = isEdit
        ? await api.put(`/products/${id}`, payload, companyCfg)
        : await api.post('/products', payload, companyCfg);
      await queryClient.invalidateQueries({ queryKey: ['products'] });
      await queryClient.invalidateQueries({ queryKey: ['product', id] });
      toast.success(isEdit ? 'Product updated.' : 'Product created.');
      setBarcode(res.data.data.barcode);
      if (isEdit) {
        seededRef.current = null;
        navigate(`/products/${id}${editCompanyId ? `?company_id=${editCompanyId}` : ''}`);
      } else {
        navigate('/products');
      }
    } catch (e) {
      const apiErrors = e.response?.data?.errors ?? {};
      const message = e.response?.data?.message ?? 'Could not save the product.';
      setErrors(Object.keys(apiErrors).length ? apiErrors : { _: [message] });
      toast.error(message);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
      setSaving(false);
    }
  }

  if (loadingForm || (isEdit && loadingExisting)) {
    return (
      <div className="flex h-full items-center justify-center">
        <Spinner className="size-6" />
      </div>
    );
  }

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">{isEdit ? 'Edit product' : 'New product'}</h1>
          <p className="text-sm text-muted">Product master with pricing, tax, reorder level and barcode.</p>
        </div>
        <Button variant="outline" size="sm" onClick={() => navigate('/products')}>
          <ArrowLeftIcon className="size-4" /> Back
        </Button>
      </div>

      {fieldError(errors, '_') && (
        <div className="rounded-xl bg-danger-soft px-4 py-3 text-sm text-danger">{fieldError(errors, '_')}</div>
      )}

      {isSuperAdmin && !isEdit && (
        <Card className="p-4">
          <Field label="Company" required>
            <select
              value={formCompanyId}
              onChange={(e) => setFormCompanyId(e.target.value)}
              className={selectCls}
            >
              <option value="">Select company first…</option>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </Field>
          <p className="mt-1.5 text-xs text-muted">Choose which company this product belongs to. Your workspace company stays unchanged.</p>
        </Card>
      )}

      {companyReady && (
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="p-5 lg:col-span-2">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {isEdit ? (
              <Field label="SKU"><Input value={form.sku} disabled readOnly className="bg-paper text-muted" /></Field>
            ) : (
              <div className="text-xs text-muted sm:col-span-2">SKU is generated automatically when you save.</div>
            )}
            <Field label="Name" required error={err('name')}>
              <Input value={form.name} onChange={set('name')} />
            </Field>
            <Field label="HSN code" error={err('hsn_code')}>
              <Input value={form.hsn_code} onChange={set('hsn_code')} />
            </Field>
            <Field label="Status" error={err('status')}>
              <select value={form.status} onChange={set('status')} className={selectCls}>
                {STATUSES.map((s) => <option key={s} value={s} className="capitalize">{s}</option>)}
              </select>
            </Field>
            <Field label="Category" required error={err('category_id')}>
              <select
                value={form.category_id}
                onChange={(e) => setForm((f) => ({ ...f, category_id: e.target.value, subcategory_id: '' }))}
                className={selectCls}
              >
                <option value="">—</option>
                {rootCategories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
              {!rootCategories.length && (
                <p className="mt-1 text-xs text-muted">No categories yet — add them under Master data.</p>
              )}
            </Field>
            <Field label="Subcategory" error={err('subcategory_id')}>
              <select
                value={form.subcategory_id}
                onChange={set('subcategory_id')}
                className={selectCls}
                disabled={!form.category_id}
              >
                <option value="">—</option>
                {subcategories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </Field>
            <Field label="Unit" error={err('unit_id')}>
              <select value={form.unit_id} onChange={set('unit_id')} className={selectCls}>
                <option value="">—</option>
                {(formData?.units ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}{u.short_name ? ` (${u.short_name})` : ''}</option>)}
              </select>
              {!formData?.units?.length && (
                <p className="mt-1 text-xs text-muted">No units yet — add them under Master data.</p>
              )}
            </Field>
            <Field label="GST %">
              <select value={form.gst_rate} onChange={set('gst_rate')} className={selectCls}>
                <option value="">—</option>
                {(formData?.tax_rates ?? [0, 5, 12, 18, 28]).map((r) => <option key={r} value={r}>{r}%</option>)}
              </select>
            </Field>
          </div>

          <div className="mt-5 border-t border-line pt-5">
            <div className="mb-3 flex items-center justify-between">
              <div className="microlabel text-faint">Pricing</div>
              <div className="flex items-center gap-2">
                <span className="text-xs text-muted">Suggest from cost + margin</span>
                <input type="number" value={margin} onChange={(e) => setMargin(e.target.value)} className="tnum h-8 w-16 rounded-lg border border-line bg-surface px-2 text-right text-sm" />
                <span className="text-xs text-muted">%</span>
                <Button type="button" variant="soft" size="sm" onClick={applyMargin}>Apply</Button>
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
              <Field label="Cost price" required error={err('cost_price')}><Input type="number" step="0.01" value={form.cost_price} onChange={set('cost_price')} /></Field>
              <Field label="MRP"><Input type="number" step="0.01" value={form.mrp} onChange={set('mrp')} /></Field>
              <Field label="Retail price"><Input type="number" step="0.01" value={form.retail_price} onChange={set('retail_price')} /></Field>
              <Field label="Wholesale price"><Input type="number" step="0.01" value={form.wholesale_price} onChange={set('wholesale_price')} /></Field>
              <Field label="Dealer price"><Input type="number" step="0.01" value={form.dealer_price} onChange={set('dealer_price')} /></Field>
            </div>
          </div>

          <div className="mt-5 border-t border-line pt-5">
            <div className="microlabel mb-3 text-faint">Stock</div>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
              <Field label="Reorder level"><Input type="number" step="0.01" value={form.reorder_level} onChange={set('reorder_level')} /></Field>
              <Field label="Opening stock"><Input type="number" step="0.01" value={form.opening_stock} onChange={set('opening_stock')} /></Field>
            </div>
          </div>

          <div className="mt-5 border-t border-line pt-5">
            <div className="microlabel mb-3 text-faint">Dimensions (cm) — for CBM / container planning</div>
            <div className="grid grid-cols-3 gap-4">
              <Field label="Length"><Input type="number" step="0.01" value={form.length_cm} onChange={set('length_cm')} /></Field>
              <Field label="Width"><Input type="number" step="0.01" value={form.width_cm} onChange={set('width_cm')} /></Field>
              <Field label="Height"><Input type="number" step="0.01" value={form.height_cm} onChange={set('height_cm')} /></Field>
            </div>
          </div>

          <div className="mt-5 border-t border-line pt-5">
            <div className="microlabel mb-3 text-faint">Plant rental</div>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={Boolean(form.is_rental)}
                onChange={(e) => setForm((f) => ({ ...f, is_rental: e.target.checked }))}
                className="size-4 rounded border-line text-leaf focus:ring-leaf/40"
              />
              This product can be rented
            </label>
            {form.is_rental && (
              <div className="mt-3 max-w-xs">
                <Field label="Daily rental rate">
                  <Input type="number" step="0.01" value={form.rental_daily_rate} onChange={set('rental_daily_rate')} />
                </Field>
              </div>
            )}
          </div>

          <div className="mt-5 border-t border-line pt-5">
            <Field label="Description" error={err('description')}>
              <textarea value={form.description} onChange={set('description')} rows={3} className="w-full rounded-xl border border-line bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25" placeholder="Care notes, variety details…" />
            </Field>
          </div>
        </Card>

        <div className="space-y-4">
          <Card className="p-5">
            <div className="microlabel mb-3 text-faint">Barcode</div>
            <p className="text-sm text-muted">
              Barcodes are assigned to each batch when stock is received on a purchase, so the
              same product can carry a different barcode per lot. Print batch labels from the
              purchase once it&rsquo;s confirmed.
            </p>
            {barcode && (
              <div className="mt-4 flex flex-col items-center gap-3 border-t border-line pt-4">
                <div className="microlabel self-start text-faint">Product-level barcode</div>
                <div className="rounded-xl bg-white p-3">
                  <Barcode value={barcode} />
                </div>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => printBarcodeLabel({ barcode, name: form.name, price: form.retail_price || form.mrp })}
                >
                  <PrinterIcon className="size-4" /> Print label
                </Button>
              </div>
            )}
            <div className="mt-4">
              <Field label="Product-level barcode (optional)">
                <Input value={form.barcode} onChange={set('barcode')} placeholder="Usually left blank — set per batch" />
              </Field>
            </div>
          </Card>

          <Card className="p-5">
            <div className="microlabel mb-3 text-faint">Photos</div>
            <ImageGallery value={form.images} onChange={(imgs) => setForm((f) => ({ ...f, images: imgs }))} max={6} onBusyChange={setUploadBusy} />
          </Card>

          <Button className="w-full" onClick={save} disabled={saving || uploadBusy}>
            {uploadBusy ? 'Uploading photos…' : saving ? <Spinner className="border-white/40 border-t-white" /> : isEdit ? 'Save changes' : 'Create product'}
          </Button>
        </div>
      </div>
      )}
    </div>
  );
}
