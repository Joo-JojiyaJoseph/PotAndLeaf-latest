import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { CheckCircleIcon, PlusIcon, XCircleIcon, PaperAirplaneIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { Badge, Button, Card, Field, Input, Modal, Spinner } from '../../components/ui';
import { formatDate } from '../../lib/format';

const STATUS_TABS = [
  { value: '', label: 'All' },
  { value: 'draft', label: 'Draft' },
  { value: 'submitted', label: 'Submitted' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
];

export default function StockVerificationsList() {
  const { activeCompany, can } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');
  const [rejecting, setRejecting] = useState(null); // verification being rejected
  const [reason, setReason] = useState('');

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['stock-verifications'] });
    queryClient.invalidateQueries({ queryKey: ['inventory'] });
  };

  const { data, isLoading, isError } = useQuery({
    queryKey: ['stock-verifications', activeCompany?.id, filterCompanyId, status],
    queryFn: () => api.get('/stock-verifications', { params: { ...companyParams, status } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });

  const submitM = useMutation({ mutationFn: (id) => api.post(`/stock-verifications/${id}/submit`), onSuccess: invalidate });
  const approveM = useMutation({ mutationFn: (id) => api.post(`/stock-verifications/${id}/approve`), onSuccess: invalidate });
  const rejectM = useMutation({
    mutationFn: ({ id, reason }) => api.post(`/stock-verifications/${id}/reject`, { reason }),
    onSuccess: () => {
      invalidate();
      setRejecting(null);
      setReason('');
    },
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Stock verification</h1>
          <p className="text-sm text-muted">Physical counts. HO approval adjusts system stock to the counted figures{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            {/* <Filter /> */}
          {can('stock_verifications.create') && (
            <Link to="/stock-verifications/new">
              <Button size="sm">
                <PlusIcon className="size-4" /> New count
              </Button>
            </Link>
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line">
        <div className="flex gap-1">
          {STATUS_TABS.map((tab) => (
            <button
              key={tab.value}
              onClick={() => setStatus(tab.value)}
              className={
                'border-b-2 px-3 py-2 text-sm transition-colors ' +
                (status === tab.value ? 'border-leaf font-medium text-leaf' : 'border-transparent text-muted hover:text-ink')
              }
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      <Card className="overflow-hidden">
        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner className="size-6" />
          </div>
        ) : isError ? (
          <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load stock counts.</div>
        ) : rows.length === 0 ? (
          <div className="px-4 py-16 text-center">
            <p className="text-sm font-medium">No counts here</p>
            <p className="mt-1 text-sm text-muted">Start a physical count to reconcile stock.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">No.</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Location</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Items</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                  <th className="microlabel px-4 py-2.5" />
                </tr>
              </thead>
              <tbody>
                {rows.map((v) => (
                  <tr key={v.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                    <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(`/stock-verifications/${v.id}`)} className="font-medium text-ink hover:text-leaf">{v.count_no}</button></td>
                    <td className="px-4 py-2.5 text-muted">{formatDate(v.count_date)}</td>
                    <td className="px-4 py-2.5 text-muted">{v.location_note || '—'}</td>
                    <td className="tnum px-4 py-2.5 text-right text-muted">{v.items_count ?? '—'}</td>
                    <td className="px-4 py-2.5">
                      <Badge tone={v.status}>{v.status}</Badge>
                    </td>
                    <td className="px-4 py-2.5">
                      <div className="flex items-center justify-end gap-2">
                        {v.can?.submit && (
                          <Button variant="outline" size="sm" onClick={() => submitM.mutate(v.id)} disabled={submitM.isPending}>
                            <PaperAirplaneIcon className="size-4" /> Submit
                          </Button>
                        )}
                        {v.can?.reject && (
                          <Button variant="ghost" size="sm" onClick={() => setRejecting(v)}>
                            <XCircleIcon className="size-4" /> Reject
                          </Button>
                        )}
                        {v.can?.approve && (
                          <Button size="sm" onClick={() => approveM.mutate(v.id)} disabled={approveM.isPending}>
                            <CheckCircleIcon className="size-4" /> Approve
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Modal
        open={Boolean(rejecting)}
        onClose={() => setRejecting(null)}
        title={rejecting ? `Reject ${rejecting.count_no}` : ''}
        footer={
          <>
            <Button variant="ghost" size="sm" onClick={() => setRejecting(null)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              size="sm"
              disabled={!reason.trim() || rejectM.isPending}
              onClick={() => rejectM.mutate({ id: rejecting.id, reason })}
            >
              Reject count
            </Button>
          </>
        }
      >
        <Field label="Reason" required>
          <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why is this count being rejected?" />
        </Field>
      </Modal>
    </div>
  );
}