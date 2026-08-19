import { useState } from 'react';
import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, XCircleIcon, PrinterIcon, BanknotesIcon, ChatBubbleLeftRightIcon, ArrowPathIcon, ClockIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatCurrency, formatDate } from '../../lib/format';
import { printInvoice } from '../../lib/invoicePrint';
import { downloadPdf } from '../../lib/pdfDownload';
import { useToast } from '../../lib/toast';

const statusTone = { draft: 'inactive', proforma: 'info', confirmed: 'active', cancelled: 'blocked' };
const billKindLabel = { tax_invoice: 'Tax invoice', proforma: 'Proforma', complimentary: 'Complimentary' };

export default function SaleDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { companyId } = useAuth();
  const queryClient = useQueryClient();
  const toast = useToast();
  const headerCompanyId = searchParams.get('company_id') || companyId;
  const [cancelModal, setCancelModal] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  const [rejectModal, setRejectModal] = useState(false);
  const [rejectReason, setRejectReason] = useState('');

  const { data, isLoading, isError } = useQuery({
    queryKey: ['sale', headerCompanyId, id],
    queryFn: () => api.get(`/sales/${id}`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: Boolean(headerCompanyId && id),
  });

  const recordCompanyId = data?.company_id ?? headerCompanyId;

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['sale', headerCompanyId, id] });
    queryClient.invalidateQueries({ queryKey: ['sales'] });
    queryClient.invalidateQueries({ queryKey: ['inventory'] });
  };
  const confirmM = useMutation({
    mutationFn: () => api.post(`/sales/${id}/confirm`, {}, withCompany(recordCompanyId)),
    onSuccess: async (res) => {
      invalidate();
      const confirmed = res?.data?.data;
      toast.success(res?.data?.message || 'Sale confirmed.');
      if (confirmed && confirmed.status === 'confirmed') {
        try { printInvoice(confirmed); } catch { /* print blocked */ }
      }
    },
    onError: (err) => toast.error(err.response?.data?.message || 'Could not confirm sale.'),
  });
  const cancelM = useMutation({
    mutationFn: () => api.delete(`/sales/${id}`, withCompany(recordCompanyId)),
    onSuccess: (res) => { invalidate(); toast.success(res?.data?.message || 'Sale cancelled.'); },
    onError: (err) => toast.error(err.response?.data?.message || 'Could not cancel sale.'),
  });
  const cancelRequestM = useMutation({
    mutationFn: (reason) => api.post(`/sales/${id}/cancel-request`, { reason }, withCompany(recordCompanyId)),
    onSuccess: (res) => { invalidate(); setCancelModal(false); setCancelReason(''); toast.success(res?.data?.message || 'Request submitted.'); },
    onError: (err) => toast.error(err.response?.data?.message || 'Could not submit request.'),
  });
  const cancelApproveM = useMutation({
    mutationFn: () => api.post(`/sales/${id}/cancel-approve`, {}, withCompany(recordCompanyId)),
    onSuccess: (res) => { invalidate(); toast.success(res?.data?.message || 'Cancellation approved.'); },
    onError: (err) => toast.error(err.response?.data?.message || 'Could not approve cancellation.'),
  });
  const cancelRejectM = useMutation({
    mutationFn: (reason) => api.post(`/sales/${id}/cancel-reject`, { reason: reason || null }, withCompany(recordCompanyId)),
    onSuccess: (res) => { invalidate(); setRejectModal(false); setRejectReason(''); toast.success(res?.data?.message || 'Request rejected.'); },
    onError: (err) => toast.error(err.response?.data?.message || 'Could not reject request.'),
  });
  const convertM = useMutation({
    mutationFn: () => api.post(`/sales/${id}/convert-proforma`, {}, withCompany(recordCompanyId)),
    onSuccess: (res) => { invalidate(); toast.success(res?.data?.message || 'Converted to tax invoice draft.'); },
    onError: (err) => toast.error(err.response?.data?.message || 'Could not convert proforma.'),
  });
  const whatsappM = useMutation({
    mutationFn: () => api.post(`/sales/${id}/whatsapp`, {}, withCompany(recordCompanyId)),
    onSuccess: (res) => { invalidate(); toast.success(res?.data?.message || 'WhatsApp sent.'); },
    onError: (err) => toast.error(err.response?.data?.message || 'Could not send WhatsApp.'),
  });
  const backorderM = useMutation({
    mutationFn: () => api.post(`/sales/${id}/backorder`, {}, withCompany(recordCompanyId)),
    onSuccess: (res) => {
      invalidate();
      toast.success(res?.data?.message || 'Backorder created.');
      const boId = res?.data?.data?.id;
      if (boId) navigate(`/backorders/${boId}?company_id=${recordCompanyId}`);
    },
    onError: (err) => toast.error(err.response?.data?.message || 'Could not create backorder.'),
  });

  async function downloadInvoicePdf() {
    try {
      await downloadPdf(`/sales/${id}/invoice.pdf`, `invoice-${data?.sale_no ?? id}.pdf`, recordCompanyId);
      toast.success('Invoice PDF downloaded.');
    } catch {
      toast.error('Could not download PDF.');
    }
  }

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/sales" />;
  const s = data;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={`Invoice ${s.sale_no}`}
        subtitle={`${s.customer_name} · ${formatDate(s.sale_date)}`}
        backTo="/sales"
        actions={<>
          <Button variant="outline" size="sm" onClick={() => printInvoice(s)}><PrinterIcon className="size-4" /> Print</Button>
          <Button variant="outline" size="sm" onClick={downloadInvoicePdf}><PrinterIcon className="size-4" /> PDF</Button>
          {s.status === 'draft' && s.customer_id && (
            <Button variant="outline" size="sm" onClick={() => backorderM.mutate()} disabled={backorderM.isPending}>
              <ClockIcon className="size-4" /> Backorder shortage
            </Button>
          )}
          {s.can?.whatsapp && (
            <Button variant="outline" size="sm" onClick={() => whatsappM.mutate()} disabled={whatsappM.isPending}>
              <ChatBubbleLeftRightIcon className="size-4" /> WhatsApp
            </Button>
          )}
          {s.status === 'confirmed' && s.customer_id && ['unpaid', 'partial'].includes(s.payment_status) && (
            <Button size="sm" onClick={() => navigate('/receipts', { state: { prefill: { key: s.id, customer_id: s.customer_id, sale_id: s.id, balance: s.balance ?? +(s.grand_total - s.amount_paid).toFixed(2) } } })}>
              <BanknotesIcon className="size-4" /> Record receipt · {formatCurrency(s.balance ?? (s.grand_total - s.amount_paid))} due
            </Button>
          )}
          <Badge tone={statusTone[s.status] ?? 'default'}>{s.status}</Badge>
          {s.bill_kind && s.bill_kind !== 'tax_invoice' && (
            <Badge tone="info">{billKindLabel[s.bill_kind] ?? s.bill_kind}</Badge>
          )}
          {s.can?.convert_proforma && (
            <Button variant="outline" size="sm" onClick={() => convertM.mutate()} disabled={convertM.isPending}>
              <ArrowPathIcon className="size-4" /> Convert to tax invoice
            </Button>
          )}
          {s.can?.cancel && (
            <Button variant="ghost" size="sm" onClick={() => cancelM.mutate()} disabled={cancelM.isPending}>
              <XCircleIcon className="size-4" /> Cancel
            </Button>
          )}
          {s.can?.cancel_request && (
            <Button variant="ghost" size="sm" onClick={() => setCancelModal(true)}>
              <XCircleIcon className="size-4" /> Request cancellation
            </Button>
          )}
          {s.can?.cancel_approve && (
            <>
              <Button variant="ghost" size="sm" onClick={() => cancelApproveM.mutate()} disabled={cancelApproveM.isPending}>
                <CheckCircleIcon className="size-4" /> Approve cancel
              </Button>
              <Button variant="ghost" size="sm" onClick={() => setRejectModal(true)}>
                <XCircleIcon className="size-4" /> Reject
              </Button>
            </>
          )}
          {s.can?.confirm && (
            <Button size="sm" onClick={() => confirmM.mutate()} disabled={confirmM.isPending}>
              <CheckCircleIcon className="size-4" /> Confirm
            </Button>
          )}
        </>}
      />

      {s.cancel_requested_at && s.status === 'confirmed' && (
        <Card className="border-warning/40 bg-warning-soft/30 p-4 text-sm">
          <p className="font-medium text-ink">Cancellation pending HO approval</p>
          <p className="mt-1 text-muted">Requested by {s.cancel_requested_by ?? '—'} on {formatDate(s.cancel_requested_at)}</p>
          {s.cancel_reason && <p className="mt-2">Reason: {s.cancel_reason}</p>}
        </Card>
      )}

      <Section title="Details">
        <InfoGrid cols={4}>
          <InfoItem label="Customer" value={s.customer_name} />
          <InfoItem label="Bill type" value={billKindLabel[s.bill_kind] ?? s.bill_kind} />
          <InfoItem label="Payment mode" value={s.payment_mode} />
          <InfoItem label="Amount paid" value={formatCurrency(s.amount_paid)} mono />
          <InfoItem label="Tax type" value={s.is_interstate ? 'Inter-state (IGST)' : 'Intra-state (CGST + SGST)'} />
          <InfoItem label="Confirmed at" value={s.confirmed_at ? formatDate(s.confirmed_at) : null} />
          <InfoItem label="Entered by" value={s.entered_by} />
          <InfoItem label="Notes" value={s.notes} />
        </InfoGrid>
      </Section>

      <Section title="Items">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-sm">
            <thead><tr className="border-b border-line text-left text-faint">
              <th className="microlabel py-2 pr-3 font-semibold">Product</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Qty</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Rate</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Disc.</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">Taxable</th>
              <th className="microlabel px-3 py-2 text-right font-semibold">GST</th>
              <th className="microlabel py-2 pl-3 text-right font-semibold">Total</th>
            </tr></thead>
            <tbody>
              {(s.items ?? []).map((it) => (
                <tr key={it.id} className="border-b border-line/60 last:border-0">
                  <td className="py-2 pr-3 font-medium">{it.product_name}{it.hsn_code ? <span className="ml-1 text-xs text-muted">HSN {it.hsn_code}</span> : null}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.qty}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(it.rate)}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(it.discount)}</td>
                  <td className="tnum px-3 py-2 text-right">{formatCurrency(it.taxable_value)}</td>
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
            <div className="flex justify-between"><dt className="text-muted">Subtotal</dt><dd className="tnum">{formatCurrency(s.subtotal)}</dd></div>
            <div className="flex justify-between"><dt className="text-muted">{s.is_interstate ? 'IGST' : 'CGST + SGST'}</dt><dd className="tnum">{formatCurrency(s.tax_total)}</dd></div>
            <div className="flex justify-between text-muted"><dt>Round off</dt><dd className="tnum">{formatCurrency(s.round_off)}</dd></div>
            <div className="mt-2 flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Total</dt><dd className="tnum">{formatCurrency(s.grand_total)}</dd></div>
          </dl>
        </Card>
      </div>

      <Modal open={cancelModal} onClose={() => setCancelModal(false)} title="Request cancellation">
        <div className="space-y-4">
          <Field label="Reason" required>
            <Input value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} placeholder="Why should this sale be cancelled?" />
          </Field>
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setCancelModal(false)}>Close</Button>
            <Button onClick={() => cancelRequestM.mutate(cancelReason)} disabled={!cancelReason.trim() || cancelRequestM.isPending}>
              {cancelRequestM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Submit request'}
            </Button>
          </div>
        </div>
      </Modal>

      <Modal open={rejectModal} onClose={() => setRejectModal(false)} title="Reject cancellation">
        <div className="space-y-4">
          <Field label="Rejection note (optional)">
            <Input value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} placeholder="Optional note to the requester" />
          </Field>
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setRejectModal(false)}>Close</Button>
            <Button onClick={() => cancelRejectM.mutate(rejectReason)} disabled={cancelRejectM.isPending}>
              {cancelRejectM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Reject request'}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
