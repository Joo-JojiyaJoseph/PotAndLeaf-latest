import { useParams, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, XCircleIcon, PrinterIcon, BanknotesIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { Badge, Button, Card } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatCurrency, formatDate } from '../../lib/format';
import { printInvoice } from '../../lib/invoicePrint';
import { downloadPdf } from '../../lib/pdfDownload';
import { useToast } from '../../lib/toast';

const statusTone = { draft: 'inactive', confirmed: 'active', cancelled: 'blocked' };

export default function SaleDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const toast = useToast();
  const { data, isLoading, isError } = useQuery({
    queryKey: ['sale', id],
    queryFn: () => api.get(`/sales/${id}`).then((r) => r.data.data),
  });
  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['sale', id] });
    queryClient.invalidateQueries({ queryKey: ['sales'] });
    queryClient.invalidateQueries({ queryKey: ['inventory'] });
  };
  const confirmM = useMutation({
    mutationFn: () => api.post(`/sales/${id}/confirm`),
    onSuccess: async (res) => {
      invalidate();
      const confirmed = res?.data?.data;
      toast.success(res?.data?.message || 'Sale confirmed.');
      // Auto-open the customer invoice print dialog after POS confirm.
      if (confirmed) {
        try { printInvoice(confirmed); } catch { /* print blocked — user can retry via Print */ }
      }
    },
    onError: (err) => toast.error(err.response?.data?.message || 'Could not confirm sale.'),
  });
  const cancelM = useMutation({ mutationFn: () => api.delete(`/sales/${id}`), onSuccess: invalidate });

  async function downloadInvoicePdf() {
    try {
      await downloadPdf(`/sales/${id}/invoice.pdf`, `invoice-${data?.sale_no ?? id}.pdf`);
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
          {s.status === 'confirmed' && s.customer_id && ['unpaid', 'partial'].includes(s.payment_status) && (
            <Button size="sm" onClick={() => navigate('/receipts', { state: { prefill: { key: s.id, customer_id: s.customer_id, sale_id: s.id, balance: s.balance ?? +(s.grand_total - s.amount_paid).toFixed(2) } } })}>
              <BanknotesIcon className="size-4" /> Record receipt · {formatCurrency(s.balance ?? (s.grand_total - s.amount_paid))} due
            </Button>
          )}
          <Badge tone={statusTone[s.status] ?? 'default'}>{s.status}</Badge>
          {s.can?.cancel && <Button variant="ghost" size="sm" onClick={() => cancelM.mutate()} disabled={cancelM.isPending}><XCircleIcon className="size-4" /> Cancel</Button>}
          {s.can?.confirm && <Button size="sm" onClick={() => confirmM.mutate()} disabled={confirmM.isPending}><CheckCircleIcon className="size-4" /> Confirm</Button>}
        </>}
      />

      <Section title="Details">
        <InfoGrid cols={4}>
          <InfoItem label="Customer" value={s.customer_name} />
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
    </div>
  );
}
