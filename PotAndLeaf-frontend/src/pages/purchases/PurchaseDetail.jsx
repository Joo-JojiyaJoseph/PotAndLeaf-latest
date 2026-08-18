import { useParams, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, PencilSquareIcon, XCircleIcon, PrinterIcon, BanknotesIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { Badge, Button, Card } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatCurrency, formatDate } from '../../lib/format';
import { printGRN } from '../../lib/invoicePrint';
import { downloadPdf } from '../../lib/pdfDownload';
import { useToast } from '../../lib/toast';
import { Barcode, printBarcodeLabel } from '../../components/Barcode';
import { printBarcodeSheet } from '../../lib/barcodeSheet';

const statusTone = { draft: 'inactive', confirmed: 'active', cancelled: 'blocked' };

export default function PurchaseDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const toast = useToast();

  const { data, isLoading, isError } = useQuery({
    queryKey: ['purchase', id],
    queryFn: () => api.get(`/purchases/${id}`).then((r) => r.data.data),
  });

  // Batches (with their barcodes) only exist once stock is posted on confirm.
  const { data: batches = [] } = useQuery({
    queryKey: ['purchase-batches', id],
    queryFn: () => api.get(`/purchases/${id}/batches`).then((r) => r.data.data),
    enabled: !!data && data.status === 'confirmed',
  });

  function printAllLabels() {
    const labels = [];
    batches.forEach((b) => {
      const copies = Math.min(Math.max(Math.round(Number(b.qty) || 1), 1), 200);
      for (let i = 0; i < copies; i++) {
        labels.push({ name: b.product?.name, sku: b.product?.sku, barcode: b.barcode, price: b.product?.price || b.product?.mrp });
      }
    });
    if (labels.length) printBarcodeSheet(labels);
  }

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['purchase', id] });
    queryClient.invalidateQueries({ queryKey: ['purchases'] });
    queryClient.invalidateQueries({ queryKey: ['inventory'] });
  };
  const confirmM = useMutation({ mutationFn: () => api.post(`/purchases/${id}/confirm`), onSuccess: invalidate });
  const cancelM = useMutation({ mutationFn: () => api.delete(`/purchases/${id}`), onSuccess: invalidate });

  async function downloadGrnPdf() {
    try {
      await downloadPdf(`/purchases/${id}/invoice.pdf`, `grn-${data?.purchase_no ?? id}.pdf`);
      toast.success('GRN PDF downloaded.');
    } catch {
      toast.error('Could not download PDF.');
    }
  }

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/purchases" />;

  const p = data;
  const interstate = p.is_interstate;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={`Purchase ${p.purchase_no}`}
        subtitle={`${p.supplier?.name ?? 'Supplier'} · ${formatDate(p.purchase_date)}`}
        backTo="/purchases"
        actions={
          <>
            <Badge tone={statusTone[p.status] ?? 'default'}>{p.status}</Badge>
            {p.payment_status && p.payment_status !== 'n/a' && <Badge tone={p.payment_status === 'paid' ? 'active' : p.payment_status === 'partial' ? 'warning' : 'blocked'}>{p.payment_status}</Badge>}
            <Button variant="outline" size="sm" onClick={() => printGRN(p)}><PrinterIcon className="size-4" /> Print</Button>
            <Button variant="outline" size="sm" onClick={downloadGrnPdf}><PrinterIcon className="size-4" /> PDF</Button>
            {p.status === 'confirmed' && p.supplier?.id && (p.balance ?? 0) > 0.001 && (
              <Button size="sm" onClick={() => navigate('/payments', { state: { prefill: { key: p.id, supplier_id: p.supplier.id, purchase_id: p.id, balance: p.balance } } })}>
                <BanknotesIcon className="size-4" /> Pay {formatCurrency(p.balance)}
              </Button>
            )}
            {p.can?.update && <Button variant="outline" size="sm" onClick={() => navigate(`/purchases/${id}/edit`)}><PencilSquareIcon className="size-4" /> Edit</Button>}
            {p.can?.cancel && <Button variant="ghost" size="sm" onClick={() => cancelM.mutate()} disabled={cancelM.isPending}><XCircleIcon className="size-4" /> Cancel</Button>}
            {p.can?.confirm && <Button size="sm" onClick={() => confirmM.mutate()} disabled={confirmM.isPending}><CheckCircleIcon className="size-4" /> Confirm</Button>}
          </>
        }
      />

      <Section title="Details">
        <InfoGrid cols={4}>
          <InfoItem label="Supplier" value={p.supplier?.name} />
          <InfoItem label="Invoice no." value={p.invoice_no} mono />
          <InfoItem label="Invoice date" value={p.invoice_date ? formatDate(p.invoice_date) : null} />
          <InfoItem label="Purchase date" value={formatDate(p.purchase_date)} />
          <InfoItem label="Tax type" value={interstate ? 'Inter-state (IGST)' : 'Intra-state (CGST + SGST)'} />
          <InfoItem label="Confirmed at" value={p.confirmed_at ? formatDate(p.confirmed_at) : null} />
          <InfoItem label="Entered by" value={p.entered_by} />
          <InfoItem label="Notes" value={p.notes} />
        </InfoGrid>
      </Section>

      <Section title="Line items">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr className="border-b border-line text-left text-faint">
                <th className="microlabel py-2 pr-3 font-semibold">Product</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Qty</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Rate</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Disc.</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Taxable</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">GST</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Landed unit cost</th>
                <th className="microlabel py-2 pl-3 text-right font-semibold">Total</th>
                <th className="microlabel py-2 pl-3 text-right font-semibold"></th>
              </tr>
            </thead>
            <tbody>
              {(p.items ?? []).map((it) => (
                <tr key={it.id} className="border-b border-line/60 last:border-0">
                  <td className="py-2 pr-3 font-medium">{it.product_name}{it.hsn_code ? <span className="ml-1 text-xs text-muted">HSN {it.hsn_code}</span> : null}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.qty}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(it.rate)}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(it.discount)}</td>
                  <td className="tnum px-3 py-2 text-right">{formatCurrency(it.taxable_value)}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency((it.cgst_amount ?? 0) + (it.sgst_amount ?? 0) + (it.igst_amount ?? 0))} <span className="text-xs">({it.gst_rate}%)</span></td>
                  <td className="tnum px-3 py-2 text-right text-muted">{formatCurrency(it.landed_unit_cost)}</td>
                  <td className="tnum py-2 pl-3 text-right font-medium">{formatCurrency(it.line_total)}</td>
                  {/* <td className="py-2 pl-3 text-right">
                    {p.status === 'confirmed' && it.product_id && !it.sell_as && (
                      <Button variant="outline" size="sm" onClick={() => navigate('/bulk-splits/new', { state: { sourceProductId: it.product_id, sourceQty: it.qty } })}>
                        Split into units
                      </Button>
                    )}
                  </td> */}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Section>

      {p.status === 'confirmed' && batches.length > 0 && (
        <Section
          title="Batch barcodes"
          actions={
            <Button variant="outline" size="sm" onClick={printAllLabels}>
              <PrinterIcon className="size-4" /> Print all labels
            </Button>
          }
        >
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {batches.map((b) => (
              <Card key={b.id} className="flex flex-col gap-3 p-4">
                <div className="min-w-0">
                  <div className="truncate font-medium text-ink">{b.product?.name}</div>
                  <div className="microlabel text-faint">
                    Batch {b.batch_no} · Qty {b.qty}
                  </div>
                </div>
                <div className="flex items-center justify-center rounded-xl bg-white p-3">
                  <Barcode value={b.barcode} height={48} />
                </div>
                <Button
                  variant="outline"
                  size="sm"
                  className="self-center"
                  onClick={() => printBarcodeLabel({ barcode: b.barcode, name: b.product?.name, price: b.product?.price || b.product?.mrp })}
                >
                  <PrinterIcon className="size-4" /> Print label
                </Button>
              </Card>
            ))}
          </div>
        </Section>
      )}

      <div className="flex justify-end">
        <Card className="w-full max-w-xs p-5">
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between"><dt className="text-muted">Subtotal</dt><dd className="tnum">{formatCurrency(p.subtotal)}</dd></div>
            {interstate ? (
              <div className="flex justify-between"><dt className="text-muted">IGST</dt><dd className="tnum">{formatCurrency(p.tax_total)}</dd></div>
            ) : (
              <>
                <div className="flex justify-between"><dt className="text-muted">CGST</dt><dd className="tnum">{formatCurrency(p.tax_total / 2)}</dd></div>
                <div className="flex justify-between"><dt className="text-muted">SGST</dt><dd className="tnum">{formatCurrency(p.tax_total / 2)}</dd></div>
              </>
            )}
            <div className="flex justify-between text-muted"><dt>Landed cost</dt><dd className="tnum">{formatCurrency(p.landed_cost_total)}</dd></div>
            <div className="mt-2 flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>Payable</dt><dd className="tnum">{formatCurrency(p.grand_total)}</dd></div>
            {p.status === 'confirmed' && (
              <>
                <div className="flex justify-between text-muted"><dt>Paid</dt><dd className="tnum">{formatCurrency(p.amount_paid)}</dd></div>
                <div className="flex justify-between font-medium"><dt>Balance</dt><dd className="tnum">{formatCurrency(p.balance)}</dd></div>
              </>
            )}
          </dl>
        </Card>
      </div>
    </div>
  );
}
