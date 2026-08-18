import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDownTrayIcon,
    MagnifyingGlassIcon,
    PencilSquareIcon,
    PlusIcon,
    TrashIcon,
    UsersIcon,
} from '@heroicons/react/24/outline';
import Swal from 'sweetalert2';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PageHeader from '@/components/nursery/PageHeader';
import StatusBadge from '@/components/nursery/StatusBadge';
import DataTable from '@/components/nursery/DataTable';
import { formatCurrency } from '@/lib/format';

export default function SuppliersIndex({
    team,
    suppliers,
    filters = {},
    statusOptions = [],
}) {
    const base = `/${team}/suppliers`;
    const rows = suppliers.data ?? [];
    const meta = suppliers.meta ?? null;

    const [search, setSearch] = useState(filters.search ?? '');
    const [selected, setSelected] = useState([]);

    // Merge changed params with the existing query and hit the server.
    function query(params) {
        router.get(
            base,
            { ...filters, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function submitSearch(event) {
        event.preventDefault();
        query({ search, page: 1 });
    }

    function handleSort(columnId) {
        const dir =
            filters.sort === columnId && filters.dir === 'asc' ? 'desc' : 'asc';
        query({ sort: columnId, dir });
    }

    function confirmDelete(supplier) {
        Swal.fire({
            title: `Delete ${supplier.name}?`,
            text: 'It will be moved to trash and can be restored later.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#DC2626',
            cancelButtonColor: '#6B7280',
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(`${base}/${supplier.id}`, { preserveScroll: true });
            }
        });
    }

    const columns = [
        {
            id: 'supplier_code',
            header: 'Code',
            cell: ({ row }) => (
                <span className="font-medium text-foreground">
                    {row.original.supplier_code}
                </span>
            ),
        },
        {
            id: 'name',
            header: 'Supplier',
            cell: ({ row }) => (
                <div className="min-w-0">
                    <div className="truncate font-medium">{row.original.name}</div>
                    <div className="truncate text-xs text-muted-foreground">
                        {row.original.email || row.original.phone || '—'}
                    </div>
                </div>
            ),
        },
        {
            id: 'outstanding',
            header: 'Outstanding',
            cell: ({ row }) => (
                <span className="tabular-nums">
                    {formatCurrency(row.original.outstanding)}
                </span>
            ),
        },
        {
            id: 'status',
            header: 'Status',
            cell: ({ row }) => <StatusBadge status={row.original.status} />,
        },
        {
            id: 'actions',
            header: '',
            enableSorting: false,
            cell: ({ row }) => (
                <div className="flex items-center justify-end gap-1">
                    {row.original.can?.update && (
                        <Link href={`${base}/${row.original.id}/edit`}>
                            <Button variant="ghost" size="icon" aria-label="Edit">
                                <PencilSquareIcon className="size-4" />
                            </Button>
                        </Link>
                    )}
                    {row.original.can?.delete && (
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Delete"
                            onClick={() => confirmDelete(row.original)}
                        >
                            <TrashIcon className="size-4 text-danger" />
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    const emptyState = (
        <div className="flex flex-col items-center justify-center rounded-lg border border-dashed border-border bg-card py-16 text-center">
            <UsersIcon className="size-10 text-muted-foreground/50" />
            <h3 className="mt-3 text-sm font-medium text-foreground">
                No suppliers yet
            </h3>
            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                Add your first supplier to start recording purchases and
                tracking outstanding balances.
            </p>
            <Link href={`${base}/create`} className="mt-4">
                <Button>
                    <PlusIcon className="size-4" /> New supplier
                </Button>
            </Link>
        </div>
    );

    return (
        <>
            <Head title="Suppliers" />

            <div className="space-y-5 p-4 sm:p-6">
                <PageHeader
                    title="Suppliers"
                    description="Manage supplier master, statutory details and credit terms."
                    actions={
                        <>
                            <Button variant="outline" size="sm">
                                <ArrowDownTrayIcon className="size-4" /> Export
                            </Button>
                            <Link href={`${base}/create`}>
                                <Button size="sm">
                                    <PlusIcon className="size-4" /> New supplier
                                </Button>
                            </Link>
                        </>
                    }
                />

                {/* Toolbar: search + status filter */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <form onSubmit={submitSearch} className="relative flex-1">
                        <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search code, name, email or phone…"
                            className="pl-9"
                        />
                    </form>

                    <select
                        value={filters.status ?? ''}
                        onChange={(e) => query({ status: e.target.value, page: 1 })}
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm text-foreground focus:ring-2 focus:ring-ring"
                    >
                        <option value="">All statuses</option>
                        {statusOptions.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>

                {selected.length > 0 && (
                    <div className="flex items-center justify-between rounded-md bg-accent px-4 py-2 text-sm text-accent-foreground">
                        <span>{selected.length} selected</span>
                        <Button variant="destructive" size="sm">
                            <TrashIcon className="size-4" /> Delete selected
                        </Button>
                    </div>
                )}

                <DataTable
                    columns={columns}
                    data={rows}
                    meta={meta}
                    sort={filters.sort}
                    dir={filters.dir}
                    onSort={handleSort}
                    onPage={(page) => query({ page })}
                    selectable
                    onSelect={setSelected}
                    emptyState={rows.length === 0 ? emptyState : null}
                    mobileCard={(supplier) => (
                        <div className="space-y-2">
                            <div className="flex items-start justify-between">
                                <div className="min-w-0">
                                    <div className="truncate font-medium">
                                        {supplier.name}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {supplier.supplier_code}
                                    </div>
                                </div>
                                <StatusBadge status={supplier.status} />
                            </div>
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    Outstanding
                                </span>
                                <span className="tabular-nums">
                                    {formatCurrency(supplier.outstanding)}
                                </span>
                            </div>
                            <div className="flex gap-2 pt-1">
                                {supplier.can?.update && (
                                    <Link
                                        href={`${base}/${supplier.id}/edit`}
                                        className="flex-1"
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="w-full"
                                        >
                                            <PencilSquareIcon className="size-4" />{' '}
                                            Edit
                                        </Button>
                                    </Link>
                                )}
                                {supplier.can?.delete && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => confirmDelete(supplier)}
                                    >
                                        <TrashIcon className="size-4 text-danger" />
                                    </Button>
                                )}
                            </div>
                        </div>
                    )}
                />
            </div>
        </>
    );
}

SuppliersIndex.layout = (props) => ({
    breadcrumbs: [{ title: 'Suppliers', href: `/${props.team}/suppliers` }],
});
