import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircleIcon, PaperAirplaneIcon, XCircleIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { Badge, Button, Field, Input, Modal } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { formatDate, classNames } from '../../lib/format';

const statusTone = { draft: 'inactive', submitted: 'submitted', approved: 'approved', rejected: 'rejected', cancelled: 'blocked' };
const varClass = (v) => (Math.abs(v) < 1e-6 ? 'text-muted' : v < 0 ? 'text-danger' : 'text-amber');

export default function StockVerificationDetail() {
  const { id } = useParams();
  const queryClient = useQueryClient();
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');

  const { data, isLoading, isError } = useQuery({
    queryKey: ['stock-verification', id],
    queryFn: () => api.get(`/stock-verifications/${id}`).then((r) => r.data.data),
  });
  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['stock-verification', id] });
    queryClient.invalidateQueries({ queryKey: ['stock-verifications'] });
    queryClient.invalidateQueries({ queryKey: ['inventory'] });
  };
  const submitM = useMutation({ mutationFn: () => api.post(`/stock-verifications/${id}/submit`), onSuccess: invalidate });
  const approveM = useMutation({ mutationFn: () => api.post(`/stock-verifications/${id}/approve`), onSuccess: invalidate });
  const rejectM = useMutation({
    mutationFn: () => api.post(`/stock-verifications/${id}/reject`, { reason }),
    onSuccess: () => { invalidate(); setRejecting(false); setReason(''); },
  });

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/stock-verifications" />;
  const v = data;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={`Stock count ${v.count_no}`}
        subtitle={`${formatDate(v.count_date)}${v.location_note ? ` · ${v.location_note}` : ''}`}
        backTo="/stock-verifications"
        actions={
          <>
            <Badge tone={statusTone[v.status] ?? 'default'}>{v.status}</Badge>
            {v.can?.submit && <Button variant="outline" size="sm" onClick={() => submitM.mutate()} disabled={submitM.isPending}><PaperAirplaneIcon className="size-4" /> Submit</Button>}
            {v.can?.reject && <Button variant="ghost" size="sm" onClick={() => setRejecting(true)}><XCircleIcon className="size-4" /> Reject</Button>}
            {v.can?.approve && <Button size="sm" onClick={() => approveM.mutate()} disabled={approveM.isPending}><CheckCircleIcon className="size-4" /> Approve</Button>}
          </>
        }
      />

      <Section title="Details">
        <InfoGrid cols={4}>
          <InfoItem label="Count date" value={formatDate(v.count_date)} />
          <InfoItem label="Location" value={v.location_note} />
          <InfoItem label="Submitted" value={v.submitted_at ? formatDate(v.submitted_at) : null} />
          <InfoItem label="Approved" value={v.approved_at ? formatDate(v.approved_at) : null} />
          {v.rejection_reason && <InfoItem label="Rejection reason" value={v.rejection_reason} />}
          <InfoItem label="Notes" value={v.notes} />
        </InfoGrid>
      </Section>

      <Section title="Counted items">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[480px] text-sm">
            <thead>
              <tr className="border-b border-line text-left text-faint">
                <th className="microlabel py-2 pr-3 font-semibold">Product</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">System</th>
                <th className="microlabel px-3 py-2 text-right font-semibold">Counted</th>
                <th className="microlabel py-2 pl-3 text-right font-semibold">Variance</th>
              </tr>
            </thead>
            <tbody>
              {(v.items ?? []).map((it) => (
                <tr key={it.id} className="border-b border-line/60 last:border-0">
                  <td className="py-2 pr-3 font-medium">{it.product_name}</td>
                  <td className="tnum px-3 py-2 text-right text-muted">{it.system_qty}</td>
                  <td className="tnum px-3 py-2 text-right">{it.counted_qty}</td>
                  <td className={classNames('tnum py-2 pl-3 text-right font-medium', varClass(it.variance))}>{it.variance > 0 ? '+' : ''}{it.variance}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Section>

      <Modal
        open={rejecting}
        onClose={() => setRejecting(false)}
        title={`Reject ${v.count_no}`}
        footer={
          <>
            <Button variant="ghost" size="sm" onClick={() => setRejecting(false)}>Cancel</Button>
            <Button variant="danger" size="sm" disabled={!reason.trim() || rejectM.isPending} onClick={() => rejectM.mutate()}>Reject count</Button>
          </>
        }
      >
        <Field label="Reason" required><Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why is this count being rejected?" /></Field>
      </Modal>
    </div>
  );
}
