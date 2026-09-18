import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { MagnifyingGlassIcon, PlusIcon, PencilSquareIcon, TrashIcon, ArrowDownTrayIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { recordDetailPath, resolveRecordCompany, defaultCreateCompanyId } from '../../lib/recordCompany';
import { Badge, Button, Card, Input, Spinner, Select } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';
import { downloadCsv } from '../../lib/csv';
import { useConfirm } from '../../lib/confirm';
import { useToast } from '../../lib/toast';
import { apiMessage } from '../../lib/formErrors';

const statusTone = { draft: 'inactive', in_progress: 'warning', completed: 'active', cancelled: 'blocked' };

export default function ProductionList() {
  const { activeCompany, can, companyId, isSuperAdmin } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const recordCtx = { filterCompanyId, companyId };
  const createCompanyId = defaultCreateCompanyId({ filterCompanyId, companyId });
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const confirm = useConfirm();
  const toast = useToast();
  const [status, setStatus] = useState('');
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim().toLowerCase()), 300);
    return () => clearTimeout(t);
  }, [search]);

  const ordersQ = useQuery({
    queryKey: ['production-orders', activeCompany?.id, filterCompanyId, status],
    queryFn: () => api.get('/production/orders', { params: { ...companyParams, status: status || undefined, per_page: 100 } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
  });

  const deleteM = useMutation({
    mutationFn: (o) => api.delete(`/production/orders/${o.id}`, withCompany(resolveRecordCompany(o, recordCtx))),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['production-orders'] });
      toast.success('Production deleted.');
    },
    onError: (err) => toast.error(apiMessage(err, 'Could not delete production.')),
  });

  const orders = (ordersQ.data?.data ?? []).filter((o) => {
    if (!debounced) return true;
    return [o.order_no, o.output_product, o.supervisor].some((v) => String(v ?? '').toLowerCase().includes(debounced));
  });

  const newPath = createCompanyId && isSuperAdmin ? `/production/new?company_id=${createCompanyId}` : '/production/new';

  async function onDelete(o) {
    const ok = await confirm({
      title: 'Delete production?',
      message: 'Are you sure you want to delete this production?',
      confirmLabel: 'Delete',
    });
    if (ok) deleteM.mutate(o);
  }

  function exportOrders() {
    if (orders.length === 0) return;
    downloadCsv('production', orders.map((o) => ({
      'No': o.order_no,
      'Date': o.order_date,
      'Product': o.output_product,
      'Quantity': o.output_quantity,
      'Unit cost': o.status === 'completed' ? o.output_unit_cost : '',
      'Total cost': o.status === 'completed' ? o.total_input_cost : '',
      'Supervisor': o.supervisor || '',
      'Status': o.status,
    })));
  }

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="page-title">Production</h1>
          <p className="text-sm text-muted">Create production, check material stock, and complete it in one place{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Filter />
          {can('production.create') && (
            <Button size="sm" onClick={() => navigate(newPath)}>
              <PlusIcon className="size-4" /> Add new production
            </Button>
          )}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <div className="relative max-w-md flex-1">
          <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search production…" className="pl-9" />
        </div>
        <Select value={status} onChange={(e) => setStatus(e.target.value)} className="h-10 w-44 rounded-xl border border-line bg-surface px-3 text-sm">
          <option value="">All statuses</option>
          <option value="draft">Draft</option>
          <option value="in_progress">In progress</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
        </Select>
        <Button variant="outline" size="sm" onClick={exportOrders} disabled={orders.length === 0}>
          <ArrowDownTrayIcon className="size-4" /> Export CSV
        </Button>
      </div>

      <Card className="overflow-hidden">
        {ordersQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
          : orders.length === 0 ? (
            <div className="px-4 py-16 text-center">
              <p className="text-sm font-medium">No production yet</p>
              <p className="mt-1 text-sm text-muted">Add a production to consume materials and raise finished stock.</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">No.</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Product</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Qty</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Unit cost</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Supervisor</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                  <th className="microlabel px-4 py-2.5" />
                </tr></thead>
                <tbody>
                  {orders.map((o) => (
                    <tr key={o.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                      <td className="tnum px-4 py-2.5 text-xs">
                        <button onClick={() => navigate(recordDetailPath('/production/orders', o, recordCtx))} className="font-medium text-ink hover:text-leaf">{o.order_no}</button>
                      </td>
                      <td className="px-4 py-2.5 text-muted">{formatDate(o.order_date)}</td>
                      <td className="px-4 py-2.5 font-medium">{o.output_product}</td>
                      <td className="tnum px-4 py-2.5 text-right">{o.output_quantity}</td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{o.status === 'completed' ? formatCurrency(o.output_unit_cost) : '—'}</td>
                      <td className="px-4 py-2.5 text-muted">{o.supervisor || '—'}</td>
                      <td className="px-4 py-2.5"><Badge tone={statusTone[o.status] ?? 'default'}>{o.status.replace('_', ' ')}</Badge></td>
                      <td className="px-4 py-2.5 text-right">
                        <div className="flex justify-end gap-1">
                          {o.can?.update && (
                            <button onClick={() => navigate(o.company_id ? `/production/${o.id}/edit?company_id=${o.company_id}` : `/production/${o.id}/edit`)} title="Edit" className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink">
                              <PencilSquareIcon className="size-4" />
                            </button>
                          )}
                          {o.can?.cancel && (
                            <button onClick={() => onDelete(o)} title="Delete" className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger">
                              <TrashIcon className="size-4" />
                            </button>
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
    </div>
  );
}
