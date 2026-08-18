import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
  EyeIcon,
  MagnifyingGlassIcon,
  PlusIcon,
  PencilSquareIcon,
  PhotoIcon,
  TrashIcon,
  QrCodeIcon,
} from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { useToast } from '../../lib/toast';
import { useConfirm } from '../../lib/confirm';
import { Badge, Button, Card, Input, Spinner } from '../../components/ui';
import Pagination from '../../components/Pagination';
import StatusToggle from '../../components/StatusToggle';
import { MediaImg } from '../../components/media';
import { formatCurrency } from '../../lib/format';
import { defaultCreateCompanyId } from '../../lib/recordCompany';

function productDetailPath(p) {
  return p.company_id ? `/products/${p.id}?company_id=${p.company_id}` : `/products/${p.id}`;
}

function productEditPath(p) {
  return p.company_id ? `/products/${p.id}/edit?company_id=${p.company_id}` : `/products/${p.id}/edit`;
}

export default function ProductsList() {
  const { activeCompany, can, companyId, isSuperAdmin } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');

  // Live search: filter as the user types (Enter still works too).
  useEffect(() => {
    const t = setTimeout(() => { setPage(1); setDebounced(search.trim()); }, 300);
    return () => clearTimeout(t);
  }, [search]);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [lowOnly, setLowOnly] = useState(false);

  const { data: formData } = useQuery({
    queryKey: ['products-form-data', activeCompany?.id, filterCompanyId],
    queryFn: () => api.get('/products/form-data', { params: companyParams }).then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });

  const { data, isLoading, isError } = useQuery({
    queryKey: ['products', activeCompany?.id, filterCompanyId, debounced, page, status, categoryId, lowOnly],
    queryFn: () => api.get('/products', {
      params: {
        ...companyParams,
        search: debounced,
        per_page: 24,
        page,
        status: status || undefined,
        category_id: categoryId || undefined,
        low_only: lowOnly ? 1 : undefined,
      },
    }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    keepPreviousData: true,
  });

  const rows = data?.data ?? [];
  const meta = data?.meta;
  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['products'] });

  async function onToggle(p, next) {
    await api.patch(`/products/${p.id}/status`, { status: next ? 'active' : 'inactive' }, withCompany(p.company_id ?? companyId));
    toast.success(`${p.name} ${next ? 'activated' : 'deactivated'}`);
    invalidate();
  }

  async function onDelete(p) {
    const ok = await confirm({
      title: 'Delete product',
      message: `Delete ${p.name}? This is a soft delete — stock history is preserved.`,
      confirmLabel: 'Delete',
      tone: 'danger',
    });
    if (!ok) return;
    try {
      await api.delete(`/products/${p.id}`, withCompany(p.company_id ?? companyId));
      toast.success(`${p.name} deleted`);
      invalidate();
    } catch (e) {
      toast.error(e.response?.data?.message ?? 'Could not delete product.');
    }
  }

  const selectCls = 'h-10 rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
  const newProductPath = (() => {
    const createCompanyId = defaultCreateCompanyId({ filterCompanyId, companyId });
    return createCompanyId && isSuperAdmin ? `/products/new?company_id=${createCompanyId}` : '/products/new';
  })();

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Products</h1>
          <p className="text-sm text-muted">Product master with live stock levels and barcodes{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can('products.create') && (
            <>
              <Link to="/products/labels"><Button variant="outline" size="sm"><QrCodeIcon className="size-4" /> Labels</Button></Link>
              <Link to={newProductPath}><Button size="sm"><PlusIcon className="size-4" /> New product</Button></Link>
            </>
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <form
          onSubmit={(e) => { e.preventDefault(); setPage(1); setDebounced(search); }}
          className="relative min-w-[220px] max-w-md flex-1"
        >
          <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search name, SKU or barcode…" className="pl-9" />
        </form>
        <select
          value={categoryId}
          onChange={(e) => { setCategoryId(e.target.value); setPage(1); }}
          className={selectCls}
        >
          <option value="">All categories</option>
          {(formData?.categories ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        <select
          value={status}
          onChange={(e) => { setStatus(e.target.value); setPage(1); }}
          className={selectCls}
        >
          <option value="">All status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
        <label className="flex items-center gap-2 text-sm text-muted">
          <input
            type="checkbox"
            checked={lowOnly}
            onChange={(e) => { setLowOnly(e.target.checked); setPage(1); }}
            className="size-4 rounded border-line text-leaf focus:ring-leaf/40"
          />
          Reorder level
        </label>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
      ) : isError ? (
        <Card className="px-4 py-12 text-center text-sm text-muted">Couldn't load products.</Card>
      ) : rows.length === 0 ? (
        <Card className="px-4 py-16 text-center">
          <p className="text-sm font-medium">No products yet</p>
          <p className="mt-1 text-sm text-muted">Add your first product to start purchasing and selling.</p>
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {rows.map((p) => {
            const thumb = Array.isArray(p.images) && p.images.length ? p.images[0] : null;
            return (
              <Card key={p.id} className="flex flex-col overflow-hidden p-4">
                <div className="flex items-start gap-3">
                  <div className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-leaf-soft">
                    {thumb
                      ? <MediaImg value={thumb} className="size-full object-cover" iconClassName="size-7 text-leaf/50" />
                      : <PhotoIcon className="size-7 text-leaf/50" />}
                  </div>
                  <div className="min-w-0 flex-1">
                    <button
                      type="button"
                      onClick={() => navigate(productDetailPath(p))}
                      className="block truncate text-left font-semibold text-ink hover:text-leaf"
                    >
                      {p.name}
                    </button>
                    <p className="tnum text-xs text-muted">{p.sku}</p>
                    <p className="mt-0.5 truncate text-xs text-muted">{p.category || 'Uncategorised'}</p>
                    {p.pool_role && (
                      <Badge tone="info" className="mt-1">
                        {p.pool_role === 'set' ? 'Set SKU' : 'Unit SKU'} · pooled
                      </Badge>
                    )}
                  </div>
                </div>
                <div className="mt-3 flex items-center justify-between text-sm">
                  <span className="tnum font-medium">{formatCurrency(p.retail_price ?? 0)}</span>
                  <span className={`tnum text-xs ${p.is_low_stock ? 'text-amber' : 'text-muted'}`}>
                    Stock {p.current_stock}
                  </span>
                </div>
                <div className="mt-3 flex items-center justify-between border-t border-line pt-3">
                  {can('products.update')
                    ? <StatusToggle active={p.status === 'active'} onToggle={(next) => onToggle(p, next)} />
                    : <Badge tone={p.status === 'active' ? 'active' : 'inactive'}>{p.status}</Badge>}
                  <div className="flex items-center gap-1">
                    <button
                      type="button"
                      onClick={() => navigate(productDetailPath(p))}
                      className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink"
                      aria-label="View"
                    >
                      <EyeIcon className="size-4" />
                    </button>
                    {(p.can?.update ?? can('products.update')) && (
                      <button
                        type="button"
                        onClick={() => navigate(productEditPath(p))}
                        className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink"
                        aria-label="Edit"
                      >
                        <PencilSquareIcon className="size-4" />
                      </button>
                    )}
                    {(p.can?.delete ?? can('products.delete')) && (
                      <button
                        type="button"
                        onClick={() => onDelete(p)}
                        className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger"
                        aria-label="Delete"
                      >
                        <TrashIcon className="size-4" />
                      </button>
                    )}
                  </div>
                </div>
              </Card>
            );
          })}
        </div>
      )}

      {meta && meta.last_page > 1 && (
        <div className="border-t border-line pt-3">
          <Pagination meta={meta} onPage={setPage} />
        </div>
      )}
    </div>
  );
}