import { useParams, Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, XCircleIcon, PrinterIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { Badge, Button, Card } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatCurrency, formatDate } from '../../lib/format';
import { Barcode, printBarcodeLabel } from '../../components/Barcode';
import { printBarcodeSheet } from '../../lib/barcodeSheet';
import useSubmitLock from '../../hooks/useSubmitLock';

const statusTone = { draft: 'inactive', confirmed: 'active', cancelled: 'blocked' };

/** Build printable labels for one split row (product batch + unit barcodes). */
function labelsForSplitItem(it) {
  const labels = [];
  const name = it.product_name;
  const price = it.retail_price;

  if (it.batch_barcode) {
    const copies = Math.min(Math.max(Math.round(Number(it.qty) || 1), 1), 500);
    for (let i = 0; i < copies; i++) {
      labels.push({ name, barcode: it.batch_barcode, price });
    }
  } else if (it.barcode) {
    labels.push({ name, barcode: it.barcode, price });
  }

  (it.units ?? []).forEach((u) => {
    if (u.barcode && u.barcode !== it.batch_barcode && u.barcode !== it.barcode) {
      labels.push({ name, barcode: u.barcode, price });
    }
  });

  if (labels.length === 0 && (it.units ?? []).length > 0) {
    (it.units ?? []).forEach((u) => labels.push({ name, barcode: u.barcode, price }));
  }

  return labels;
}

export default function BulkSplitDetail() {
  const { id } = useParams();
  const queryClient = useQueryClient();
  const { data, isLoading, isError } = useQuery({
    queryKey: ['bulk-split', id],
    queryFn: () => api.get(`/bulk-splits/${id}`).then((r) => r.data.data),
  });
  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['bulk-split', id] });
    queryClient.invalidateQueries({ queryKey: ['bulk-splits'] });
    queryClient.invalidateQueries({ queryKey: ['inventory'] });
    queryClient.invalidateQueries({ queryKey: ['products'] });
  };
  const confirmM = useMutation({ mutationFn: () => api.post(`/bulk-splits/${id}/confirm`), onSuccess: invalidate });
  const cancelM = useMutation({ mutationFn: () => api.delete(`/bulk-splits/${id}`), onSuccess: invalidate });
  const { submit, release, locked } = useSubmitLock(confirmM.isPending || cancelM.isPending);

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/bulk-splits" />;
  const s = data;
  const items = s.items ?? [];

  const allLabels = items.flatMap(labelsForSplitItem);
  const printAll = () => { if (allLabels.length) printBarcodeSheet(allLabels); };
  const printSplit = (it) => {
    const labels = labelsForSplitItem(it);
    if (labels.length) printBarcodeSheet(labels);
  };

  const allocated = s.split_total_qty ?? items.reduce((sum, it) => sum + (Number(it.qty) || 0), 0);
  const remaining = s.remaining_qty ?? Math.max(0, (Number(s.source_qty) || 0) - allocated);

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={`Split ${s.split_no}`}
        subtitle={`${s.source_product_name} · ${formatDate(s.split_date)}`}
        backTo="/bulk-splits"
        actions={
          <>
            <Badge tone={statusTone[s.status] ?? 'default'}>{s.status}</Badge>
            {s.status === 'confirmed' && allLabels.length > 0 && (
              <Button variant="outline" size="sm" onClick={printAll}>
                <PrinterIcon className="size-4" /> Print all labels
              </Button>
            )}
            {s.can?.cancel && (
              <Button variant="ghost" size="sm" onClick={() => submit(() => cancelM.mutate(undefined, { onSettled: release }))} disabled={locked}>
                <XCircleIcon className="size-4" /> Cancel
              </Button>
            )}
            {s.can?.confirm && (
              <Button size="sm" onClick={() => submit(() => confirmM.mutate(undefined, { onSettled: release }))} disabled={locked}>
                <CheckCircleIcon className="size-4" /> Confirm split
              </Button>
            )}
          </>
        }
      />

      <Card className="border-leaf/20 bg-leaf-soft/30 p-4">
        <div className="flex flex-wrap gap-6 text-sm tabular-nums">
          <span><span className="text-muted">Available:</span> <strong className="text-ink">{s.source_qty}</strong></span>
          <span><span className="text-muted">Allocated:</span> <strong className="text-ink">{allocated}</strong></span>
          <span><span className="text-muted">Remaining:</span> <strong className={remaining > 0 ? 'text-amber-600' : 'text-leaf'}>{remaining}</strong></span>
        </div>
      </Card>

      <Section title="Source">
        <InfoGrid cols={4}>
          <InfoItem label="Bulk product" value={s.source_product_name} />
          <InfoItem label="Available qty" value={s.source_qty} mono />
          <InfoItem label="Unit cost" value={formatCurrency(s.source_unit_cost)} mono />
          <InfoItem label="Cost allocated" value={formatCurrency(s.total_cost)} mono />
          {s.split_mode && <InfoItem label="Split mode" value={s.split_mode.replace(/_/g, ' ')} />}
          {s.confirmed_at && <InfoItem label="Confirmed" value={new Date(s.confirmed_at).toLocaleString()} />}
        </InfoGrid>
        {s.notes && <div className="mt-4 border-t border-line pt-4"><InfoItem label="Notes" value={s.notes} /></div>}
      </Section>

      <Section
        title={`Split products (${items.length})`}
        actions={
          s.status === 'confirmed' && allLabels.length > 0 ? (
            <Button variant="outline" size="sm" onClick={printAll}>
              <PrinterIcon className="size-4" /> Print all labels
            </Button>
          ) : null
        }
      >
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-sm">
            <thead>
              <tr className="border-b border-line text-left text-faint">
                <th className="microlabel py-2 pr-3 font-semibold">Split #</th>
                <th className="microlabel px-3 py-2 font-semibold">Product</th>
                <th className="microlabel px-3 py-2 font-semibold">Batch</th>
                <th className="microlabel px-3 py-2 font-semibold">SKU / Barcode</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Qty</th>
                <th className="microlabel px-3 py-2 font-semibold">Unit</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Unit cost</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Retail</th>
                <th className="microlabel px-3 py-2 font-semibold">Status</th>
                <th className="microlabel py-2 pl-3 font-semibold">Print</th>
              </tr>
            </thead>
            <tbody>
              {items.map((it) => (
                <tr key={it.id} className="border-b border-line/60 last:border-0 align-top">
                  <td className="py-3 pr-3 text-muted">{it.split_label || it.split_sequence || '—'}</td>
                  <td className="px-3 py-3 font-medium">
                    {it.product_id && s.status === 'confirmed' ? (
                      <Link to={`/products/${it.product_id}`} className="text-leaf hover:underline">{it.product_name}</Link>
                    ) : it.product_name}
                  </td>
                  <td className="px-3 py-3 font-mono text-xs text-muted">{it.batch_no || '—'}</td>
                  <td className="px-3 py-3">
                    <div className="font-mono text-xs text-muted">{it.sku || '—'}</div>
                    {(it.batch_barcode || it.barcode) && (
                      <div className="mt-1 max-w-[140px]"><Barcode value={it.batch_barcode || it.barcode} height={32} /></div>
                    )}
                  </td>
                  <td className="tnum px-3 py-3 text-right">{it.qty}</td>
                  <td className="px-3 py-3 text-muted">{it.unit_name || '—'}</td>
                  <td className="tnum px-3 py-3 text-right">{formatCurrency(it.unit_cost)}</td>
                  <td className="tnum px-3 py-3 text-right">{formatCurrency(it.retail_price)}</td>
                  <td className="px-3 py-3">
                    <Badge tone={it.batch_status === 'active' || s.status === 'draft' ? 'active' : 'inactive'}>
                      {it.batch_status || s.status}
                    </Badge>
                  </td>
                  <td className="py-3 pl-3">
                    {s.status === 'confirmed' && (
                      <Button variant="outline" size="sm" onClick={() => printSplit(it)}>
                        <PrinterIcon className="size-4" /> Print label
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Section>
      
{/* 
      {s.status === 'confirmed' && items.some((it) => (it.units?.length ?? 0) > 0) && (
        <Section title="Unit barcodes (all)">
          <div className="space-y-8">
            {items.map((it) => (
              <div key={`units-${it.id}`}>
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <span className="font-medium text-ink">{it.product_name}</span>
                    <span className="ml-2 text-xs text-muted">{it.units?.length ?? 0} unit barcode(s)</span>
                  </div>
                  <Button variant="outline" size="sm" onClick={() => printSplit(it)}>
                    <PrinterIcon className="size-4" /> Print label
                  </Button>
                </div>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                  {(it.units ?? []).map((u) => (
                    <Card key={u.id} className="flex flex-col items-center gap-2 p-3">
                      <div className="text-xs text-muted">Unit {u.unit_no}</div>
                      <div className="rounded-lg bg-white p-2"><Barcode value={u.barcode} height={38} /></div>
                      <button
                        type="button"
                        onClick={() => printBarcodeLabel({ barcode: u.barcode, name: it.product_name, price: it.retail_price })}
                        className="text-xs font-medium text-leaf hover:underline"
                      >
                        Print
                      </button>
                    </Card>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </Section>
      )} */}
    </div>
  );
}
