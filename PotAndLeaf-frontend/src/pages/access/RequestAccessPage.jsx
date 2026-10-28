import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { KeyIcon, MagnifyingGlassIcon, CheckIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../lib/toast';
import { apiMessage } from '../../lib/formErrors';
import { Badge, Button, Card, Field, Input, PageHeader, Spinner, Textarea } from '../../components/ui';
import { formatDateTime } from '../../lib/format';

const tone = { pending: 'pending', approved: 'active', rejected: 'blocked', cancelled: 'inactive' };
const word = { pending: 'waiting', approved: 'approved', rejected: 'rejected', cancelled: 'withdrawn' };

/**
 * Request access: tick the things you need to do, say why, send.
 * An admin approves (all or some) or rejects; the answer shows here and in the bell.
 */
export default function RequestAccessPage() {
  const { activeCompany } = useAuth();
  const toast = useToast();
  const queryClient = useQueryClient();
  const [params, setParams] = useSearchParams();
  const [picked, setPicked] = useState(() => new Set((params.get('need') ?? '').split(',').filter(Boolean)));
  const [reason, setReason] = useState('');
  const [search, setSearch] = useState('');
  const [error, setError] = useState('');

  const { data, isLoading, isError, error: loadError, refetch } = useQuery({
    queryKey: ['access-form', activeCompany?.id],
    queryFn: () => api.get('/access-requests/form-data').then((r) => r.data.data),
    enabled: Boolean(activeCompany),
  });

  const q = search.trim().toLowerCase();
  const groups = useMemo(() => (data?.groups ?? [])
    .map((g) => ({ ...g, permissions: g.permissions.filter((p) => !q || `${g.module} ${p.label}`.toLowerCase().includes(q)) }))
    .filter((g) => g.permissions.length), [data, q]);

  const sendM = useMutation({
    mutationFn: () => api.post('/access-requests', { permissions: [...picked], reason }),
    onSuccess: (res) => {
      toast.success(res.data?.message ?? 'Request sent.');
      setPicked(new Set()); setReason(''); setError(''); setParams({}, { replace: true });
      queryClient.invalidateQueries({ queryKey: ['access-form'] });
      queryClient.invalidateQueries({ queryKey: ['access-notices'] });
    },
    onError: (e) => setError(apiMessage(e, 'Could not send the request.')),
  });
  const withdrawM = useMutation({
    mutationFn: (id) => api.delete(`/access-requests/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['access-form'] }),
  });

  const toggle = (name) => setPicked((s) => { const n = new Set(s); n.has(name) ? n.delete(name) : n.add(name); return n; });
  const labelOf = (name) => (data?.groups ?? []).flatMap((g) => g.permissions).find((p) => p.name === name)?.label ?? name;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <PageHeader icon={KeyIcon} title="Request access"
        subtitle={`Ask for permission to do more in ${activeCompany?.name ?? 'this company'}. Tick what you need, say why, and send. An admin approves or rejects it.`} />

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
        <Card className="p-4">
          <div className="relative mb-3">
            <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Find a permission — e.g. reports, delete, stock…" className="pl-9" />
          </div>
          {isLoading ? <div className="flex justify-center py-10"><Spinner className="size-6" /></div> : isError ? (
            <div className="rounded-xl border border-danger/30 bg-danger/5 p-4 text-sm">
              <p className="font-medium text-danger">The list of permissions could not be loaded.</p>
              <p className="mt-1 text-muted">{apiMessage(loadError, 'The server did not answer.')}</p>
              <p className="mt-1 text-muted">If this keeps happening, ask the person who looks after the server to run <span className="font-mono text-xs">php artisan migrate</span> and <span className="font-mono text-xs">php artisan optimize:clear</span>.</p>
              <button type="button" onClick={() => refetch()} className="mt-2 text-sm font-medium text-leaf-hover underline">Try again</button>
            </div>
          ) : (
            <>
            {data?.ready === false && (
              <div className="mb-3 rounded-xl border border-warn/40 bg-warn/10 p-3 text-sm">
                Requests cannot be sent yet: the server is missing the Request access update. Ask the person who looks after the server to run <span className="font-mono text-xs">php artisan migrate</span>.
              </div>
            )}
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
              {groups.map((g) => (
                <div key={g.module} className="rounded-xl border border-line p-3">
                  <div className="mb-1.5 text-xs font-semibold text-ink">{g.module}</div>
                  <div className="space-y-1">
                    {g.permissions.map((p) => (
                      <label key={p.name} className={'flex items-start gap-2 rounded-lg px-1.5 py-1 text-sm ' + (p.has ? 'text-muted' : 'cursor-pointer hover:bg-leaf-soft/50')}>
                        {p.has
                          ? <CheckIcon className="mt-0.5 size-4 shrink-0 text-leaf" />
                          : <input type="checkbox" checked={picked.has(p.name)} onChange={() => toggle(p.name)} className="mt-0.5 size-4 shrink-0" />}
                        <span>{p.label}{p.has && <span className="ml-1 text-[11px]">(you have this)</span>}</span>
                      </label>
                    ))}
                  </div>
                </div>
              ))}
              {groups.length === 0 && q && <p className="py-6 text-center text-sm text-muted md:col-span-2">Nothing matches “{search}”.</p>}
            </div>
            </>
          )}
        </Card>

        <div className="space-y-4 lg:sticky lg:top-4 lg:self-start">
          <Card className="p-4">
            <div className="mb-2 text-sm font-semibold">Your request</div>
            {picked.size === 0 ? <p className="text-sm text-muted">Tick one or more permissions on the left.</p> : (
              <ul className="mb-3 space-y-1 text-sm">
                {[...picked].map((n) => (
                  <li key={n} className="flex items-center justify-between gap-2 rounded-lg bg-leaf-soft/50 px-2 py-1">
                    <span>{labelOf(n)}</span>
                    <button type="button" onClick={() => toggle(n)} className="text-xs text-muted hover:text-danger">remove</button>
                  </li>
                ))}
              </ul>
            )}
            <Field label="Why do you need it?" required>
              <Textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. I close the shop and need the day's sales report" />
            </Field>
            {error && <p className="mt-2 text-sm text-danger">{error}</p>}
            <Button className="mt-3 w-full" disabled={sendM.isPending || picked.size === 0 || !reason.trim()} onClick={() => sendM.mutate()}>
              {sendM.isPending ? <Spinner className="border-white/40 border-t-white" /> : `Send request${picked.size ? ` (${picked.size})` : ''}`}
            </Button>
          </Card>

          <Card className="p-4">
            <div className="mb-2 text-sm font-semibold">My requests</div>
            {(data?.mine ?? []).length === 0 ? <p className="text-sm text-muted">You have not asked for anything yet.</p> : (
              <ul className="space-y-3">
                {data.mine.map((r) => (
                  <li key={r.id} className="rounded-xl border border-line p-3 text-sm">
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-xs text-muted">{formatDateTime(r.created_at)}</span>
                      <Badge tone={tone[r.status]}>{word[r.status] ?? r.status}</Badge>
                    </div>
                    <ul className="mt-1.5 space-y-0.5">
                      {r.permissions.map((p) => (
                        <li key={p.name} className={r.status === 'approved' && !p.granted ? 'text-muted line-through' : ''}>
                          {r.status === 'approved' && p.granted && <CheckIcon className="mr-1 inline size-3.5 text-leaf" />}{p.label}
                        </li>
                      ))}
                    </ul>
                    {r.decided_by && <p className="mt-1.5 text-xs text-muted">{word[r.status]} by {r.decided_by}{r.decision_note ? ` — “${r.decision_note}”` : ''}</p>}
                    {r.status === 'approved' && <p className="mt-1 text-xs text-leaf-hover">You can use the new access now (the menu updates within a minute).</p>}
                    {r.status === 'pending' && (
                      <button type="button" onClick={() => withdrawM.mutate(r.id)} className="mt-1.5 text-xs text-muted underline hover:text-danger">Withdraw</button>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>
    </div>
  );
}
