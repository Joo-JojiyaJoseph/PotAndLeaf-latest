import { useState } from 'react';
import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, XCircleIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { recordDetailPath } from '../../lib/recordCompany';
import { Badge, Button, Field, Input, Spinner } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatCurrency, formatDate } from '../../lib/format';
import { useToast } from '../../lib/toast';

const tone = { open: 'submitted', partial: 'warning', fulfilled: 'active', cancelled: 'blocked' };

export default function BackorderDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { companyId } = useAuth();
  const queryClient = useQueryClient();
  const toast = useToast();
  const headerCompanyId = searchParams.get('company_id') || companyId;
  const [fulfillQty, setFulfillQty] = useState({});

  const { data, isLoading, isError } = useQuery({
    queryKey: ['backorder', headerCompanyId, id],
    queryFn: () => api.get(`/backorders/${id}`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: Boolean(headerCompanyId && id),
  });

  const recordCompanyId = data?.company_id ?? headerCompanyId;
  const recordCtx = { filterCompanyId: headerCompanyId, companyId };

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['backorder', headerCompanyId, id] });
    queryClient.invalidateQueries({ queryKey: ['backorders'] });
  };
  const cancelM = useMutation({
    mutationFn: () => api.delete(`/backorders/${id}`, withCompany(recordCompanyId)),
    onSuccess: () => { invalidate(); toast.success('Backorder cancelled.'); },
  });
  const fulfillM = useMutation({
    mutationFn: (items) => api.post(`/backorders/${id}/fulfill`, { items }, withCompany(recordCompanyId)),
    onSuccess: (res) => {
      invalidate();
      toast.success(res?.data?.message || 'Fulfillment saved.');
      const sid = res.data.data?.sale_id;
      if (sid) navigate(recordDetailPath('/sales', { id: sid, company_id: recordCompanyId }, recordCtx));
    },
    onError: (err) => toast.error(err.response?.data?.message || 'Could not fulfill.'),
  });

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/backorders" />;
  const o = data;

  function fulfillAllPending() {
    const items = (o.items ?? [])
      .filter((it) => it.pending_qty > 0)
      .map((it) => ({ id: it.id, qty: Math.min(it.pending_qty, it.current_stock ?? it.pending_qty) }))
      .filter((it) => it.qty > 0);
    if (!items.length) {
      toast.error('No stock available to fulfill.');
      return;
    }
    fulfillM.mutate(items);
  }

  function fulfillSelected() {
    const items = (o.items ?? [])
      .map((it) => ({ id: it.id, qty: Number(fulfillQty[it.id]) || 0 }))
      .filter((it) => it.qty > 0);
    if (!items.length) {
      toast.error('Enter qty to fulfill.');
      return;
    }
    fulfillM.mutate(items);
  }

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={`Backorder ${o.order_no}`}
        subtitle={`${o.customer_name} · ${formatDate(o.order_date)}`}
        backTo="/backorders"
        actions={<>
          <Badge tone={tone[o.status] ?? 'default'}>{o.status}</Badge>
          {o.can?.cancel && <Button variant="ghost" size="sm" onClick={() => cancelM.mutate()} disabled={cancelM.isPending}><XCircleIcon className="size-4" /> Cancel</Button>}
          {o.can?.fulfill && (
            <>
              <Button variant="outline" size="sm" onClick={fulfillAllPending} disabled={fulfillM.isPending}>Fulfill available stock</Button>
              <Button size="sm" onClick={fulfillSelected} disabled={fulfillM.isPending}>
                {fulfillM.isPending ? <Spinner className="border-white/40 border-t-white" /> : <><CheckCircleIcon className="size-4" /> Fulfill selected</>}
              </Button>
            </>
          )}
          {o.sale_id && <Button variant="outline" size="sm" onClick={() => navigate(recordDetailPath('/sales', { id: o.sale_id, company_id: recordCompanyId }, recordCtx))}>Source sale</Button>}
        </>}
      />

      <Section title="Details">
        <InfoGrid cols={4}>
          <InfoItem label="Customer" value={o.customer_name} />
          <InfoItem label="Expected" value={o.expected_date ? formatDate(o.expected_date) : null} />
          <InfoItem label="Notes" value={o.notes} />
        </InfoGrid>
      </Section>

      <Section title="Lines">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead><tr className="border-b border-line text-left text-faint">
              <th className="microlabel py-2 pr-3 font-semibold">Product</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Ordered</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Fulfilled</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Pending</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Stock</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Fulfill now</th>
            </tr></thead>
            <tbody>
              {(o.items ?? []).map((it) => (
                <tr key={it.id} className="border-b border-line/60 last:border-0">
                  <td className="py-2 pr-3 font-medium">{it.product_name}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.ordered_qty}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.fulfilled_qty}</td>
                  <td className="tnum px-3 py-2 text-right font-medium">{it.pending_qty}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.current_stock ?? '—'}</td>
                  <td className="px-3 py-2">
                    {it.pending_qty > 0 && o.can?.fulfill ? (
                      <Input type="number" step="0.001" min="0" max={it.pending_qty} className="h-8 text-right text-sm" placeholder={`max ${it.pending_qty}`}
                        value={fulfillQty[it.id] ?? ''} onChange={(e) => setFulfillQty((p) => ({ ...p, [it.id]: e.target.value }))} />
                    ) : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Section>
    </div>
  );
}
