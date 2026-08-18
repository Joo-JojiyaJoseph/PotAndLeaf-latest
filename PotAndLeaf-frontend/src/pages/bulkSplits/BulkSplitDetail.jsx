import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, XCircleIcon, PrinterIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { Badge, Button, Card } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatCurrency, formatDate } from '../../lib/format';
import { Barcode, printBarcodeLabel } from '../../components/Barcode';
import { printBarcodeSheet } from '../../lib/barcodeSheet';

const statusTone = { draft: 'inactive', confirmed: 'active', cancelled: 'blocked' };

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
  };
  const confirmM = useMutation({ mutationFn: () => api.post(`/bulk-splits/${id}/confirm`), onSuccess: invalidate });
  const cancelM = useMutation({ mutationFn: () => api.delete(`/bulk-splits/${id}`), onSuccess: invalidate });

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/bulk-splits" />;
  const s = data;

  const itemsWithUnits = (s.items ?? []).filter((it) => (it.units?.length ?? 0) > 0);
  const labelsFor = (it) => (it.units ?? []).map((u) => ({ name: it.product_name, barcode: u.barcode, price: it.retail_price }));
  const printProduct = (it) => { const l = labelsFor(it); if (l.length) printBarcodeSheet(l); };
  const printAll = () => { const l = itemsWithUnits.flatMap(labelsFor); if (l.length) printBarcodeSheet(l); };

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={`Split ${s.split_no}`}
        subtitle={`${s.source_product_name} · ${formatDate(s.split_date)}`}
        backTo="/bulk-splits"
        actions={
          <>
            <Badge tone={statusTone[s.status] ?? 'default'}>{s.status}</Badge>
            {s.can?.cancel && <Button variant="ghost" size="sm" onClick={() => cancelM.mutate()} disabled={cancelM.isPending}><XCircleIcon className="size-4" /> Cancel</Button>}
            {s.can?.confirm && <Button size="sm" onClick={() => confirmM.mutate()} disabled={confirmM.isPending}><CheckCircleIcon className="size-4" /> Confirm</Button>}
          </>
        }
      />

      <Section title="Source">
        <InfoGrid cols={4}>
          <InfoItem label="Bulk product" value={s.source_product_name} />
          <InfoItem label="Quantity" value={s.source_qty} mono />
          <InfoItem label="Unit cost" value={formatCurrency(s.source_unit_cost)} mono />
          <InfoItem label="Total cost redistributed" value={formatCurrency(s.total_cost)} mono />
        </InfoGrid>
        {s.notes && <div className="mt-4 border-t border-line pt-4"><InfoItem label="Notes" value={s.notes} /></div>}
      </Section>

      <Section title="Output units">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[480px] text-sm">
            <thead>
              <tr className="border-b border-line text-left text-faint">
                <th className="microlabel py-2 pr-3 font-semibold">Product</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Qty</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Weight</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Cost share</th>
                <th className="microlabel py-2 pl-3 text-right font-semibold">Unit cost</th>
              </tr>
            </thead>
            <tbody>
              {(s.items ?? []).map((it) => (
                <tr key={it.id} className="border-b border-line/60 last:border-0">
                  <td className="py-2 pr-3 font-medium">{it.product_name}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.qty}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.weight}</td>
                  <td className="tnum px-3 py-2 text-right">{formatCurrency(it.cost_alloc)}</td>
                  <td className="tnum py-2 pl-3 text-right font-medium">{formatCurrency(it.unit_cost)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Section>

      {s.status === 'confirmed' && itemsWithUnits.length > 0 && (
        <Section
          title="Unit barcodes"
          actions={<Button variant="outline" size="sm" onClick={printAll}><PrinterIcon className="size-4" /> Print all labels</Button>}
        >
          <div className="space-y-6">
            {itemsWithUnits.map((it) => (
              <div key={it.id}>
                <div className="mb-2 flex items-center justify-between gap-3">
                  <div className="font-medium text-ink">
                    {it.product_name}
                    <span className="ml-1 text-xs text-muted">· {it.units.length} unit{it.units.length > 1 ? 's' : ''}</span>
                  </div>
                  <Button variant="outline" size="sm" onClick={() => printProduct(it)}>
                    <PrinterIcon className="size-4" /> Print {it.units.length} label{it.units.length > 1 ? 's' : ''}
                  </Button>
                </div>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                  {it.units.slice(0, 12).map((u) => (
                    <Card key={u.id} className="flex flex-col items-center gap-2 p-3">
                      <div className="rounded-lg bg-white p-2"><Barcode value={u.barcode} height={40} /></div>
                      <button
                        onClick={() => printBarcodeLabel({ barcode: u.barcode, name: it.product_name, price: it.retail_price })}
                        className="text-xs font-medium text-leaf-hover hover:underline"
                      >
                        Print
                      </button>
                    </Card>
                  ))}
                </div>
                {it.units.length > 12 && (
                  <p className="mt-2 text-xs text-muted">Showing 12 of {it.units.length}. Use “Print {it.units.length} labels” for the full set.</p>
                )}
              </div>
            ))}
          </div>
        </Section>
      )}
    </div>
  );
}
