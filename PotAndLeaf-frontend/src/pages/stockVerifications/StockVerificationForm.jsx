import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { defaultCreateCompanyId } from '../../lib/recordCompany';
import { Button, Card, Field, Input, Spinner, Select } from '../../components/ui';
import { classNames, todayISO as today } from '../../lib/format';
const selectCls =
  'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const numInput =
  'h-9 w-full rounded-xl border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/25';

function varianceClass(v) {
  if (Math.abs(v) < 1e-6) return 'text-muted';
  return v < 0 ? 'text-danger' : 'text-amber';
}

export default function StockVerificationForm() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { activeCompany, isSuperAdmin, companies, companyId } = useAuth();
  const presetCompanyId = searchParams.get('company_id') ?? '';
  const [formCompanyId, setFormCompanyId] = useState(() => defaultCreateCompanyId({ filterCompanyId: presetCompanyId, companyId }));
  const [countDate, setCountDate] = useState(today());
  const [locationNote, setLocationNote] = useState('');
  const [notes, setNotes] = useState('');
  const [counts, setCounts] = useState({}); // product_id -> string
  const [search, setSearch] = useState('');
  const [errors, setErrors] = useState([]);
  const [saving, setSaving] = useState(false);

  const targetCompanyId = isSuperAdmin ? formCompanyId : companyId;
  const companyCfg = targetCompanyId ? withCompany(targetCompanyId) : {};
  const companyReady = !isSuperAdmin || Boolean(formCompanyId);

  useEffect(() => {
    if (!isSuperAdmin) return;
    setFormCompanyId(defaultCreateCompanyId({ filterCompanyId: presetCompanyId, companyId }));
  }, [isSuperAdmin, presetCompanyId, companyId]);

  useEffect(() => {
    setCounts({});
    setSearch('');
  }, [targetCompanyId]);

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['sv-form-data', targetCompanyId],
    enabled: Boolean(activeCompany) && companyReady && Boolean(targetCompanyId),
    queryFn: () => api.get('/stock-verifications/form-data', companyCfg).then((r) => r.data.data),
  });

  const products = data?.products ?? [];
  // One row per batch (oldest first); a product with no batches is one row.
  // Stock outside every batch (rare) gets its own "Not in a batch" row.
  const rows = useMemo(() => {
    const byProduct = {};
    (data?.batches ?? []).forEach((b) => { (byProduct[b.product_id] ??= []).push(b); });
    const out = [];
    products.forEach((p) => {
      const bs = byProduct[p.id] ?? [];
      if (bs.length === 0) {
        out.push({ key: `p:${p.id}`, product: p, batch: null, system_qty: p.system_qty, first: true, last: true });
        return;
      }
      const outside = Math.round((p.system_qty - bs.reduce((t, b) => t + b.system_qty, 0)) * 1000) / 1000;
      const list = bs.map((b) => ({ key: `b:${b.id}`, product: p, batch: b, system_qty: b.system_qty }));
      if (outside > 0.0005) list.push({ key: `p:${p.id}`, product: p, batch: null, outsideBatches: true, system_qty: outside });
      list.forEach((r, i) => { r.first = i === 0; r.last = i === list.length - 1; r.count = list.length; });
      out.push(...list);
    });
    return out;
  }, [products, data]);
  const countFor = (r) => (counts[r.key] === undefined ? r.system_qty : counts[r.key]);

  const visible = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return rows;
    return rows.filter((r) => [r.product.name, r.product.sku, r.batch?.batch_no, r.batch?.barcode].filter(Boolean).join(' ').toLowerCase().includes(q));
  }, [rows, search]);

  const discrepancies = rows.reduce((n, r) => {
    const v = Number(countFor(r)) - r.system_qty;
    return Math.abs(v) > 1e-6 ? n + 1 : n;
  }, 0);

  async function save() {
    if (!companyReady || !targetCompanyId) {
      setErrors(['Select a company first.']);
      return;
    }

    setErrors([]);
    setSaving(true);
    const items = rows.map((r) => ({ product_id: r.product.id, product_batch_id: r.batch?.id ?? null, counted_qty: Number(countFor(r)) || 0 }));
    try {
      await api.post('/stock-verifications', {
        count_date: countDate,
        location_note: locationNote || null,
        notes: notes || null,
        items,
      }, companyCfg);
      navigate('/stock-verifications');
    } catch (err) {
      const bag = err.response?.data?.errors;
      setErrors(bag ? Object.values(bag).flat() : [err.response?.data?.message ?? 'Could not save the count.']);
    } finally {
      setSaving(false);
    }
  }

  if (isSuperAdmin && !companyReady) {
    return (
      <div className="space-y-5 p-4 sm:p-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="page-title">New stock count</h1>
            <p className="text-sm text-muted">Choose which company this physical count belongs to.</p>
          </div>
          <Button variant="outline" size="sm" onClick={() => navigate('/stock-verifications')}>
            <ArrowLeftIcon className="size-4" /> Back
          </Button>
        </div>
        <Card className="p-5">
          <Field label="Company" required>
            <Select value={formCompanyId} onChange={(e) => setFormCompanyId(e.target.value)} className={selectCls}>
              <option value="">Select company first…</option>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </Field>
          <p className="mt-1.5 text-xs text-muted">Products and stock levels load after you pick a company.</p>
        </Card>
      </div>
    );
  }

  if (isLoading || (isFetching && products.length === 0)) {
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
          <h1 className="page-title">New stock count</h1>
          <p className="text-sm text-muted">Count each batch on the shelf (match the barcode label) and type what you find. Submit for approval to adjust stock.</p>
        </div>
        <Button variant="outline" size="sm" onClick={() => navigate('/stock-verifications')}>
          <ArrowLeftIcon className="size-4" /> Back
        </Button>
      </div>

      {errors.length > 0 && (
        <div className="rounded-xl bg-danger-soft px-4 py-3 text-sm text-danger">
          <ul className="list-disc space-y-0.5 pl-4">
            {errors.map((e, i) => (
              <li key={i}>{e}</li>
            ))}
          </ul>
        </div>
      )}

      {isSuperAdmin && (
        <Card className="p-5">
          <Field label="Company" required>
            <Select value={formCompanyId} onChange={(e) => setFormCompanyId(e.target.value)} className={selectCls}>
              <option value="">Select company first…</option>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </Field>
          <p className="mt-1.5 text-xs text-muted">Count sheet and saved draft belong to this company. Your workspace company stays unchanged.</p>
        </Card>
      )}

      <Card className="p-5">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Field label="Count date" required>
            <Input type="date" value={countDate} onChange={(e) => setCountDate(e.target.value)} />
          </Field>
          <Field label="Location / area">
            <Input value={locationNote} onChange={(e) => setLocationNote(e.target.value)} placeholder="Godown, Shelf A…" />
          </Field>
          <Field label="Notes">
            <Input value={notes} onChange={(e) => setNotes(e.target.value)} />
          </Field>
        </div>
      </Card>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="relative max-w-md flex-1">
          <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Filter products…" className="pl-9" />
        </div>
        <span className="text-sm text-muted">
          {discrepancies} discrepanc{discrepancies === 1 ? 'y' : 'ies'} of {rows.length} rows ({products.length} products)
        </span>
      </div>

      <Card className="overflow-hidden">
        {products.length === 0 ? (
          <div className="px-4 py-16 text-center text-sm text-muted">No products in this company yet.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[680px] text-sm">
              <thead>
                <tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">Product</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Batch</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">System</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Counted</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Variance</th>
                </tr>
              </thead>
              <tbody>
                {visible.map((r) => {
                  const v = Number(countFor(r)) - r.system_qty;
                  const showName = r.first || search.trim();
                  return (
                    <tr key={r.key} className={classNames('border-line/60', r.last || search.trim() ? 'border-b last:border-0' : '')}>
                      <td className="px-4 py-2 align-top">
                        {showName ? (
                          <>
                            <div className="font-medium">{r.product.name}</div>
                            <div className="tnum text-xs text-muted">
                              {r.product.sku}{r.product.unit ? ` · ${r.product.unit}` : ''}
                              {r.count > 1 ? ` · total ${r.product.system_qty} in ${r.count} rows` : ''}
                            </div>
                          </>
                        ) : null}
                      </td>
                      <td className="px-4 py-2">
                        {r.batch ? (
                          <>
                            <div className="tnum text-sm">{r.batch.batch_no}</div>
                            <div className="tnum text-[11px] text-muted">{r.batch.barcode}{r.batch.received_at ? ` · ${r.batch.received_at}` : ''}</div>
                          </>
                        ) : (
                          <span className="text-xs text-muted">{r.outsideBatches ? 'Not in a batch' : 'No batches'}</span>
                        )}
                      </td>
                      <td className="tnum px-4 py-2 text-right text-muted">{r.system_qty}</td>
                      <td className="px-4 py-2">
                        <input
                          type="number"
                          step="0.001"
                          min="0"
                          aria-label={`Counted ${r.product.name}${r.batch ? ` batch ${r.batch.batch_no}` : ''}`}
                          className={numInput}
                          value={countFor(r)}
                          onChange={(e) => setCounts((c) => ({ ...c, [r.key]: e.target.value }))}
                        />
                      </td>
                      <td className={classNames('tnum px-4 py-2 text-right font-medium', varianceClass(v))}>
                        {v > 0 ? '+' : ''}{Math.round(v * 1000) / 1000}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <div className="flex justify-end">
        <Button onClick={save} disabled={saving || products.length === 0 || !companyReady}>
          {saving ? <Spinner className="border-white/40 border-t-white" /> : 'Save draft'}
        </Button>
      </div>
    </div>
  );
}
