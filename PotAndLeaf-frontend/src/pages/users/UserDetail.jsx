import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PencilSquareIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Badge, Button } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';

export default function UserDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { activeCompany, can, companyId, isSuperAdmin } = useAuth();
  const queryClient = useQueryClient();
  const canManage = isSuperAdmin || can('users.update');
  const headerCompanyId = searchParams.get('company_id') || companyId;

  const { data, isLoading, isError } = useQuery({
    queryKey: ['user', headerCompanyId, id],
    queryFn: () => api.get(`/users/${id}`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: Boolean(headerCompanyId && id),
  });

  // Extra permissions given to this person alone (approved access requests).
  const extrasQ = useQuery({
    queryKey: ['user-extras', headerCompanyId, id],
    queryFn: () => api.get(`/users/${id}/extra-permissions`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: Boolean(headerCompanyId && id && canManage),
  });
  const removeM = useMutation({
    mutationFn: (name) => api.delete(`/users/${id}/extra-permissions/${encodeURIComponent(name)}`, withCompany(headerCompanyId)),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['user-extras', headerCompanyId, id] }),
  });
  const extras = extrasQ.data ?? [];

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/users" />;
  const u = data;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={u.name}
        subtitle={u.is_super_admin ? 'HO super admin' : !u.companies?.length ? 'Removed from all companies — restore from Users → Removed' : `User at ${u.companies?.find((c) => String(c.id) === String(headerCompanyId))?.name ?? activeCompany?.name}`}
        backTo="/users"
        actions={
          <>
            {!u.is_super_admin && !u.companies?.length
              ? <Badge tone="blocked">removed</Badge>
              : <Badge tone={u.is_active ? 'active' : 'inactive'}>{u.is_active ? 'active' : 'inactive'}</Badge>}
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
        </InfoGrid>
      </Section>

      {canManage && !u.is_super_admin && (
        <Section title="Extra permissions">
          {extras.length === 0 ? (
            <p className="text-sm text-muted">Only what the role allows. Extra permissions appear here when you approve an access request from this person.</p>
          ) : (
            <ul className="divide-y divide-line/60">
              {extras.map((e) => (
                <li key={e.name} className="flex items-center justify-between gap-3 py-2 text-sm">
                  <span><span className="text-xs text-muted">{e.module} · </span>{e.label}
                    {e.granted_by && <span className="block text-xs text-faint">given by {e.granted_by}</span>}
                  </span>
                  <Button variant="ghost" size="sm" disabled={removeM.isPending} onClick={() => removeM.mutate(e.name)}>Remove</Button>
                </li>
              ))}
            </ul>
          )}
        </Section>
      )}
    </div>
  );
}
