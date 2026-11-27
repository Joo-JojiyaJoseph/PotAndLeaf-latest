import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, PencilSquareIcon, XCircleIcon, PrinterIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../lib/toast';
import { useConfirm } from '../../lib/confirm';
import { useState } from 'react';
import { Badge, Button, Card, Modal, Select } from '../../components/ui';
import PriceTiers from '../../components/PriceTiers';
import { apiMessage } from '../../lib/formErrors';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatCurrency, formatDate } from '../../lib/format';
import { Barcode, printBarcodeLabel } from '../../components/Barcode';
import { printBarcodeSheet } from '../../lib/barcodeSheet';

const statusTone = { draft: 'inactive', in_progress: 'warning', completed: 'active', cancelled: 'blocked' };

export default function ProductionOrderDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { companyId, isSuperAdmin } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
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
  // Final step: set the selling prices of the finished product, then complete.
  const [pricing, setPricing] = useState(null); // null = closed, 'complete' | 'edit'
  const [priceForm, setPriceForm] = useState({ cost_price: '', mrp: '', retail_price: '', wholesale_price: '', dealer_price: '' });
  const openPricing = (mode) => {
    const p = data?.output_prices ?? {};
    const cost = data?.status === 'completed' ? data?.output_unit_cost : estimateQ.data?.unit_cost;
    setPriceForm({
      cost_price: cost ? String(Math.round(Number(cost) * 100) / 100) : '',
      mrp: p.mrp || '', retail_price: p.retail_price || '', wholesale_price: p.wholesale_price || '', dealer_price: p.dealer_price || '',
    });
    setPricing(mode);
  };
  const savePrices = () => api.post(`/production/orders/${id}/prices`, {
    mrp: Number(priceForm.mrp) || null, retail_price: Number(priceForm.retail_price) || null,
    wholesale_price: Number(priceForm.wholesale_price) || null, dealer_price: Number(priceForm.dealer_price) || null,
  }, withCompany(recordCompanyId));
  const completeM = useMutation({
    mutationFn: async () => {
      await savePrices();
      return api.post(`/production/orders/${id}/complete`, {}, withCompany(recordCompanyId));
    },
    onSuccess: () => { setPricing(null); invalidate(); toast.success('Production completed — stock updated and prices saved.'); },
    onError: (err) => toast.error(apiMessage(err, 'Could not complete production.')),
  });
  const pricesM = useMutation({
    mutationFn: savePrices,
    onSuccess: () => { setPricing(null); invalidate(); toast.success('Prices saved on the finished product.'); },
    onError: (err) => toast.error(apiMessage(err, 'Could not save the prices.')),
  });
  const cancelM = useMutation({
    mutationFn: () => api.delete(`/production/orders/${id}`, withCompany(recordCompanyId)),
    onSuccess: () => { invalidate(); toast.success('Production deleted.'); navigate('/production'); },
    onError: (err) => toast.error(apiMessage(err, 'Could not delete production.')),
  });
  const statusM = useMutation({
    mutationFn: (status) => api.patch(`/production/orders/${id}/status`, { status }, withCompany(recordCompanyId)),
    onSuccess: () => { invalidate(); toast.success('Status updated.'); },
    onError: (err) => toast.error(apiMessage(err, 'Could not change status.')),
  });
  const startStageM = useMutation({
    mutationFn: (stageId) => api.post(`/production/orders/${id}/stages/${stageId}/start`, {}, withCompany(recordCompanyId)),
    onSuccess: () => { invalidate(); toast.success('Stage started.'); },
    onError: (err) => toast.error(apiMessage(err, 'Could not start stage.')),
  });
  const completeStageM = useMutation({
    mutationFn: (stageId) => api.post(`/production/orders/${id}/stages/${stageId}/complete`, {}, withCompany(recordCompanyId)),
    onSuccess: (res) => {
      invalidate();
      toast.success(res.data?.message ?? 'Stage completed.');
      if (res.data?.data?.status === 'completed') toast.success('Production completed — stock updated.');
    },
    onError: (err) => toast.error(apiMessage(err, 'Could not complete stage.')),
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
          <Badge tone={statusTone[o.status] ?? 'default'}>{o.status.replace('_', ' ')}</Badge>
          {o.can?.update && (
            <Button variant="outline" size="sm" onClick={() => navigate(o.company_id ? `/production/${o.id}/edit?company_id=${o.company_id}` : `/production/${o.id}/edit`)}>
              <PencilSquareIcon className="size-4" /> Edit
            </Button>
          )}
          {o.can?.cancel && (
            <Button variant="ghost" size="sm" disabled={cancelM.isPending} onClick={async () => {
              const ok = await confirm({ title: 'Delete production?', message: 'Are you sure you want to delete this production?', confirmLabel: 'Delete' });
              if (ok) cancelM.mutate();
            }}><XCircleIcon className="size-4" /> Delete</Button>
          )}
          {o.can?.set_prices && o.status === 'completed' && <Button variant="outline" size="sm" onClick={() => openPricing('edit')}>Set prices</Button>}
          {o.can?.complete && <Button size="sm" onClick={() => openPricing('complete')} disabled={completeM.isPending}><CheckCircleIcon className="size-4" /> Complete</Button>}
        </>}
      />

      <Section title="Details">
        <InfoGrid cols={4}>
          <InfoItem label="Product" value={o.output_product} />
          <InfoItem label="Output quantity" value={o.output_quantity} />
          <InfoItem label="Unit cost" value={o.status === 'completed' ? formatCurrency(o.output_unit_cost) : (estimateQ.data ? formatCurrency(estimateQ.data.unit_cost) : '—')} mono />
          <InfoItem label="Material cost" value={o.status === 'completed' ? formatCurrency(o.material_cost ?? o.total_input_cost) : (estimateQ.data ? formatCurrency(estimateQ.data.total_material_cost) : '—')} mono />
          <InfoItem label="Setting charge" value={Number(o.setting_charge) > 0 ? formatCurrency(o.setting_charge) : null} mono />
          <InfoItem label="Miscellaneous" value={Number(o.misc_charge) > 0 ? formatCurrency(o.misc_charge) : null} mono />
          {o.status === 'completed' && <InfoItem label="Total cost" value={formatCurrency(o.total_input_cost)} mono />}
          {o.status === 'completed' && Number(o.margin_value) > 0 && <InfoItem label={`Margin (${o.margin_type === 'amount' ? '₹ per piece' : o.margin_value + '%'})`} value={o.margin_type === 'amount' ? formatCurrency(o.margin_value) : `${o.margin_value}% of cost`} />}
          {o.suggested_price != null && <InfoItem label="Suggested price" value={formatCurrency(o.suggested_price)} mono />}
          {o.output_prices && <InfoItem label="MRP" value={formatCurrency(o.output_prices.mrp)} mono />}
          {o.output_prices && <InfoItem label="Retail price" value={formatCurrency(o.output_prices.retail_price)} mono />}
          {o.output_prices && <InfoItem label="Wholesale price" value={formatCurrency(o.output_prices.wholesale_price)} mono />}
          {o.output_prices && <InfoItem label="Dealer price" value={formatCurrency(o.output_prices.dealer_price)} mono />}
          <InfoItem label="Made by" value={o.supervisor || '—'} />
          <InfoItem label="Completed" value={o.completed_at ? formatDate(o.completed_at) : null} />
          <InfoItem label="Notes" value={o.notes} />
        </InfoGrid>
      </Section>

      {isSuperAdmin && o.can?.change_status && (
        <Section title="Super admin">
          <div className="max-w-xs">
            <Select value={o.status} onChange={(e) => statusM.mutate(e.target.value)} className="h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm" disabled={statusM.isPending}>
              <option value="draft">Draft</option>
              <option value="in_progress">In progress</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </Select>
          </div>
        </Section>
      )}

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
                    {stage.supervisor && <span>Supervisor {stage.supervisor}</span>}
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
                <th className="microlabel px-3 py-2 text-right font-semibold">Shortage</th>
                <th className="microlabel py-2 pl-3 text-right font-semibold">Line cost</th>
              </tr></thead>
              <tbody>
                {(estimateQ.data.items ?? []).map((it) => (
                  <tr key={it.product_id} className="border-b border-line/60 last:border-0">
                    <td className="py-2 pr-3 font-medium">{it.product_name}</td>
                    <td className={`tnum px-3 py-2 text-right ${it.sufficient ? 'text-muted' : 'text-danger'}`}>{it.required_qty}</td>
                    <td className="tnum px-3 py-2 text-right text-muted">{it.available_stock}</td>
                    <td className={`tnum px-3 py-2 text-right ${it.shortage_qty > 0 ? 'text-danger' : 'text-muted'}`}>{it.shortage_qty || '—'}</td>
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

      <Modal
        open={Boolean(pricing)}
        onClose={() => setPricing(null)}
        title={pricing === 'complete' ? `Complete ${o.order_no ?? 'production'} — set prices` : 'Set prices of the finished product'}
        footer={<>
          <Button variant="ghost" size="sm" onClick={() => setPricing(null)}>Cancel</Button>
          {pricing === 'complete'
            ? <Button size="sm" disabled={completeM.isPending} onClick={() => completeM.mutate()}>Complete production</Button>
            : <Button size="sm" disabled={pricesM.isPending} onClick={() => pricesM.mutate()}>Save prices</Button>}
        </>}
      >
        <p className="mb-3 text-sm text-muted">
          Set the selling prices of <b>{o.output_product}</b>. Enter a margin % on the production cost and the price fills in (rounded to a whole rupee).
          Leave a price empty to keep the product&apos;s current one.
        </p>
        <PriceTiers
          value={priceForm}
          onChange={(patch) => setPriceForm((f) => ({ ...f, ...patch }))}
          costLabel="Cost of one piece"
          costRequired={false}
        />
      </Modal>
    </div>
  );
}
