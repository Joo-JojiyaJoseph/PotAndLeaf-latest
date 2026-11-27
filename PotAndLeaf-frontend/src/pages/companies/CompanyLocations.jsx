import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { BuildingStorefrontIcon, HomeModernIcon, PencilSquareIcon, PlusIcon, SparklesIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import LocationFormModal, { blankLocation, typeLabel } from './LocationFormModal';

/** Branches and godowns of one company, with add / edit right on the company page (super admin). */
export default function CompanyLocations({ companyId }) {
  const [modal, setModal] = useState({ open: false, initial: blankLocation('shop'), editingId: null });

  const q = useQuery({
    queryKey: ['locations', 'company', companyId],
    queryFn: () => api.get('/locations', { params: { company_id: companyId } }).then((r) => r.data.data),
    enabled: Boolean(companyId),
  });
  const rows = q.data ?? [];
  const branches = rows.filter((l) => l.type !== 'godown');
  const godowns = rows.filter((l) => l.type === 'godown');

  const openNew = (type) => setModal({ open: true, initial: blankLocation(type), editingId: null });
  const openEdit = (l) => setModal({ open: true, initial: { ...blankLocation(l.type), ...l }, editingId: l.id });

  const card = (l) => (
    <Card key={l.id} className={`p-4 ${l.is_active ? '' : 'opacity-60'}`}>
      <div className="flex items-start justify-between gap-2">
        <div className="flex min-w-0 items-start gap-3">
          <span className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${l.type === 'godown' ? 'bg-paper text-muted' : 'bg-leaf-soft text-leaf'}`}>
            {l.type === 'godown' ? <HomeModernIcon className="size-5" /> : <BuildingStorefrontIcon className="size-5" />}
          </span>
          <div className="min-w-0">
            <div className="truncate font-medium">{l.name}</div>
            <div className="tnum text-xs text-muted">{l.code}</div>
          </div>
        </div>
        <button type="button" onClick={() => openEdit(l)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink" aria-label={`Edit ${l.name}`}><PencilSquareIcon className="size-4" /></button>
      </div>
      <div className="mt-3 flex flex-wrap gap-1.5">
        <Badge tone={l.type === 'godown' ? 'default' : 'info'}>{typeLabel(l.type)}</Badge>
        {l.is_default && <Badge tone="active">Main</Badge>}
        {l.is_landscape && <Badge tone="active"><SparklesIcon className="mr-1 inline size-3" />Landscape division</Badge>}
        {!l.is_active && <Badge tone="inactive">Inactive</Badge>}
      </div>
    </Card>
  );

  return (
    <section>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="text-sm font-semibold">Branches &amp; godowns</h2>
          <p className="text-xs text-muted">Shops that sell and store rooms that hold stock. Add them here; people are assigned to a location on the Users page.</p>
        </div>
        <div className="flex gap-2">
          <Button size="sm" variant="outline" onClick={() => openNew('godown')}><PlusIcon className="size-4" /> Add godown</Button>
          <Button size="sm" onClick={() => openNew('shop')}><PlusIcon className="size-4" /> Add branch</Button>
        </div>
      </div>

      {q.isLoading ? <div className="flex justify-center py-10"><Spinner className="size-5" /></div> : (
        <div className="space-y-4">
          <div>
            <div className="microlabel mb-2 text-faint">Branches ({branches.length})</div>
            {branches.length === 0 ? <p className="text-sm text-muted">No branch yet.</p> : <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">{branches.map(card)}</div>}
          </div>
          <div>
            <div className="microlabel mb-2 text-faint">Godowns ({godowns.length})</div>
            {godowns.length === 0 ? <p className="text-sm text-muted">No godown yet.</p> : <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">{godowns.map(card)}</div>}
          </div>
        </div>
      )}

      <LocationFormModal open={modal.open} onClose={() => setModal((m) => ({ ...m, open: false }))} companyId={companyId} initial={modal.initial} editingId={modal.editingId} existingCodes={rows.map((r) => r.code)} />
    </section>
  );
}
