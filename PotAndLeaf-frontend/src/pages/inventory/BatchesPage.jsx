import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ExclamationTriangleIcon, PrinterIcon, QrCodeIcon } from '@heroicons/react/24/outline';
import { useToast } from '../../lib/toast';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import { Barcode, printBarcodeLabel } from '../../components/Barcode';
import { printBarcodeSheet } from '../../lib/barcodeSheet';

function SafeBarcode({ value, height = 40 }) {
  if (!value) {
    return <span className="text-xs text-muted">No barcode</span>;
  }
  try {
    return <Barcode value={String(value)} height={height} />;
  } catch {
    return <span className="font-mono text-xs text-muted">{String(value)}</span>;
  }
}

export default function BatchesPage() {
  const { activeCompany } = useAuth();
  const toast = useToast();
  const queryClient = useQueryClient();
  const [searchParams] = useSearchParams();
  const [filter, setFilter] = useState(searchParams.get('q') ?? '');

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['inventory-batches', activeCompany?.id],
    queryFn: () => api.get('/inventory/batches').then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });

  const genM = useMutation({
    mutationFn: () => api.post('/batches/generate-opening'),
    onSuccess: (res) => {
      toast.success(res.data?.message || 'Opening barcodes generated.');
      queryClient.invalidateQueries({ queryKey: ['inventory-batches'] });
    },
    onError: (err) => toast.error(err.response?.data?.message || 'Could not generate barcodes.'),
  });

  const batches = (data?.batches ?? []).filter((b) => {
    const q = filter.trim().toLowerCase();
    if (!q) return true;
    return [b.product, b.sku, b.barcode, b.batch_no, b.source].some((v) => String(v ?? '').toLowerCase().includes(q));
  });
  const untracked = data?.untracked ?? [];

  function printAll() {
    const labels = [];
    batches.forEach((b) => {
      if (!b.barcode) return;
      const copies = Math.min(Math.max(Math.round(Number(b.remaining_qty) || 1), 1), 200);
      for (let i = 0; i < copies; i++) labels.push({ name: b.product, barcode: b.barcode });
    });
    if (labels.length) printBarcodeSheet(labels);
  }

  if (!activeCompany) {
    return (
      <div className="p-4 sm:p-6">
        <Card className="p-10 text-center text-sm text-muted">Select a company to view batches.</Card>
      </div>
    );
  }

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-ink">Batches &amp; barcodes</h1>
          <p className="text-sm text-muted">Every batch in stock and its scannable barcode.</p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={printAll} disabled={batches.length === 0}>
            <PrinterIcon className="size-4" /> Print all
          </Button>
        </div>
      </div>

      {isError && (
        <Card className="flex flex-wrap items-center justify-between gap-3 border-danger/30 bg-danger-soft p-4">
          <div className="flex items-start gap-2 text-sm text-danger">
            <ExclamationTriangleIcon className="mt-0.5 size-5 shrink-0" />
            <span>{error?.response?.data?.message ?? 'Could not load batches. Please try again.'}</span>
          </div>
          <Button variant="outline" size="sm" onClick={() => refetch()}>Retry</Button>
        </Card>
      )}

      {!isError && untracked.length > 0 && (
        <Card className="flex flex-wrap items-center justify-between gap-3 border-amber-300/50 bg-amber-50 p-4">
          <div className="text-sm text-amber-800">
            {untracked.length} product{untracked.length === 1 ? '' : 's'} hold stock with no batch barcode yet
            (pre-existing stock). Generate a one-time opening barcode so it can be scanned.
          </div>
          <Button size="sm" onClick={() => genM.mutate()} disabled={genM.isPending}>
            {genM.isPending ? <Spinner className="border-white/40 border-t-white" /> : <><QrCodeIcon className="size-4" /> Generate opening barcodes</>}
          </Button>
        </Card>
      )}

      <input
        value={filter}
        onChange={(e) => setFilter(e.target.value)}
        placeholder="Filter by product, SKU, barcode, batch, source…"
        className="h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25"
      />

      {isLoading ? (
        <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
      ) : isError ? null : batches.length === 0 ? (
        <Card className="p-10 text-center text-sm text-muted">No batches in stock.</Card>
      ) : (
        <Card className="overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2 font-semibold">Product</th>
                  <th className="microlabel px-4 py-2 font-semibold">Batch</th>
                  <th className="microlabel px-4 py-2 font-semibold">Source</th>
                  <th className="microlabel px-4 py-2 text-right font-semibold">Remaining</th>
                  <th className="microlabel px-4 py-2 font-semibold">Barcode</th>
                  <th className="px-4 py-2" />
                </tr>
              </thead>
              <tbody>
                {batches.map((b) => (
                  <tr key={b.id ?? `${b.batch_no}-${b.barcode}`} className="border-b border-line/60 last:border-0">
                    <td className="px-4 py-2.5">
                      <div className="font-medium text-ink">{b.product ?? 'Unknown product'}</div>
                      <div className="microlabel text-faint">{b.sku ?? '—'}</div>
                      {b.source_product && (
                        <div className="mt-0.5 text-xs text-muted">From bulk: {b.source_product}</div>
                      )}
                    </td>
                    <td className="px-4 py-2.5 text-muted">{b.batch_no ?? '—'}</td>
                    <td className="px-4 py-2.5"><Badge tone="default">{b.source ?? '—'}</Badge></td>
                    <td className="tnum px-4 py-2.5 text-right font-medium">{b.remaining_qty ?? 0}</td>
                    <td className="px-4 py-2.5"><div className="w-40"><SafeBarcode value={b.barcode} height={40} /></div></td>
                    <td className="px-4 py-2.5 text-right">
                      {b.barcode && (
                        <Button variant="ghost" size="sm" onClick={() => printBarcodeLabel({ barcode: b.barcode, name: b.product })}>
                          <PrinterIcon className="size-4" />
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  );
}
