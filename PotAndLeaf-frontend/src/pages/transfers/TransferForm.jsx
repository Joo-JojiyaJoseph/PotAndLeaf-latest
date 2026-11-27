import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { todayISO } from '../../lib/format';
import { useConfirm } from '../../lib/confirm';
import { Button, Card, Field, Input, Spinner, Select } from '../../components/ui';
import SearchSelect from '../../components/SearchSelect';

const today = todayISO;
const emptyLine = () => ({ product_id: '', qty: '' });
const selectCls = 'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25';
const numInput = 'h-9 w-full rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-leaf/30';
const typeLabel = (t) => (t === 'godown' ? 'Godown' : 'Branch');

/**
 * Send stock between two locations of this company:
 * godown to branch, branch to godown, or branch to branch. Never company to company.
 */
export default function TransferForm() {
  const navigate = useNavigate();
  const { activeCompany, isSuperAdmin } = useAuth();
  const confirmDialog = useConfirm();
  const companyId = activeCompany?.id ? String(activeCompany.id) : '';
  const companyCfg = companyId ? withCompany(companyId) : {};

  const [header, setHeader] = useState({ from_location_id: '', to_location_id: '', transfer_date: today(), notes: '' });
  const [lines, setLines] = useState([emptyLine()]);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['transfer-form-data', companyId],
    queryFn: () => api.get('/transfers/form-data', companyCfg).then((r) => r.data.data),
    enabled: Boolean(companyId),
  });

  const products = data?.products ?? [];
  const locations = data?.locations ?? [];
  const stockRows = data?.stock ?? [];
  const myLocationId = data?.my_location_id ?? '';
  const isGodownUser = Boolean(data?.is_godown_user);
  const byId = useMemo(() => Object.fromEntries(locations.map((l) => [String(l.id), l])), [locations]);
  const from = byId[String(header.from_location_id)];
  const to = byId[String(header.to_location_id)];

  // What each product has free at the chosen sending location.
  const stockHere = useMemo(() => {
    const map = {};
    stockRows.filter((r) => String(r.location_id) === String(header.from_location_id)).forEach((r) => { map[r.product_id] = Number(r.available) || 0; });
    return map;
  }, [stockRows, header.from_location_id]);

  // Start from the person's own location (a godown user's own godown is fixed as sender or receiver).
  useEffect(() => {
    if (!locations.length || header.from_location_id) return;
    const first = (myLocationId && byId[String(myLocationId)]) || locations.find((l) => l.is_default) || locations[0];
    setHeader((h) => ({ ...h, from_location_id: String(first.id) }));
  }, [locations, myLocationId, byId, header.from_location_id]);

  // Where this stock may go: not the same place, and never godown to godown.
  const toOptions = locations.filter((l) => String(l.id) !== String(header.from_location_id) && !(from?.type === 'godown' && l.type === 'godown'));
  const fromOptions = locations;

  useEffect(() => {
    if (header.to_location_id && !toOptions.some((l) => String(l.id) === String(header.to_location_id))) {
      setHeader((h) => ({ ...h, to_location_id: '' }));
    }
  }, [header.from_location_id]); // eslint-disable-line react-hooks/exhaustive-deps

  const productOptions = products.map((p) => ({
    value: p.id,
    label: p.name,
    image: p.image ?? null,
    hasImage: true,
    sublabel: [p.sku, header.from_location_id ? `${stockHere[p.id] ?? 0} at ${from?.name ?? 'sender'}` : null].filter(Boolean).join(' · '),
  }));
  const err = (k) => errors[k]?.[0];
  const setLine = (i, patch) => setLines((prev) => prev.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));
  const available = (productId) => (productId ? (stockHere[productId] ?? 0) : null);

  async function save({ confirm = false } = {}) {
    setErrors({});
    const clientErrors = {};
    if (!header.from_location_id) clientErrors.from_location_id = ['Choose where the stock is sent from.'];
    if (!header.to_location_id) clientErrors.to_location_id = ['Choose where the stock is sent to.'];
    if (isGodownUser && myLocationId && String(header.from_location_id) !== String(myLocationId) && String(header.to_location_id) !== String(myLocationId)) {
      clientErrors.from_location_id = ['You can only move stock into or out of your own godown.'];
    }
    const totals = {};
    lines.filter((l) => l.product_id).forEach((l) => { totals[l.product_id] = (totals[l.product_id] ?? 0) + (Number(l.qty) || 0); });
    Object.entries(totals).forEach(([productId, qty]) => {
      const have = available(productId);
      if (have != null && qty > have + 0.0001) {
        const prod = products.find((p) => p.id === productId);
        clientErrors.items = [`Not enough stock of ${prod?.name ?? 'product'} at ${from?.name ?? 'the sender'}: ${have} available, ${qty} requested.`];
      }
    });
    if (!Object.keys(totals).length) clientErrors.items = ['Add at least one product.'];
    if (Object.keys(clientErrors).length) { setErrors(clientErrors); return; }

    if (confirm && isSuperAdmin) {
      const ok = await confirmDialog({
        title: 'Confirm transfer',
        message: `Approve moving stock from ${from?.name} to ${to?.name}? This skips the pending-request step.`,
        confirmLabel: 'Confirm transfer',
        tone: 'default',
      });
      if (!ok) return;
    }

    setSaving(true);
    try {
      const res = await api.post('/transfers', {
        transfer_type: 'intra_company',
        from_location_id: header.from_location_id,
        to_location_id: header.to_location_id,
        transfer_date: header.transfer_date,
        notes: header.notes || null,
        confirm: Boolean(confirm && isSuperAdmin),
        items: lines.filter((l) => l.product_id).map((l) => ({ product_id: l.product_id, qty: Number(l.qty) || 0 })),
      }, companyCfg);
      const created = res.data.data;
      navigate(created.company_id ? `/transfers/${created.id}?company_id=${created.company_id}` : `/transfers/${created.id}`);
    } catch (e) {
      setErrors(e.response?.data?.errors ?? { _: [e.response?.data?.message ?? 'Could not save transfer.'] });
    } finally { setSaving(false); }
  }

  if (isLoading) return <div className="flex h-full items-center justify-center"><Spinner className="size-6" /></div>;
  const needTwo = locations.length < 2;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="page-title">New transfer</h1>
          <p className="text-sm text-muted">Move stock between the branches and godowns of {activeCompany?.name ?? 'your company'}. Godown to branch, branch to godown, or branch to branch.</p>
        </div>
        <Button variant="outline" size="sm" onClick={() => navigate('/transfers')}><ArrowLeftIcon className="size-4" /> Back</Button>
      </div>

      {errors._ && <div className="rounded-xl bg-danger-soft px-4 py-3 text-sm text-danger">{errors._[0]}</div>}

      <Card className="p-5">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Field label="From" required error={err('from_location_id')}>
            <Select value={header.from_location_id} onChange={(e) => setHeader((h) => ({ ...h, from_location_id: e.target.value }))} className={selectCls}>
              <option value="">Select…</option>
              {fromOptions.map((l) => <option key={l.id} value={l.id}>{l.name} ({typeLabel(l.type)})</option>)}
            </Select>
          </Field>
          <Field label="To" required error={err('to_location_id')}>
            <Select value={header.to_location_id} onChange={(e) => setHeader((h) => ({ ...h, to_location_id: e.target.value }))} className={selectCls}>
              <option value="">Select…</option>
              {toOptions.map((l) => <option key={l.id} value={l.id}>{l.name} ({typeLabel(l.type)})</option>)}
            </Select>
            {from?.type === 'godown' && <p className="mt-1 text-xs text-muted">A godown sends to a branch.</p>}
          </Field>
          <Field label="Date" required error={err('transfer_date')}>
            <Input type="date" value={header.transfer_date} onChange={(e) => setHeader((h) => ({ ...h, transfer_date: e.target.value }))} />
          </Field>
        </div>
      </Card>

      <Card className="overflow-hidden">
        <table className="w-full text-sm">
          <thead><tr className="border-b border-line text-left text-faint">
            <th className="microlabel px-3 py-2 font-semibold">Product</th>
            <th className="microlabel px-3 py-2 text-right font-semibold">At {from?.name ?? 'sender'}</th>
            <th className="microlabel px-3 py-2 text-right font-semibold">Qty</th>
            <th className="px-3 py-2" />
          </tr></thead>
          <tbody>
            {lines.map((line, i) => {
              const have = available(line.product_id);
              const over = line.product_id && have != null && (Number(line.qty) || 0) > have + 0.0001;
              return (
                <tr key={i} className="border-b border-line/60 last:border-0">
                  <td className="px-3 py-2">
                    <SearchSelect value={line.product_id} onChange={(v) => setLine(i, { product_id: v })} options={productOptions} placeholder="Select product…" emptyLabel="No products in this company" />
                  </td>
                  <td className="tnum px-3 py-2 text-right text-muted">{line.product_id ? have : '—'}</td>
                  <td className="px-3 py-2">
                    <input type="number" step="0.001" min="0" max={have != null ? have : undefined} className={numInput} value={line.qty} onChange={(e) => setLine(i, { qty: e.target.value })} />
                    {over && <p className="mt-1 text-xs text-danger">Exceeds available ({have})</p>}
                  </td>
                  <td className="px-3 py-2"><button onClick={() => setLines((p) => (p.length === 1 ? p : p.filter((_, idx) => idx !== i)))} className="rounded-md p-1.5 text-muted hover:bg-paper hover:text-danger" aria-label="Remove line"><TrashIcon className="size-4" /></button></td>
                </tr>
              );
            })}
          </tbody>
        </table>
        <div className="border-t border-line px-3 py-2">
          <Button variant="ghost" size="sm" onClick={() => setLines((p) => [...p, emptyLine()])}><PlusIcon className="size-4" /> Add product</Button>
          {err('items') && <span className="ml-2 text-xs text-danger">{err('items')}</span>}
        </div>
      </Card>

      {isSuperAdmin && (
        <p className="text-right text-xs text-muted">
          <b>Save request</b> keeps it waiting for approval (nothing moves yet). <b>Confirm transfer</b> approves it now so it can be dispatched.
        </p>
      )}
      <div className="flex flex-wrap items-center justify-end gap-3">
        <Input value={header.notes} onChange={(e) => setHeader((h) => ({ ...h, notes: e.target.value }))} placeholder="Notes (optional)" className="max-w-xs" />
        {isSuperAdmin ? (
          <>
            <Button variant="outline" onClick={() => save({ confirm: false })} disabled={saving || needTwo}>{saving ? <Spinner /> : 'Save request'}</Button>
            <Button onClick={() => save({ confirm: true })} disabled={saving || needTwo}>{saving ? <Spinner className="border-white/40 border-t-white" /> : 'Confirm transfer'}</Button>
          </>
        ) : (
          <Button onClick={() => save()} disabled={saving || needTwo}>{saving ? <Spinner className="border-white/40 border-t-white" /> : 'Save transfer'}</Button>
        )}
      </div>
      {needTwo && <p className="text-sm text-muted">Add at least two locations (a branch and a godown, or two branches) under Setup → Branches &amp; Godowns before moving stock.</p>}
    </div>
  );
}
