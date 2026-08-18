import { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { useForm } from 'react-hook-form';
import {
    MagnifyingGlassIcon,
    PencilSquareIcon,
    PlusIcon,
    TrashIcon,
} from '@heroicons/react/24/outline';
import Swal from 'sweetalert2';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PageHeader from '@/components/nursery/PageHeader';
import StatusBadge from '@/components/nursery/StatusBadge';
import DataTable from '@/components/nursery/DataTable';

/**
 * A complete CRUD screen for a simple lookup master, driven by a config object.
 * The three lookup pages (categories/brands/units) are thin wrappers around this.
 *
 * config: { title, singular, slug, columns:[{key,label}], fields:[{name,label,type,required,options,colSpan,placeholder}] }
 */
export default function LookupResource({ config, team, records, filters = {}, can = {} }) {
    const base = `/${team}/${config.slug}`;
    const rows = records.data ?? [];
    const meta = records.meta ?? null;

    const [search, setSearch] = useState(filters.search ?? '');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);

    const defaults = useMemo(() => {
        const base = {};
        config.fields.forEach((f) => {
            base[f.name] = f.name === 'status' ? 'active' : '';
        });
        return base;
    }, [config.fields]);

    const {
        register,
        handleSubmit,
        reset,
        setError,
        formState: { errors, isSubmitting },
    } = useForm({ defaultValues: defaults });

    function query(params) {
        router.get(base, { ...filters, ...params }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function openCreate() {
        setEditing(null);
        reset(defaults);
        setOpen(true);
    }

    function openEdit(record) {
        setEditing(record);
        reset({ ...defaults, ...record });
        setOpen(true);
    }

    function onSubmit(values) {
        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
            onError: (serverErrors) =>
                Object.entries(serverErrors).forEach(([field, message]) =>
                    setError(field, { type: 'server', message }),
                ),
        };
        if (editing) {
            router.put(`${base}/${editing.id}`, values, options);
        } else {
            router.post(base, values, options);
        }
    }

    function confirmDelete(record) {
        Swal.fire({
            title: `Delete ${record.name}?`,
            text: 'It will be moved to trash and can be restored later.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#DC2626',
            cancelButtonColor: '#6B7280',
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(`${base}/${record.id}`, { preserveScroll: true });
            }
        });
    }

    const columns = [
        ...config.columns.map((col) => ({
            id: col.key,
            header: col.label,
            enableSorting: col.sortable !== false,
            cell: ({ row }) => (
                <span className={col.key === 'code' ? 'font-medium' : ''}>
                    {row.original[col.key] || '—'}
                </span>
            ),
        })),
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
                    {can.update && (
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Edit"
                            onClick={() => openEdit(row.original)}
                        >
                            <PencilSquareIcon className="size-4" />
                        </Button>
                    )}
                    {can.delete && (
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
            <Head title={config.title} />

            <div className="space-y-5 p-4 sm:p-6">
                <PageHeader
                    title={config.title}
                    description={config.description}
                    actions={
                        can.create && (
                            <Button size="sm" onClick={openCreate}>
                                <PlusIcon className="size-4" /> New {config.singular}
                            </Button>
                        )
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
                        placeholder={`Search ${config.title.toLowerCase()}…`}
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
                    mobileCard={(record) => (
                        <div className="flex items-center justify-between">
                            <div>
                                <div className="font-medium">{record.name}</div>
                                <div className="text-xs text-muted-foreground">
                                    {record.code}
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <StatusBadge status={record.status} />
                                {can.update && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => openEdit(record)}
                                    >
                                        <PencilSquareIcon className="size-4" />
                                    </Button>
                                )}
                            </div>
                        </div>
                    )}
                />
            </div>

            {/* Create / edit modal */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? `Edit ${config.singular}` : `New ${config.singular}`}
                        </DialogTitle>
                    </DialogHeader>

                    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            {config.fields.map((field) => (
                                <div
                                    key={field.name}
                                    className={`space-y-1.5 ${field.colSpan === 2 ? 'sm:col-span-2' : ''}`}
                                >
                                    <Label>
                                        {field.label}
                                        {field.required && (
                                            <span className="text-danger"> *</span>
                                        )}
                                    </Label>

                                    {field.type === 'textarea' ? (
                                        <textarea
                                            rows={3}
                                            {...register(field.name, {
                                                required: field.required && `${field.label} is required`,
                                            })}
                                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-ring"
                                        />
                                    ) : field.type === 'select' ? (
                                        <select
                                            {...register(field.name, {
                                                required: field.required && `${field.label} is required`,
                                            })}
                                            className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:ring-2 focus:ring-ring"
                                        >
                                            <option value="">
                                                {field.placeholder ?? 'Select…'}
                                            </option>
                                            {(field.options ?? []).map((opt) => (
                                                <option key={opt.value} value={opt.value}>
                                                    {opt.label}
                                                </option>
                                            ))}
                                        </select>
                                    ) : (
                                        <Input
                                            {...register(field.name, {
                                                required: field.required && `${field.label} is required`,
                                            })}
                                            placeholder={field.placeholder}
                                        />
                                    )}

                                    {errors[field.name] && (
                                        <p className="text-xs text-danger">
                                            {errors[field.name].message}
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={isSubmitting}>
                                {editing ? 'Save changes' : `Create ${config.singular}`}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
