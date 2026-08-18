import { useParams, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, XCircleIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { Badge, Button } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatCurrency, formatDate } from '../../lib/format';

const tone = { booked: 'submitted', fulfilled: 'active', cancelled: 'blocked' };

export default function AdvanceOrderDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { data, isLoading, isError } = useQuery({
    queryKey: ['advance-order', id],
    queryFn: () => api.get(`/advance-orders/${id}`).then((r) => r.data.data),
  });
  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['advance-order', id] });
    queryClient.invalidateQueries({ queryKey: ['advance-orders'] });
  };
  const cancelM = useMutation({ mutationFn: () => api.delete(`/advance-orders/${id}`), onSuccess: invalidate });
  const fulfillM = useMutation({
    mutationFn: () => api.post(`/advance-orders/${id}/fulfill`),
    onSuccess: (res) => { invalidate(); const sid = res.data.data?.sale_id; if (sid) navigate(`/sales/${sid}`); },
  });

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/advance-orders" />;
  const o = data;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={`Advance ${o.order_no}`}
        subtitle={`${o.customer_name} · ${formatDate(o.order_date)}`}
        backTo="/advance-orders"
        actions={<>
          <Badge tone={tone[o.status] ?? 'default'}>{o.status}</Badge>
          {o.can?.cancel && <Button variant="ghost" size="sm" onClick={() => cancelM.mutate()} disabled={cancelM.isPending}><XCircleIcon className="size-4" /> Cancel</Button>}
          {o.can?.fulfill && <Button size="sm" onClick={() => fulfillM.mutate()} disabled={fulfillM.isPending}><CheckCircleIcon className="size-4" /> Fulfil → sale</Button>}
          {o.sale_id && <Button variant="outline" size="sm" onClick={() => navigate(`/sales/${o.sale_id}`)}>View sale</Button>}
        </>}
      />

      <Section title="Details">
        <InfoGrid cols={4}>
          <InfoItem label="Customer" value={o.customer_name} />
          <InfoItem label="Expected" value={o.expected_date ? formatDate(o.expected_date) : null} />
          <InfoItem label="Advance paid" value={formatCurrency(o.advance_amount)} mono />
          <InfoItem label="Balance" value={formatCurrency(o.balance)} mono />
          <InfoItem label="Est. total" value={formatCurrency(o.grand_total)} mono />
          <InfoItem label="Notes" value={o.notes} />
        </InfoGrid>
      </Section>

      <Section title="Items">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-line text-left text-faint">
              <th className="microlabel py-2 pr-3 font-semibold">Product</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Qty</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Rate</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">GST %</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Taxable</th>
              <th className="microlabel py-2 pl-3 text-right font-semibold">Total</th>
            </tr></thead>
            <tbody>
              {(o.items ?? []).map((it) => (
                <tr key={it.id} className="border-b border-line/60 last:border-0">
                  <td className="py-2 pr-3 font-medium">{it.product_name}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.qty}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(it.rate)}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.gst_rate}%</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(it.taxable_value)}</td>
                  <td className="tnum py-2 pl-3 text-right font-medium">{formatCurrency(it.line_total)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="border-t border-line"><td colSpan="4" /><td className="px-3 py-1.5 text-right text-muted">Subtotal</td><td className="tnum py-1.5 pl-3 text-right">{formatCurrency(o.subtotal)}</td></tr>
              <tr><td colSpan="4" /><td className="px-3 py-1.5 text-right text-muted">Tax</td><td className="tnum py-1.5 pl-3 text-right">{formatCurrency(o.tax_total)}</td></tr>
              <tr><td colSpan="4" /><td className="px-3 py-1.5 text-right font-semibold">Grand total</td><td className="tnum py-1.5 pl-3 text-right font-semibold">{formatCurrency(o.grand_total)}</td></tr>
            </tfoot>
          </table>
        </div>
      </Section>
    </div>
  );
}
