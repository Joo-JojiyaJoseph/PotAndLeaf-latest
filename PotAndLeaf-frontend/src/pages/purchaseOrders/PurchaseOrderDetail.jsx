import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PaperAirplaneIcon, XCircleIcon, ArrowRightCircleIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { recordDetailPath } from '../../lib/recordCompany';
import { Badge, Button } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatCurrency, formatDate } from '../../lib/format';

const tone = { draft: 'inactive', sent: 'submitted', received: 'active', cancelled: 'blocked' };

export default function PurchaseOrderDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { companyId } = useAuth();
  const queryClient = useQueryClient();
  const headerCompanyId = searchParams.get('company_id') || companyId;

  const { data, isLoading, isError } = useQuery({
    queryKey: ['purchase-order', headerCompanyId, id],
    queryFn: () => api.get(`/purchase-orders/${id}`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: Boolean(headerCompanyId && id),
  });

  const recordCompanyId = data?.company_id ?? headerCompanyId;
  const recordCtx = { filterCompanyId: headerCompanyId, companyId };

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['purchase-order', headerCompanyId, id] });
    queryClient.invalidateQueries({ queryKey: ['purchase-orders'] });
  };
  const sendM = useMutation({ mutationFn: () => api.post(`/purchase-orders/${id}/send`, {}, withCompany(recordCompanyId)), onSuccess: invalidate });
  const cancelM = useMutation({ mutationFn: () => api.delete(`/purchase-orders/${id}`, withCompany(recordCompanyId)), onSuccess: invalidate });
  const convertM = useMutation({
    mutationFn: () => api.post(`/purchase-orders/${id}/convert`, {}, withCompany(recordCompanyId)),
    onSuccess: (res) => {
      invalidate();
      const pid = res.data.data?.purchase_id;
      if (pid) navigate(recordDetailPath('/purchases', { id: pid, company_id: recordCompanyId }, recordCtx));
    },
  });

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/purchase-orders" />;
  const po = data;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={`PO ${po.po_no}`}
        subtitle={`${po.supplier_name} · ${formatDate(po.po_date)}`}
        backTo="/purchase-orders"
        actions={<>
          <Badge tone={tone[po.status] ?? 'default'}>{po.status}</Badge>
          {po.can?.cancel && <Button variant="ghost" size="sm" onClick={() => cancelM.mutate()} disabled={cancelM.isPending}><XCircleIcon className="size-4" /> Cancel</Button>}
          {po.can?.send && <Button variant="outline" size="sm" onClick={() => sendM.mutate()} disabled={sendM.isPending}><PaperAirplaneIcon className="size-4" /> Mark sent</Button>}
          {po.can?.convert && <Button size="sm" onClick={() => convertM.mutate()} disabled={convertM.isPending}><ArrowRightCircleIcon className="size-4" /> Convert to GRN</Button>}
          {po.purchase_id && <Button variant="outline" size="sm" onClick={() => navigate(recordDetailPath('/purchases', { id: po.purchase_id, company_id: recordCompanyId }, recordCtx))}>View GRN</Button>}
        </>}
      />

      <Section title="Details">
        <InfoGrid cols={4}>
          <InfoItem label="Supplier" value={po.supplier_name} />
          <InfoItem label="PO date" value={formatDate(po.po_date)} />
          <InfoItem label="Expected" value={po.expected_date ? formatDate(po.expected_date) : null} />
          <InfoItem label="Est. total" value={formatCurrency(po.grand_total)} mono />
          <InfoItem label="Notes" value={po.notes} />
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
              {(po.items ?? []).map((it) => (
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
              <tr className="border-t border-line"><td colSpan="4" /><td className="px-3 py-1.5 text-right text-muted">Subtotal</td><td className="tnum py-1.5 pl-3 text-right">{formatCurrency(po.subtotal)}</td></tr>
              <tr><td colSpan="4" /><td className="px-3 py-1.5 text-right text-muted">Tax</td><td className="tnum py-1.5 pl-3 text-right">{formatCurrency(po.tax_total)}</td></tr>
              <tr><td colSpan="4" /><td className="px-3 py-1.5 text-right font-semibold">Grand total</td><td className="tnum py-1.5 pl-3 text-right font-semibold">{formatCurrency(po.grand_total)}</td></tr>
            </tfoot>
          </table>
        </div>
      </Section>
    </div>
  );
}
