import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, XCircleIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { Badge, Button, Card } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatCurrency, formatDate } from '../../lib/format';

const statusTone = { draft: 'inactive', confirmed: 'active', cancelled: 'blocked' };

export default function PurchaseReturnDetail() {
  const { id } = useParams();
  const queryClient = useQueryClient();
  const { data, isLoading, isError } = useQuery({
    queryKey: ['purchase-return', id],
    queryFn: () => api.get(`/purchase-returns/${id}`).then((r) => r.data.data),
  });
  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['purchase-return', id] });
    queryClient.invalidateQueries({ queryKey: ['purchase-returns'] });
    queryClient.invalidateQueries({ queryKey: ['inventory'] });
  };
  const confirmM = useMutation({ mutationFn: () => api.post(`/purchase-returns/${id}/confirm`), onSuccess: invalidate });
  const cancelM = useMutation({ mutationFn: () => api.delete(`/purchase-returns/${id}`), onSuccess: invalidate });

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/purchase-returns" />;
  const r = data;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={`Return ${r.return_no}`}
        subtitle={`${r.supplier?.name ?? 'Supplier'} · ${formatDate(r.return_date)}`}
        backTo="/purchase-returns"
        actions={
          <>
            <Badge tone={statusTone[r.status] ?? 'default'}>{r.status}</Badge>
            {r.can?.cancel && <Button variant="ghost" size="sm" onClick={() => cancelM.mutate()} disabled={cancelM.isPending}><XCircleIcon className="size-4" /> Cancel</Button>}
            {r.can?.confirm && <Button size="sm" onClick={() => confirmM.mutate()} disabled={confirmM.isPending}><CheckCircleIcon className="size-4" /> Confirm</Button>}
          </>
        }
      />

      <Section title="Details">
        <InfoGrid cols={4}>
          <InfoItem label="Against purchase" value={r.purchase?.purchase_no} mono />
          <InfoItem label="Supplier" value={r.supplier?.name} />
          <InfoItem label="Tax type" value={r.is_interstate ? 'Inter-state (IGST)' : 'Intra-state (CGST + SGST)'} />
          <InfoItem label="Reason" value={r.reason} />
          {r.rejection_reason && <InfoItem label="Rejection reason" value={r.rejection_reason} />}
          <InfoItem label="Notes" value={r.notes} />
        </InfoGrid>
      </Section>

      <Section title="Returned items">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[560px] text-sm">
            <thead>
              <tr className="border-b border-line text-left text-faint">
                <th className="microlabel py-2 pr-3 font-semibold">Product</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Qty</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Rate</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">GST</th>
                <th className="microlabel py-2 pl-3 text-right font-semibold">Line total</th>
              </tr>
            </thead>
            <tbody>
              {(r.items ?? []).map((it) => (
                <tr key={it.id} className="border-b border-line/60 last:border-0">
                  <td className="py-2 pr-3 font-medium">{it.product_name}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.qty}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(it.rate)}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency((it.cgst_amount ?? 0) + (it.sgst_amount ?? 0) + (it.igst_amount ?? 0))} <span className="text-xs">({it.gst_rate}%)</span></td>
                  <td className="tnum py-2 pl-3 text-right font-medium">{formatCurrency(it.line_total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Section>

      <div className="flex justify-end">
        <Card className="w-full max-w-xs p-5">
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between"><dt className="text-muted">Taxable</dt><dd className="tnum">{formatCurrency(r.subtotal)}</dd></div>
            <div className="flex justify-between"><dt className="text-muted">{r.is_interstate ? 'IGST' : 'CGST + SGST'}</dt><dd className="tnum">{formatCurrency(r.tax_total)}</dd></div>
            <div className="mt-2 flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Debit note</dt><dd className="tnum">{formatCurrency(r.grand_total)}</dd></div>
          </dl>
        </Card>
      </div>
    </div>
  );
}
