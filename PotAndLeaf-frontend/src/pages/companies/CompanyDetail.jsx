import { useNavigate, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { PencilSquareIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatDate } from '../../lib/format';
import { mediaUrl } from '../../components/media';
import { BuildingOffice2Icon } from '@heroicons/react/24/outline';

export default function CompanyDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { isSuperAdmin, booting } = useAuth();

  const { data: c, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['company', id],
    queryFn: () => api.get(`/companies/${id}`).then((r) => r.data.data),
    enabled: Boolean(id) && !booting && isSuperAdmin,
    retry: 1,
  });

  if (booting || isLoading) return <DetailLoading />;

  if (!isSuperAdmin) {
    return <DetailError backTo="/" message="Company details are available to HO super admins only." />;
  }

  if (isError || !c) {
    const apiMessage = error?.response?.data?.message;
    const status = error?.response?.status;
    const hint = status === 403
      ? 'Your account is not a super admin. Log in as admin@potandleaf.test.'
      : status === 404
        ? 'This company does not exist or was removed.'
        : apiMessage ?? error?.message ?? 'Server error loading company details.';
    return (
      <div className="space-y-3">
        <DetailError backTo="/companies" message={hint} />
        {status >= 500 && (
          <div className="px-6 text-center">
            <Button size="sm" variant="outline" onClick={() => refetch()}>Retry</Button>
          </div>
        )}
      </div>
    );
  }

  const stats = c.statistics ?? {};

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={c.name}
        subtitle={c.code}
        backTo="/companies"
        actions={
          <>
            <Badge tone={c.is_active ? 'active' : 'inactive'}>{c.is_active ? 'Active' : 'Inactive'}</Badge>
            <Button size="sm" variant="outline" onClick={() => navigate('/companies', { state: { editId: c.id } })}>
              <PencilSquareIcon className="size-4" /> Edit
            </Button>
          </>
        }
      />

      <div className="flex items-start gap-4">
        <div className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-leaf-soft">
          {(c.logo || c.photo)
            ? <img src={mediaUrl(c.logo || c.photo)} alt="" className="size-full object-cover" />
            : <BuildingOffice2Icon className="size-10 text-leaf/50" />}
        </div>
        <div>
          <p className="text-sm text-muted">{c.description || 'No description'}</p>
          {c.legal_name && <p className="mt-1 text-sm"><span className="text-muted">Legal name:</span> {c.legal_name}</p>}
        </div>
      </div>

      <Section title="Company information">
        <InfoGrid cols={3}>
          <InfoItem label="Company code" value={c.code} mono />
          <InfoItem label="Status" value={c.is_active ? 'Active' : 'Inactive'} />
          <InfoItem label="Created" value={c.created_at ? formatDate(c.created_at) : '—'} />
          <InfoItem label="Updated" value={c.updated_at ? formatDate(c.updated_at) : '—'} />
        </InfoGrid>
      </Section>

      <Section title="Contact">
        <InfoGrid cols={3}>
          <InfoItem label="Email" value={c.email || '—'} />
          <InfoItem label="Phone" value={c.phone || '—'} />
        </InfoGrid>
      </Section>

      <Section title="Address">
        <InfoGrid cols={3}>
          <InfoItem label="Address" value={c.address || '—'} />
          <InfoItem label="State" value={c.state || '—'} />
          <InfoItem label="State code" value={c.state_code || '—'} />
          <InfoItem label="Locations" value={c.locations || '—'} />
        </InfoGrid>
      </Section>

      <Section title="Tax & registration">
        <InfoGrid cols={2}>
          <InfoItem label="GST number" value={c.gst_number || '—'} mono />
          <InfoItem label="Legal name" value={c.legal_name || '—'} />
        </InfoGrid>
      </Section>

      <Section title="Statistics">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {[
            ['Users', stats.users_total],
            ['Active users', stats.users_active],
            ['Inactive users', stats.users_inactive],
            ['Products', stats.products_total],
            ['Categories', stats.categories_total],
            ['Subcategories', stats.subcategories_total],
            ['Suppliers', stats.suppliers_total],
            ['Purchases', stats.purchases_total],
          ].map(([label, value]) => (
            <Card key={label} className="p-4 text-center">
              <div className="microlabel text-faint">{label}</div>
              <div className="tnum mt-1 text-xl font-semibold">{value ?? 0}</div>
            </Card>
          ))}
        </div>
      </Section>
    </div>
  );
}
