import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { PencilSquareIcon, PrinterIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Badge, Button, Card } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatCurrency } from '../../lib/format';
import { mediaUrl } from '../../components/media';
import { Barcode, printBarcodeLabel } from '../../components/Barcode';

export default function ProductDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { can, companyId } = useAuth();
  const headerCompanyId = searchParams.get('company_id') || companyId;
  const companyCfg = headerCompanyId ? withCompany(headerCompanyId) : {};

  const { data: p, isLoading, isError } = useQuery({
    queryKey: ['product', id, headerCompanyId],
    queryFn: () => api.get(`/products/${id}`, companyCfg).then((r) => r.data.data),
    enabled: Boolean(id && headerCompanyId),
  });

  const { data: batches } = useQuery({
    queryKey: ['product-batches', id, headerCompanyId],
    queryFn: () => api.get(`/products/${id}/batches`, companyCfg).then((r) => r.data.data),
    enabled: Boolean(id && headerCompanyId),
  });

  if (isLoading) return <DetailLoading />;
  if (isError || !p) return <DetailError backTo="/products" />;

  const photos = p.images ?? [];
  const activeBatches = (batches ?? []).filter((b) => (b.remaining_qty ?? 0) > 0);

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={p.name}
        subtitle={`${p.sku}${p.category ? ` · ${p.category}` : ''}`}
        backTo="/products"
        actions={<>
          <Badge tone={p.status === 'active' ? 'active' : 'inactive'}>{p.status}</Badge>
          {p.is_rental && <Badge tone="info">Rental</Badge>}
          {(p.can?.update ?? can('products.update')) && (
            <Button size="sm" onClick={() => navigate(`/products/${p.id}/edit${headerCompanyId ? `?company_id=${headerCompanyId}` : ''}`)}>
              <PencilSquareIcon className="size-4" /> Edit
            </Button>
          )}
        </>}
      />

      <Section title="Overview">
        <InfoGrid cols={4}>
          <InfoItem label="SKU" value={p.sku} mono />
          <InfoItem label="Category" value={p.category || '—'} />
          <InfoItem label="Unit" value={p.unit || '—'} />
          <InfoItem label="HSN code" value={p.hsn_code || '—'} />
          <InfoItem label="GST %" value={`${p.gst_rate ?? 0}%`} />
          <InfoItem label="Status" value={p.status} />
          {p.is_rental && <InfoItem label="Daily rental rate" value={formatCurrency(p.rental_daily_rate ?? 0)} mono />}
        </InfoGrid>
        {p.description && <p className="mt-3 text-sm text-muted">{p.description}</p>}
      </Section>

      <Section title="Pricing">
        <InfoGrid cols={5}>
          <InfoItem label="Cost price" value={formatCurrency(p.cost_price ?? 0)} mono />
          <InfoItem label="MRP" value={formatCurrency(p.mrp ?? 0)} mono />
          <InfoItem label="Retail" value={formatCurrency(p.retail_price ?? 0)} mono />
          <InfoItem label="Wholesale" value={formatCurrency(p.wholesale_price ?? 0)} mono />
          <InfoItem label="Dealer" value={formatCurrency(p.dealer_price ?? 0)} mono />
        </InfoGrid>
      </Section>

      <Section title="Stock">
        <InfoGrid cols={4}>
          <InfoItem label="Current stock" value={<span className={p.is_low_stock ? 'text-amber font-medium' : ''}>{p.current_stock ?? 0}</span>} />
          <InfoItem label="Opening stock" value={p.opening_stock ?? 0} />
          <InfoItem label="Reorder level" value={p.reorder_level ?? 0} />
          <InfoItem label="Needs reorder" value={p.is_low_stock ? 'Yes' : 'No'} />
        </InfoGrid>
      </Section>

      {(p.suppliers?.length ?? 0) > 0 && (
        <Section title="Suppliers">
          <div className="flex flex-wrap gap-2">
            {p.suppliers.map((s) => (
              <span key={s.id} className="rounded-xl border border-line px-3 py-1.5 text-sm">
                {s.name} · {formatCurrency(s.supplier_price ?? 0)}
              </span>
            ))}
          </div>
        </Section>
      )}

      <Section title="Barcodes">
        {p.barcode && (
          <div className="mb-4">
            <div className="microlabel mb-1 text-faint">Product-level barcode</div>
            <div className="inline-flex flex-col items-center gap-2 rounded-xl border border-line p-3">
              <Barcode value={p.barcode} height={44} />
              <Button variant="outline" size="sm" onClick={() => printBarcodeLabel({ barcode: p.barcode, name: p.name })}>
                <PrinterIcon className="size-4" /> Print label
              </Button>
            </div>
          </div>
        )}
        {activeBatches.length > 0 ? (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {activeBatches.map((b) => (
              <Card key={b.id} className="flex flex-col gap-2 p-3">
                <div className="flex items-center justify-between text-xs">
                  <span className="font-medium text-ink">Batch {b.batch_no}</span>
                  <span className="text-muted">Qty {b.remaining_qty}</span>
                </div>
                <div className="flex items-center justify-center rounded-lg bg-white p-2"><Barcode value={b.barcode} height={38} /></div>
                <Button variant="ghost" size="sm" className="self-center" onClick={() => printBarcodeLabel({ barcode: b.barcode, name: p.name })}>
                  <PrinterIcon className="size-4" /> Print
                </Button>
              </Card>
            ))}
          </div>
        ) : (
          !p.barcode && <p className="text-sm text-muted">No barcodes yet. Barcodes are created per batch when stock is received on a purchase, or via the opening-barcode generator on the Batches page.</p>
        )}
      </Section>

      {photos.length > 0 && (
        <Section title="Photos">
          <div className="flex flex-wrap gap-3">
            {photos.map((src, i) => (
              <img key={i} src={mediaUrl(src)} alt="" className="size-24 rounded-xl object-cover" />
            ))}
          </div>
        </Section>
      )}
    </div>
  );
}
