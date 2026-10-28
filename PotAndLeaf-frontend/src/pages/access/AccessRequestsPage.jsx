import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { InboxArrowDownIcon, CheckIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../lib/toast';
import { apiMessage } from '../../lib/formErrors';
import { Badge, Button, Card, Input, PageHeader, Spinner } from '../../components/ui';
import Pagination from '../../components/Pagination';
import { formatDateTime } from '../../lib/format';

const TABS = [['pending', 'Waiting'], ['approved', 'Approved'], ['rejected', 'Rejected'], ['all', 'All']];
const tone = { pending: 'pending', approved: 'active', rejected: 'blocked', cancelled: 'inactive' };
const word = { pending: 'waiting', approved: 'approved', rejected: 'rejected', cancelled: 'withdrawn' };

/** One request: tick what to give, approve or reject with a note. */
function RequestCard({ r, onDone }) {
  const toast = useToast();
  const [give, setGive] = useState(() => new Set(r.permissions.filter((p) => p.can_grant).map((p) => p.name)));
  const [note, setNote] = useState('');
  const [error, setError] = useState('');
  const act = useMutation({
    mutationFn: (kind) => (kind === 'approve'
      ? api.post(`/access-requests/${r.id}/approve`, { permissions: [...give], note: note || null })
      : api.post(`/access-requests/${r.id}/reject`, { note: note || null })),
    onSuccess: (res) => { toast.success(res.data?.message ?? 'Done.'); onDone(); },
    onError: (e) => setError(apiMessage(e, 'Could not save.')),
  });
  const pending = r.status === 'pending';
  const toggle = (n) => setGive((s) => { const x = new Set(s); x.has(n) ? x.delete(n) : x.add(n); return x; });

  return (
    <Card className="p-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <div className="font-semibold">{r.user?.name}</div>
          <div className="text-xs text-muted">{r.user?.email} · asked {formatDateTime(r.created_at)}{r.company ? ` · ${r.company}` : ''}</div>
        </div>
        <Badge tone={tone[r.status]}>{word[r.status] ?? r.status}</Badge>
      </div>
      {r.reason && <p className="mt-2 rounded-xl bg-paper/60 px-3 py-2 text-sm">“{r.reason}”</p>}
      <div className="mt-3 space-y-1">
        {r.permissions.map((p) => (
          <label key={p.name} className="flex items-start gap-2 text-sm">
            {pending
              ? <input type="checkbox" className="mt-0.5 size-4" checked={give.has(p.name)} disabled={!p.can_grant} onChange={() => toggle(p.name)} />
              : p.granted ? <CheckIcon className="mt-0.5 size-4 text-leaf" /> : <span className="mt-0.5 inline-block size-4" />}
            <span className={!pending && !p.granted ? 'text-muted line-through' : ''}>
              <span className="text-xs text-muted">{p.module} · </span>{p.label}
              {pending && !p.can_grant && <span className="ml-1 text-xs text-amber"> — only HO can give this (you do not have it)</span>}
            </span>
          </label>
        ))}
      </div>
      {pending ? (
        <div className="mt-3 flex flex-wrap items-center gap-2">
          <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Note to the user (optional)" className="min-w-[200px] flex-1" />
          <Button variant="outline" size="sm" disabled={act.isPending} onClick={() => act.mutate('reject')}>Reject</Button>
          <Button size="sm" disabled={act.isPending || give.size === 0} onClick={() => act.mutate('approve')}>
            {give.size === r.permissions.length ? 'Approve' : `Approve ${give.size} of ${r.permissions.length}`}
          </Button>
        </div>
      ) : r.decided_by && (
        <p className="mt-2 text-xs text-muted">{word[r.status]} by {r.decided_by} on {formatDateTime(r.decided_at)}{r.decision_note ? ` — “${r.decision_note}”` : ''}</p>
      )}
      {error && <p className="mt-2 text-sm text-danger">{error}</p>}
    </Card>
  );
}

export default function AccessRequestsPage() {
  const { activeCompany } = useAuth();
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('pending');
  const [page, setPage] = useState(1);
  const { data, isLoading } = useQuery({
    queryKey: ['access-requests', activeCompany?.id, status, page],
    queryFn: () => api.get('/access-requests', { params: { status, page } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
  });
  const rows = data?.data ?? [];
  const done = () => {
    queryClient.invalidateQueries({ queryKey: ['access-requests'] });
    queryClient.invalidateQueries({ queryKey: ['access-notices'] });
  };

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <PageHeader icon={InboxArrowDownIcon} title="Access requests"
        subtitle="Staff asking to do more. Tick what to give and approve, or reject with a note. Given access is for that person in this company only; their role does not change." />
      <div className="no-scrollbar flex gap-1 overflow-x-auto border-b border-line">
        {TABS.map(([v, l]) => (
          <button key={v} onClick={() => { setStatus(v); setPage(1); }}
            className={'border-b-2 px-3 py-2 text-sm ' + (status === v ? 'border-leaf font-medium text-leaf' : 'border-transparent text-muted hover:text-ink')}>{l}</button>
        ))}
      </div>
      {isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
        : rows.length === 0 ? <Card className="p-10 text-center text-sm text-muted">{status === 'pending' ? 'No requests waiting. All caught up.' : 'Nothing here.'}</Card>
        : (
          <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
            {rows.map((r) => <RequestCard key={r.id} r={r} onDone={done} />)}
          </div>
        )}
      <Pagination meta={data?.meta} onPage={setPage} />
    </div>
  );
}
