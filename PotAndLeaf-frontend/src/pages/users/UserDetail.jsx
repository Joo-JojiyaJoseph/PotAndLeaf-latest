import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { PencilSquareIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Badge, Button } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';

export default function UserDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { activeCompany, can, companyId } = useAuth();
  const headerCompanyId = searchParams.get('company_id') || companyId;

  const { data, isLoading, isError } = useQuery({
    queryKey: ['user', headerCompanyId, id],
    queryFn: () => api.get(`/users/${id}`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: Boolean(headerCompanyId && id),
  });

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/users" />;
  const u = data;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={u.name}
        subtitle={u.is_super_admin ? 'HO super admin' : `User at ${u.companies?.find((c) => String(c.id) === String(headerCompanyId))?.name ?? activeCompany?.name}`}
        backTo="/users"
        actions={
          <>
            <Badge tone={u.is_active ? 'active' : 'inactive'}>{u.is_active ? 'active' : 'inactive'}</Badge>
            {/* {can('users.update') && <Button variant="outline" size="sm" onClick={() => navigate('/users')}><PencilSquareIcon className="size-4" /> Edit</Button>} */}
          </>
        }
      />

      <Section title="Profile">
        <InfoGrid cols={3}>
          <InfoItem label="Full name" value={u.name} />
          <InfoItem label="Email" value={u.email} />
          <InfoItem label="Phone / WhatsApp" value={u.phone} mono />
        </InfoGrid>
      </Section>

      <Section title="Access">
        <InfoGrid cols={3}>
          <InfoItem label="Role in this company" value={u.roles?.length ? <Badge tone="info">{u.roles[0].name}</Badge> : '—'} />
          <InfoItem label="Status" value={u.is_active ? 'Active' : 'Inactive'} />
          <InfoItem label="HO super admin" value={u.is_super_admin ? 'Yes — full access to all companies' : 'No'} />
        </InfoGrid>
      </Section>
    </div>
  );
}
