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

const statusTone = { draft: 'inactive', in_progress: 'warning', completed: 'active', cancelled: 'blocked' };

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

  const estimateQ = useQuery({
    queryKey: ['production-estimate', recordCompanyId, data?.bom_id, data?.output_quantity],
    queryFn: () => api.get('/production/estimate', {
      params: { bom_id: data.bom_id, output_quantity: data.output_quantity },
      ...withCompany(recordCompanyId),
    }).then((r) => r.data.data),
    enabled: Boolean(recordCompanyId && ['draft', 'in_progress'].includes(data?.status) && data?.bom_id && data?.output_quantity),
  });

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
  const startStageM = useMutation({
    mutationFn: (stageId) => api.post(`/production/orders/${id}/stages/${stageId}/start`, {}, withCompany(recordCompanyId)),
    onSuccess: () => { invalidate(); toast.success('Stage started.'); },
    onError: (err) => toast.error(err.response?.data?.message ?? err.response?.data?.errors?.stage?.[0] ?? 'Could not start stage.'),
  });
  const completeStageM = useMutation({
    mutationFn: (stageId) => api.post(`/production/orders/${id}/stages/${stageId}/complete`, {}, withCompany(recordCompanyId)),
    onSuccess: (res) => {
      invalidate();
      toast.success(res.data?.message ?? 'Stage completed.');
      if (res.data?.data?.status === 'completed') toast.success('Production completed — stock updated.');
    },
    onError: (err) => toast.error(err.response?.data?.message ?? err.response?.data?.errors?.items?.[0] ?? 'Could not complete stage.'),
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
          <InfoItem label="Supervisor" value={o.supervisor || '—'} />
          <InfoItem label="Location" value={o.location || '—'} />
          <InfoItem label="Completed" value={o.completed_at ? formatDate(o.completed_at) : null} />
          <InfoItem label="Notes" value={o.notes} />
        </InfoGrid>
      </Section>

      {o.is_multi_stage && (o.stages?.length ?? 0) > 0 && o.status !== 'cancelled' && (
        <Section title="Production pipeline">
          <div className="space-y-3">
            {o.stages.map((stage, index) => (
              <div key={stage.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-paper/40 p-4">
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="microlabel font-semibold text-faint">Step {index + 1}</span>
                    <span className="font-medium">{stage.name}</span>
                    <Badge tone={stage.status === 'completed' ? 'active' : stage.status === 'in_progress' ? 'warning' : 'inactive'}>{stage.status.replace('_', ' ')}</Badge>
                  </div>
                  <div className="mt-1 flex flex-wrap gap-3 text-xs text-muted">
                    {stage.started_at && <span>Started {formatDate(stage.started_at)}</span>}
                    {stage.completed_at && <span>Completed {formatDate(stage.completed_at)}</span>}
                    {stage.material_cost > 0 && <span>Material cost {formatCurrency(stage.material_cost)}</span>}
                  </div>
                </div>
                <div className="flex gap-2">
                  {stage.can?.start && (
                    <Button size="sm" variant="outline" onClick={() => startStageM.mutate(stage.id)} disabled={startStageM.isPending}>
                      Start
                    </Button>
                  )}
                  {stage.can?.complete && (
                    <Button size="sm" onClick={() => completeStageM.mutate(stage.id)} disabled={completeStageM.isPending}>
                      Complete stage
                    </Button>
                  )}
                </div>
              </div>
            ))}
          </div>
        </Section>
      )}

      {o.is_multi_stage && ['draft', 'in_progress'].includes(o.status) && estimateQ.data && (
        <Section title="Estimated cost (all stages)">
          <div className="flex flex-wrap gap-4 text-sm">
            <span>Material total: <strong className="tnum">{formatCurrency(estimateQ.data.total_material_cost)}</strong></span>
            <span>Unit cost: <strong className="tnum">{formatCurrency(estimateQ.data.unit_cost)}</strong></span>
            {!estimateQ.data.can_complete && <span className="text-danger">Insufficient stock across one or more stages</span>}
          </div>
        </Section>
      )}

      {o.status === 'draft' && !o.is_multi_stage && estimateQ.data && (
        <Section title="Estimated cost (before complete)">
          <div className="mb-3 flex flex-wrap gap-4 text-sm">
            <span>Material total: <strong className="tnum">{formatCurrency(estimateQ.data.total_material_cost)}</strong></span>
            <span>Unit cost: <strong className="tnum">{formatCurrency(estimateQ.data.unit_cost)}</strong></span>
            {!estimateQ.data.can_complete && <span className="text-danger">Insufficient stock — cannot complete yet</span>}
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel py-2 pr-3 font-semibold">Component</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Required</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Available</th>
                <th className="microlabel py-2 pl-3 text-right font-semibold">Line cost</th>
              </tr></thead>
              <tbody>
                {(estimateQ.data.items ?? []).map((it) => (
                  <tr key={it.product_id} className="border-b border-line/60 last:border-0">
                    <td className="py-2 pr-3 font-medium">{it.product_name}{it.wastage_pct > 0 ? ` (+${it.wastage_pct}% wastage)` : ''}</td>
                    <td className={`tnum px-3 py-2 text-right ${it.sufficient ? 'text-muted' : 'text-danger'}`}>{it.required_qty}</td>
                    <td className="tnum px-3 py-2 text-right text-muted">{it.available_stock}</td>
                    <td className="tnum py-2 pl-3 text-right">{formatCurrency(it.line_cost)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Section>
      )}

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
