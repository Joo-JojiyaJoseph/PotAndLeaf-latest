import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDownTrayIcon,
    CubeIcon,
    ExclamationTriangleIcon,
    MagnifyingGlassIcon,
    PencilSquareIcon,
    PlusIcon,
    TrashIcon,
} from '@heroicons/react/24/outline';
import Swal from 'sweetalert2';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PageHeader from '@/components/nursery/PageHeader';
import StatusBadge from '@/components/nursery/StatusBadge';
import DataTable from '@/components/nursery/DataTable';
import { formatCurrency } from '@/lib/format';

export default function ProductsIndex({
    team,
    products,
    filters = {},
    statusOptions = [],
    categories = [],
    brands = [],
}) {
    const base = `/${team}/products`;
    const rows = products.data ?? [];
    const meta = products.meta ?? null;

    const [search, setSearch] = useState(filters.search ?? '');
    const [selected, setSelected] = useState([]);

    function query(params) {
        router.get(base, { ...filters, ...params }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function handleSort(columnId) {
        const dir =
            filters.sort === columnId && filters.dir === 'asc' ? 'desc' : 'asc';
        query({ sort: columnId, dir });
    }

    function confirmDelete(product) {
        Swal.fire({
            title: `Delete ${product.name}?`,
            text: 'It will be moved to trash and can be restored later.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#DC2626',
            cancelButtonColor: '#6B7280',
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(`${base}/${product.id}`, { preserveScroll: true });
            }
        });
    }

    function Thumb({ product }) {
        const src = product.images?.[0];
        if (src) {
            return (
                <img
                    src={src}
                    alt={product.name}
                    className="size-9 rounded-md object-cover"
                />
            );
        }
        return (
            <div className="flex size-9 items-center justify-center rounded-md bg-muted text-muted-foreground">
                <CubeIcon className="size-4" />
            </div>
        );
    }

    const columns = [
        {
            id: 'name',
            header: 'Product',
            cell: ({ row }) => (
                <div className="flex items-center gap-3">
                    <Thumb product={row.original} />
                    <div className="min-w-0">
                        <div className="truncate font-medium">{row.original.name}</div>
                        <div className="truncate text-xs text-muted-foreground">
                            {row.original.sku}
                            {row.original.category ? ` · ${row.original.category}` : ''}
                        </div>
                    </div>
                </div>
            ),
        },
        {
            id: 'retail_price',
            header: 'Retail',
            cell: ({ row }) => (
                <span className="tabular-nums">
                    {formatCurrency(row.original.retail_price)}
                </span>
            ),
        },
        {
            id: 'current_stock',
            header: 'Stock',
            cell: ({ row }) => (
                <span
                    className={`inline-flex items-center gap-1 tabular-nums ${
                        row.original.is_low_stock ? 'text-warning' : ''
                    }`}
                >
                    {row.original.is_low_stock && (
                        <ExclamationTriangleIcon className="size-4" />
                    )}
                    {row.original.current_stock}
                    {row.original.unit ? ` ${row.original.unit}` : ''}
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
            <CubeIcon className="size-10 text-muted-foreground/50" />
            <h3 className="mt-3 text-sm font-medium">No products yet</h3>
            <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                Create your first product to manage pricing, stock and suppliers.
            </p>
            <Link href={`${base}/create`} className="mt-4">
                <Button>
                    <PlusIcon className="size-4" /> New product
                </Button>
            </Link>
        </div>
    );

    return (
        <>
            <Head title="Products" />

            <div className="space-y-5 p-4 sm:p-6">
                <PageHeader
                    title="Products"
                    description="Product master with pricing, stock levels and supplier sourcing."
                    actions={
                        <>
                            <Button variant="outline" size="sm">
                                <ArrowDownTrayIcon className="size-4" /> Export
                            </Button>
                            <Link href={`${base}/create`}>
                                <Button size="sm">
                                    <PlusIcon className="size-4" /> New product
                                </Button>
                            </Link>
                        </>
                    }
                />

                <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            query({ search, page: 1 });
                        }}
                        className="relative flex-1"
                    >
                        <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search name, SKU, barcode or HSN…"
                            className="pl-9"
                        />
                    </form>

                    <select
                        value={filters.category_id ?? ''}
                        onChange={(e) => query({ category_id: e.target.value, page: 1 })}
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm focus:ring-2 focus:ring-ring"
                    >
                        <option value="">All categories</option>
                        {categories.map((c) => (
                            <option key={c.value} value={c.value}>
                                {c.label}
                            </option>
                        ))}
                    </select>

                    <select
                        value={filters.status ?? ''}
                        onChange={(e) => query({ status: e.target.value, page: 1 })}
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm focus:ring-2 focus:ring-ring"
                    >
                        <option value="">All statuses</option>
                        {statusOptions.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </select>

                    <label className="flex items-center gap-2 whitespace-nowrap text-sm text-muted-foreground">
                        <input
                            type="checkbox"
                            checked={filters.low_stock === '1'}
                            onChange={(e) =>
                                query({ low_stock: e.target.checked ? '1' : '', page: 1 })
                            }
                            className="size-4 rounded border-input text-primary focus:ring-ring"
                        />
                        Low stock only
                    </label>
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
                    mobileCard={(product) => (
                        <div className="space-y-2">
                            <div className="flex items-start gap-3">
                                <Thumb product={product} />
                                <div className="min-w-0 flex-1">
                                    <div className="truncate font-medium">
                                        {product.name}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {product.sku}
                                    </div>
                                </div>
                                <StatusBadge status={product.status} />
                            </div>
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">Retail</span>
                                <span className="tabular-nums">
                                    {formatCurrency(product.retail_price)}
                                </span>
                            </div>
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">Stock</span>
                                <span
                                    className={`tabular-nums ${product.is_low_stock ? 'text-warning' : ''}`}
                                >
                                    {product.current_stock}
                                </span>
                            </div>
                            <div className="flex gap-2 pt-1">
                                {product.can?.update && (
                                    <Link
                                        href={`${base}/${product.id}/edit`}
                                        className="flex-1"
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="w-full"
                                        >
                                            <PencilSquareIcon className="size-4" /> Edit
                                        </Button>
                                    </Link>
                                )}
                                {product.can?.delete && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => confirmDelete(product)}
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

ProductsIndex.layout = (props) => ({
    breadcrumbs: [{ title: 'Products', href: `/${props.team}/products` }],
});
