import { useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, PaperAirplaneIcon, XCircleIcon, ArrowsRightLeftIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Badge, Button, Modal } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatDate } from '../../lib/format';

const tone = { requested: 'warning', draft: 'inactive', in_transit: 'warning', received: 'active', rejected: 'blocked', cancelled: 'blocked' };

export default function TransferDetail() {
  const { id } = useParams();
  const [searchParams] = useSearchParams();
  const { companies, companyId } = useAuth();
  const queryClient = useQueryClient();
  const headerCompanyId = searchParams.get('company_id') || companyId;
  const [receiving, setReceiving] = useState(false);
  const [receipts, setReceipts] = useState({});
  const [rejecting, setRejecting] = useState(false);
  const [rejectReason, setRejectReason] = useState('');
  const [redirecting, setRedirecting] = useState(false);
  const [redirectTo, setRedirectTo] = useState('');
  const [approving, setApproving] = useState(false);
  const [approvals, setApprovals] = useState({});

  const { data, isLoading, isError } = useQuery({
    queryKey: ['transfer', headerCompanyId, id],
    queryFn: () => api.get(`/transfers/${id}`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: Boolean(headerCompanyId && id),
  });

  const recordCompanyId = data?.company_id ?? headerCompanyId;

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['transfer', headerCompanyId, id] });
    queryClient.invalidateQueries({ queryKey: ['transfers'] });
    queryClient.invalidateQueries({ queryKey: ['inventory'] });
  };
  const dispatchM = useMutation({ mutationFn: () => api.post(`/transfers/${id}/dispatch`, {}, withCompany(recordCompanyId)), onSuccess: invalidate });
  const cancelM = useMutation({ mutationFn: () => api.delete(`/transfers/${id}`, withCompany(recordCompanyId)), onSuccess: invalidate });
  const approveM = useMutation({
    mutationFn: () => api.post(`/transfers/${id}/approve`, {
      approvals: Object.entries(approvals).map(([itemId, row]) => ({
        id: itemId,
        approved_qty: Number(row.qty) || 0,
        rejection_reason: row.reason || null,
      })),
    }, withCompany(recordCompanyId)),
    onSuccess: () => { invalidate(); setApproving(false); },
  });
  const redirectM = useMutation({
    mutationFn: () => api.post(`/transfers/${id}/redirect`, { to_company_id: redirectTo }, withCompany(recordCompanyId)),
    onSuccess: () => { invalidate(); setRedirecting(false); setRedirectTo(''); },
  });
  const rejectM = useMutation({
    mutationFn: () => api.post(`/transfers/${id}/reject`, { reason: rejectReason || null }, withCompany(recordCompanyId)),
    onSuccess: () => { invalidate(); setRejecting(false); setRejectReason(''); },
  });
  const receiveM = useMutation({
    mutationFn: () => api.post(`/transfers/${id}/receive`, { receipts: Object.entries(receipts).map(([itemId, q]) => ({ id: itemId, received_qty: Number(q) || 0 })) }, withCompany(recordCompanyId)),
    onSuccess: () => { invalidate(); setReceiving(false); },
  });

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/transfers" />;
  const t = data;

  const openReceive = () => {
    const seed = {};
    (t.items ?? []).forEach((it) => { seed[it.id] = String(it.dispatch_qty ?? it.qty); });
    setReceipts(seed); setReceiving(true);
  };

  const openApprove = () => {
    const seed = {};
    (t.items ?? []).forEach((it) => { seed[it.id] = { qty: String(it.qty), reason: '' }; });
    setApprovals(seed); setApproving(true);
  };

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={`Transfer ${t.transfer_no}`}
        subtitle={`${t.from_location ?? t.from_company} → ${t.to_location ?? t.to_company} · ${formatDate(t.transfer_date)}${t.is_intra_company ? ' · location move' : ''}`}
        backTo="/transfers"
        actions={<>
          <Badge tone={tone[t.status] ?? 'default'}>{t.status.replace('_', ' ')}</Badge>
          {t.can?.approve && <Button size="sm" onClick={openApprove} disabled={approveM.isPending}><CheckCircleIcon className="size-4" /> Approve</Button>}
          {t.can?.reject && <Button variant="ghost" size="sm" onClick={() => setRejecting(true)}><XCircleIcon className="size-4" /> Reject</Button>}
          {t.can?.cancel && <Button variant="ghost" size="sm" onClick={() => cancelM.mutate()} disabled={cancelM.isPending}><XCircleIcon className="size-4" /> Cancel</Button>}
          {t.can?.dispatch && <Button variant="outline" size="sm" onClick={() => dispatchM.mutate()} disabled={dispatchM.isPending}><PaperAirplaneIcon className="size-4" /> Dispatch</Button>}
          {t.can?.redirect && <Button variant="outline" size="sm" onClick={() => setRedirecting(true)}><ArrowsRightLeftIcon className="size-4" /> Redirect</Button>}
          {t.can?.receive && <Button size="sm" onClick={openReceive}><CheckCircleIcon className="size-4" /> Receive</Button>}
        </>}
      />

      {t.status === 'requested' && (
        <div className="rounded-xl border border-amber-300/50 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          Awaiting HO approval before stock can be dispatched.
        </div>
      )}
      {t.status === 'rejected' && (
        <div className="rounded-xl border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger">
          This transfer request was rejected{t.rejection_reason ? `: ${t.rejection_reason}` : '.'}
        </div>
      )}

      <Section title="Details">
        <InfoGrid cols={4}>
          <InfoItem label="Type" value={t.is_intra_company ? 'Location transfer' : 'Inter-company'} />
          <InfoItem label="From" value={t.from_location ?? t.from_company} />
          <InfoItem label="To" value={t.to_location ?? t.to_company} />
          {t.redirected_at && <InfoItem label="Redirected" value={formatDate(t.redirected_at)} />}
          <InfoItem label="Dispatched" value={t.dispatched_at ? formatDate(t.dispatched_at) : null} />
          <InfoItem label="Received" value={t.received_at ? formatDate(t.received_at) : null} />
          <InfoItem label="Notes" value={t.notes} />
        </InfoGrid>
      </Section>

      <Section title="Items">
        <table className="w-full text-sm">
          <thead><tr className="border-b border-line text-left text-faint">
            <th className="microlabel py-2 pr-3 font-semibold">Product</th>
            <th className="microlabel px-3 py-2 text-right font-semibold">Requested</th>
            <th className="microlabel px-3 py-2 text-right font-semibold">Approved</th>
            <th className="microlabel py-2 pl-3 text-right font-semibold">Received</th>
          </tr></thead>
          <tbody>
            {(t.items ?? []).map((it) => (
              <tr key={it.id} className="border-b border-line/60 last:border-0">
                <td className="py-2 pr-3 font-medium">
                  {it.product_name}
                  {(it.batch_no || it.source_purchase) && (
                    <span className="mt-0.5 block text-[11px] font-normal text-muted">
                      {it.batch_no ? `Batch ${it.batch_no}` : ''}{it.source_purchase ? ` · from ${it.source_purchase}` : ''}{it.barcode ? ` · ${it.barcode}` : ''}
                    </span>
                  )}
                </td>
                <td className="tnum px-3 py-2 text-right text-muted">{it.qty}</td>
                <td className="tnum px-3 py-2 text-right text-muted">{it.approved_qty != null ? it.approved_qty : (t.status === 'draft' || t.status === 'in_transit' || t.status === 'received' ? (it.dispatch_qty ?? it.qty) : '—')}</td>
                <td className="tnum py-2 pl-3 text-right font-medium">{t.status === 'received' ? it.received_qty : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </Section>

      <Modal open={approving} onClose={() => setApproving(false)} title={`Approve ${t.transfer_no}`}
        footer={<>
          <Button variant="ghost" size="sm" onClick={() => setApproving(false)}>Cancel</Button>
          <Button size="sm" disabled={approveM.isPending} onClick={() => approveM.mutate()}>Confirm approval</Button>
        </>}
      >
        <p className="mb-3 text-sm text-muted">Set approved quantity per line. Any shortfall is rejected and will not be dispatched.</p>
        <div className="space-y-3">
          {(t.items ?? []).map((it) => (
            <div key={it.id} className="rounded-xl border border-line bg-paper/40 p-3">
              <div className="mb-2 flex items-center justify-between gap-3">
                <span className="text-sm font-medium">{it.product_name} <span className="text-xs font-normal text-muted">(requested {it.qty})</span></span>
                <input type="number" step="0.001" max={it.qty} value={approvals[it.id]?.qty ?? ''}
                  onChange={(e) => setApprovals((a) => ({ ...a, [it.id]: { ...a[it.id], qty: e.target.value } }))}
                  className="h-9 w-28 rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums" />
              </div>
              <input value={approvals[it.id]?.reason ?? ''} onChange={(e) => setApprovals((a) => ({ ...a, [it.id]: { ...a[it.id], reason: e.target.value } }))}
                placeholder="Rejection reason (optional)" className="h-9 w-full rounded-[10px] border border-line bg-surface px-2 text-sm" />
            </div>
          ))}
        </div>
      </Modal>

      <Modal open={receiving} onClose={() => setReceiving(false)} title={`Receive ${t.transfer_no}`}
        footer={<>
          <Button variant="ghost" size="sm" onClick={() => setReceiving(false)}>Cancel</Button>
          <Button size="sm" disabled={receiveM.isPending} onClick={() => receiveM.mutate()}>Confirm receipt</Button>
        </>}
      >
        <p className="mb-3 text-sm text-muted">Enter the quantity actually received at {t.to_company ?? t.to_location}. Any shortfall returns to {t.from_company ?? t.from_location}.</p>
        <div className="space-y-2">
          {(t.items ?? []).map((it) => (
            <div key={it.id} className="flex items-center justify-between gap-3">
              <span className="text-sm">{it.product_name} <span className="text-xs text-muted">(sent {it.dispatch_qty ?? it.qty})</span></span>
              <input type="number" step="0.001" max={it.dispatch_qty ?? it.qty} value={receipts[it.id] ?? ''} onChange={(e) => setReceipts((r) => ({ ...r, [it.id]: e.target.value }))}
                className="h-9 w-28 rounded-[10px] border border-line bg-surface px-2 text-right text-sm tabular-nums" />
            </div>
          ))}
        </div>
      </Modal>

      <Modal open={rejecting} onClose={() => setRejecting(false)} title={`Reject ${t.transfer_no}`}
        footer={<>
          <Button variant="ghost" size="sm" onClick={() => setRejecting(false)}>Cancel</Button>
          <Button size="sm" disabled={rejectM.isPending} onClick={() => rejectM.mutate()}>Reject request</Button>
        </>}
      >
        <p className="mb-3 text-sm text-muted">Reject this transfer request? The requester will see the reason below.</p>
        <textarea value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} rows={3} placeholder="Reason (optional)"
          className="w-full rounded-xl border border-line bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25" />
      </Modal>

      <Modal open={redirecting} onClose={() => setRedirecting(false)} title={`Redirect ${t.transfer_no}`}
        footer={<>
          <Button variant="ghost" size="sm" onClick={() => setRedirecting(false)}>Cancel</Button>
          <Button size="sm" disabled={redirectM.isPending || !redirectTo} onClick={() => redirectM.mutate()}>Redirect stock</Button>
        </>}
      >
        <p className="mb-3 text-sm text-muted">Send this in-transit stock to a different shop instead of {t.to_company ?? t.to_location}. The new shop receives and approves it.</p>
        <select value={redirectTo} onChange={(e) => setRedirectTo(e.target.value)}
          className="h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25">
          <option value="">Select destination shop…</option>
          {(companies ?? [])
            .filter((c) => String(c.id) !== String(t.from_company_id) && String(c.id) !== String(t.to_company_id))
            .map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      </Modal>
    </div>
  );
}
