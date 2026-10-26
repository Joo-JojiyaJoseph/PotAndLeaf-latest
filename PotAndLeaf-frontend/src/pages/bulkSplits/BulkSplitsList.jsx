import { useState, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import { CheckCircleIcon, PlusIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import useCompanyFilter from '../../hooks/useCompanyFilter';
import { recordDetailPath, resolveRecordCompany } from '../../lib/recordCompany';
import { Badge, Button, Card, Input, Spinner } from '../../components/ui';
import { formatCurrency, formatDate } from '../../lib/format';
import { ListToolbar, useListFilters } from '../../components/ListToolbar';
import { exportList } from '../../lib/exportList';
import Pagination from '../../components/Pagination';

const STATUS_TABS = [
  { value: '', label: 'All' },
  { value: 'draft', label: 'Draft' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'cancelled', label: 'Cancelled' },
];
const statusTone = { draft: 'inactive', confirmed: 'active', cancelled: 'blocked' };

export default function BulkSplitsList() {
  const { activeCompany, can, companyId } = useAuth();
  const { filterCompanyId, companyParams, companyHint, Filter } = useCompanyFilter();
  const recordCtx = { filterCompanyId, companyId };
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');
  const lf = useListFilters();
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 300);
    return () => clearTimeout(t);
  }, [search]);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['bulk-splits', activeCompany?.id, filterCompanyId, status, debounced, lf.key],
    queryFn: () => api.get('/bulk-splits', { params: { ...companyParams, status, search: debounced, ...lf.params } }).then((r) => r.data),
    enabled: Boolean(activeCompany),
    placeholderData: keepPreviousData,
  });

  const confirmM = useMutation({
    mutationFn: (s) => api.post(`/bulk-splits/${s.id}/confirm`, {}, withCompany(resolveRecordCompany(s, recordCtx))),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bulk-splits'] });
      queryClient.invalidateQueries({ queryKey: ['inventory'] });
    },
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="page-title">Bulk splitting</h1>
          <p className="text-sm text-muted">Turn one big lot (e.g. a sack or a tray) into smaller items you sell. Stock changes when you confirm{companyHint}.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
            <Filter />
          {can('bulk_splits.create') && (
            <Link to="/bulk-splits/new"><Button size="sm"><PlusIcon className="size-4" /> New split</Button></Link>
          )}
        </div>
      </div>

      <div className="relative max-w-md">
        <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search split no…" className="pl-9" />
      </div>

      <ListToolbar lf={lf} placeholder={''} withSearch={false}
        onExport={() => exportList({ url: '/bulk-splits', params: { ...companyParams, status, ...lf.filters, search: debounced }, filename: 'bulk-splits', columns: [['Split no', (r) => r.split_no], ['Date', (r) => r.split_date], ['Bulk item', (r) => r.source_product_name], ['Qty used', (r) => r.source_qty], ['Pieces made', (r) => r.split_total_qty], ['Cost', (r) => r.total_cost], ['Status', (r) => r.status]] })} />

      <div className="no-scrollbar flex gap-1 overflow-x-auto border-b border-line">
        {STATUS_TABS.map((tab) => (
          <button key={tab.value} onClick={() => setStatus(tab.value)}
            className={'border-b-2 px-3 py-2 text-sm transition-colors ' + (status === tab.value ? 'border-leaf font-medium text-leaf' : 'border-transparent text-muted hover:text-ink')}>
            {tab.label}
          </button>
        ))}
      </div>

      <Card className="overflow-hidden">
        {isLoading ? (
          <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
        ) : isError ? (
          <div className="px-4 py-12 text-center text-sm text-muted">Couldn't load splits.</div>
        ) : rows.length === 0 ? (
          <div className="px-4 py-16 text-center">
            <p className="text-sm font-medium">No splits here</p>
            <p className="mt-1 text-sm text-muted">Split a bulk product into smaller units.</p>{can('bulk_splits.create') && <Link to="/bulk-splits/new" className="mt-4 inline-flex items-center gap-1 rounded-xl bg-leaf px-4 py-2 text-sm font-medium text-white hover:brightness-105">+ New bulk split</Link>}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">No.</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Source</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Qty</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Cost</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Outputs</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Status</th>
                  <th className="microlabel px-4 py-2.5" />
                </tr>
              </thead>
              <tbody>
                {rows.map((s) => (
                  <tr key={s.id} className="border-b border-line/60 last:border-0 hover:bg-sidebar/60">
                    <td className="tnum px-4 py-2.5 text-xs"><button onClick={() => navigate(recordDetailPath('/bulk-splits', s, recordCtx))} className="font-medium text-ink hover:text-leaf">{s.split_no}</button></td>
                    <td className="px-4 py-2.5 text-muted">{formatDate(s.split_date)}</td>
                    <td className="px-4 py-2.5 font-medium">{s.source_product_name}</td>
                    <td className="tnum px-4 py-2.5 text-right text-muted">{s.source_qty}</td>
                    <td className="tnum px-4 py-2.5 text-right">{formatCurrency(s.total_cost)}</td>
                    <td className="tnum px-4 py-2.5 text-right text-muted">{s.items_count ?? '—'}</td>
                    <td className="px-4 py-2.5"><Badge tone={statusTone[s.status] ?? 'default'}>{s.status}</Badge></td>
                    <td className="px-4 py-2.5 text-right">
                      {s.can?.confirm && (
                        <Button size="sm" onClick={() => confirmM.mutate(s)} disabled={confirmM.isPending}>
                          <CheckCircleIcon className="size-4" /> Confirm
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      {!isLoading && <div className="border-t border-line px-3"><Pagination meta={data?.meta} onPage={lf.setPage} /></div>}
        </Card>
    </div>
  );
}
