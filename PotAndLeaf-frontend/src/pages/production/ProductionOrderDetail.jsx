import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, XCircleIcon, PrinterIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../lib/toast';
import { Badge, Button, Card } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatCurrency, formatDate } from '../../lib/format';
import { Barcode, printBarcodeLabel } from '../../components/Barcode';
import { printBarcodeSheet } from '../../lib/barcodeSheet';

const statusTone = { draft: 'inactive', completed: 'active', cancelled: 'blocked' };

export default function ProductionOrderDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { companyId } = useAuth();
  const toast = useToast();
  const queryClient = useQueryClient();
  const headerCompanyId = searchParams.get('company_id') || companyId;

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['production-order', headerCompanyId, id],
    queryFn: () => api.get(`/production/orders/${id}`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: Boolean(headerCompanyId && id),
  });

  const recordCompanyId = data?.company_id ?? headerCompanyId;

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['production-order', headerCompanyId, id] });
    queryClient.invalidateQueries({ queryKey: ['production-orders'] });
    queryClient.invalidateQueries({ queryKey: ['inventory'] });
  };
  const completeM = useMutation({
    mutationFn: () => api.post(`/production/orders/${id}/complete`, {}, withCompany(recordCompanyId)),
    onSuccess: () => { invalidate(); toast.success('Production completed — stock updated.'); },
    onError: (err) => toast.error(err.response?.data?.message ?? 'Could not complete production.'),
  });
  const cancelM = useMutation({
    mutationFn: () => api.delete(`/production/orders/${id}`, withCompany(recordCompanyId)),
    onSuccess: () => { invalidate(); toast.success('Production order cancelled.'); navigate('/production'); },
    onError: (err) => toast.error(err.response?.data?.message ?? 'Could not cancel production order.'),
  });

  if (isLoading) return <DetailLoading />;
  if (isError || !data) {
    const msg = error?.response?.data?.message;
    return (
      <div className="p-6">
        <DetailError backTo="/production" />
        {msg && <p className="mt-2 text-center text-xs text-muted">{msg}</p>}
      </div>
    );
  }
  const o = data;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={`Production ${o.order_no}`}
        subtitle={`${o.output_quantity} × ${o.output_product} · ${formatDate(o.order_date)}`}
        backTo="/production"
        actions={<>
          <Badge tone={statusTone[o.status] ?? 'default'}>{o.status}</Badge>
          {o.can?.cancel && <Button variant="ghost" size="sm" onClick={() => cancelM.mutate()} disabled={cancelM.isPending}><XCircleIcon className="size-4" /> Cancel</Button>}
          {o.can?.complete && <Button size="sm" onClick={() => completeM.mutate()} disabled={completeM.isPending}><CheckCircleIcon className="size-4" /> Complete</Button>}
        </>}
      />

      <Section title="Details">
        <InfoGrid cols={4}>
          <InfoItem label="Recipe" value={o.bom_name} />
          <InfoItem label="Output product" value={o.output_product} />
          <InfoItem label="Output quantity" value={o.output_quantity} />
          <InfoItem label="Unit cost" value={o.status === 'completed' ? formatCurrency(o.output_unit_cost) : '—'} mono />
          <InfoItem label="Total input cost" value={o.status === 'completed' ? formatCurrency(o.total_input_cost) : '—'} mono />
          <InfoItem label="Completed" value={o.completed_at ? formatDate(o.completed_at) : null} />
          <InfoItem label="Notes" value={o.notes} />
        </InfoGrid>
      </Section>

      {o.status === 'completed' && (o.barcodes?.length ?? 0) > 0 && (
        <Section
          title="Finished product barcode"
          actions={
            <Button variant="outline" size="sm" onClick={() => {
              const labels = [];
              o.barcodes.forEach((b) => {
                const copies = Math.min(Math.max(Math.round(Number(b.qty) || 1), 1), 200);
                for (let i = 0; i < copies; i++) labels.push({ name: o.output_product, barcode: b.barcode });
              });
              if (labels.length) printBarcodeSheet(labels);
            }}>
              <PrinterIcon className="size-4" /> Print all labels
            </Button>
          }
        >
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {o.barcodes.map((b) => (
              <Card key={b.id} className="flex flex-col gap-3 p-4">
                <div className="min-w-0">
                  <div className="truncate font-medium text-ink">{o.output_product}</div>
                  <div className="microlabel text-faint">Qty {b.qty}</div>
                </div>
                <div className="flex items-center justify-center rounded-xl bg-white p-3"><Barcode value={b.barcode} height={48} /></div>
                <Button variant="outline" size="sm" className="self-center" onClick={() => printBarcodeLabel({ barcode: b.barcode, name: o.output_product })}>
                  <PrinterIcon className="size-4" /> Print label
                </Button>
              </Card>
            ))}
          </div>
        </Section>
      )}

      {o.status === 'completed' && (o.items?.length ?? 0) > 0 && (
        <Section title="Materials consumed">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel py-2 pr-3 font-semibold">Component</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Qty</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Unit cost</th>
                <th className="microlabel py-2 pl-3 text-right font-semibold">Line cost</th>
              </tr></thead>
              <tbody>
                {o.items.map((it) => (
                  <tr key={it.id} className="border-b border-line/60 last:border-0">
                    <td className="py-2 pr-3 font-medium">{it.product_name}</td>
                    <td className="tnum px-3 py-2 text-right text-muted">{it.qty}</td>
                    <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(it.unit_cost)}</td>
                    <td className="tnum py-2 pl-3 text-right font-medium">{formatCurrency(it.line_cost)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Section>
      )}
    </div>
  );
}
