import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    LockClosedIcon,
    MagnifyingGlassIcon,
    PencilSquareIcon,
    PlusIcon,
    ShieldCheckIcon,
    TrashIcon,
} from '@heroicons/react/24/outline';
import Swal from 'sweetalert2';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PageHeader from '@/components/nursery/PageHeader';
import DataTable from '@/components/nursery/DataTable';

export default function RolesIndex({ team, roles, filters = {} }) {
    const base = `/${team}/roles`;
    const rows = roles.data ?? [];
    const meta = roles.meta ?? null;
    const [search, setSearch] = useState(filters.search ?? '');

    function query(params) {
        router.get(base, { ...filters, ...params }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function confirmDelete(role) {
        Swal.fire({
            title: `Delete ${role.name}?`,
            text: 'Users lose the access this role granted. It can be restored later.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#DC2626',
            cancelButtonColor: '#6B7280',
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(`${base}/${role.id}`, { preserveScroll: true });
            }
        });
    }

    const columns = [
        {
            id: 'name',
            header: 'Role',
            cell: ({ row }) => (
                <div className="flex items-center gap-2">
                    <span className="flex size-8 items-center justify-center rounded-md bg-accent text-primary">
                        <ShieldCheckIcon className="size-4" />
                    </span>
                    <div>
                        <div className="flex items-center gap-1.5 font-medium">
                            {row.original.name}
                            {row.original.is_system && (
                                <LockClosedIcon
                                    className="size-3.5 text-muted-foreground"
                                    title="System role"
                                />
                            )}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {row.original.slug}
                        </div>
                    </div>
                </div>
            ),
        },
        {
            id: 'permissions',
            header: 'Permissions',
            enableSorting: false,
            cell: ({ row }) => {
                const perms = row.original.permissions ?? [];
                const label = perms.includes('*')
                    ? 'Full access'
                    : `${perms.length} permission${perms.length === 1 ? '' : 's'}`;
                return <span className="text-sm text-muted-foreground">{label}</span>;
            },
        },
        {
            id: 'users_count',
            header: 'Users',
            enableSorting: false,
            cell: ({ row }) => (
                <span className="tabular-nums">{row.original.users_count ?? 0}</span>
            ),
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

    return (
        <>
            <Head title="Roles" />

            <div className="space-y-5 p-4 sm:p-6">
                <PageHeader
                    title="Roles &amp; permissions"
                    description="Define what each role can do; assign roles to people under Users."
                    actions={
                        <>
                            <Link href={`/${team}/permissions`}>
                                <Button variant="outline" size="sm">
                                    View permission catalog
                                </Button>
                            </Link>
                            <Link href={`${base}/create`}>
                                <Button size="sm">
                                    <PlusIcon className="size-4" /> New role
                                </Button>
                            </Link>
                        </>
                    }
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        query({ search, page: 1 });
                    }}
                    className="relative max-w-md"
                >
                    <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search roles…"
                        className="pl-9"
                    />
                </form>

                <DataTable
                    columns={columns}
                    data={rows}
                    meta={meta}
                    sort={filters.sort}
                    dir={filters.dir}
                    onSort={(colId) =>
                        query({
                            sort: colId,
                            dir:
                                filters.sort === colId && filters.dir === 'asc'
                                    ? 'desc'
                                    : 'asc',
                        })
                    }
                    onPage={(page) => query({ page })}
                    mobileCard={(role) => (
                        <div className="flex items-center justify-between">
                            <div>
                                <div className="font-medium">{role.name}</div>
                                <div className="text-xs text-muted-foreground">
                                    {role.permissions?.includes('*')
                                        ? 'Full access'
                                        : `${role.permissions?.length ?? 0} permissions`}{' '}
                                    · {role.users_count ?? 0} users
                                </div>
                            </div>
                            {role.can?.update && (
                                <Link href={`${base}/${role.id}/edit`}>
                                    <Button variant="ghost" size="icon">
                                        <PencilSquareIcon className="size-4" />
                                    </Button>
                                </Link>
                            )}
                        </div>
                    )}
                />
            </div>
        </>
    );
}

RolesIndex.layout = (props) => ({
    breadcrumbs: [{ title: 'Roles', href: `/${props.team}/roles` }],
});
