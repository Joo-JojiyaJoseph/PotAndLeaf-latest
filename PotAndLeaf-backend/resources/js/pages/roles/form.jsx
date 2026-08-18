import { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeftIcon, CheckIcon } from '@heroicons/react/24/outline';
import Swal from 'sweetalert2';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PageHeader from '@/components/nursery/PageHeader';

function slugify(value) {
    return value
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

export default function RoleForm({ team, role, permissionGroups = {} }) {
    const record = role?.data ?? role ?? null;
    const isEdit = Boolean(record);
    const isSystem = Boolean(record?.is_system);
    const base = `/${team}/roles`;

    const [name, setName] = useState(record?.name ?? '');
    const [slug, setSlug] = useState(record?.slug ?? '');
    const [slugTouched, setSlugTouched] = useState(isEdit);
    const [description, setDescription] = useState(record?.description ?? '');
    const [selected, setSelected] = useState(() => new Set(record?.permissions ?? []));
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);

    const allNames = useMemo(
        () =>
            Object.values(permissionGroups).flatMap((perms) => Object.keys(perms)),
        [permissionGroups],
    );

    function onNameChange(value) {
        setName(value);
        if (!slugTouched) setSlug(slugify(value));
    }

    function toggle(permission) {
        setSelected((prev) => {
            const next = new Set(prev);
            next.has(permission) ? next.delete(permission) : next.add(permission);
            return next;
        });
    }

    function toggleModule(names, checked) {
        setSelected((prev) => {
            const next = new Set(prev);
            names.forEach((n) => (checked ? next.add(n) : next.delete(n)));
            return next;
        });
    }

    const allChecked = allNames.length > 0 && allNames.every((n) => selected.has(n));

    function submit(e) {
        e.preventDefault();
        setProcessing(true);
        setErrors({});
        const payload = {
            name,
            slug,
            description,
            permissions: Array.from(selected),
        };
        const options = {
            preserveScroll: true,
            onError: (serverErrors) => setErrors(serverErrors),
            onFinish: () => setProcessing(false),
        };
        if (isEdit) {
            router.put(`${base}/${record.id}`, payload, options);
        } else {
            router.post(base, payload, options);
        }
    }

    function cancel() {
        router.get(base);
    }

    return (
        <>
            <Head title={isEdit ? `Edit ${record.name}` : 'New role'} />

            <form onSubmit={submit} className="space-y-5 p-4 sm:p-6">
                <PageHeader
                    title={isEdit ? 'Edit role' : 'New role'}
                    description="Name the role and choose the permissions it grants."
                    actions={
                        <Link href={base}>
                            <Button type="button" variant="outline" size="sm">
                                <ArrowLeftIcon className="size-4" /> Back
                            </Button>
                        </Link>
                    }
                />

                <section className="rounded-lg border border-border bg-card p-5">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>
                                Name<span className="text-danger"> *</span>
                            </Label>
                            <Input
                                value={name}
                                disabled={isSystem}
                                onChange={(e) => onNameChange(e.target.value)}
                                placeholder="Purchase Manager"
                            />
                            {errors.name && (
                                <p className="text-xs text-danger">{errors.name}</p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label>
                                Slug<span className="text-danger"> *</span>
                            </Label>
                            <Input
                                value={slug}
                                disabled={isSystem}
                                onChange={(e) => {
                                    setSlug(e.target.value);
                                    setSlugTouched(true);
                                }}
                                placeholder="purchase-manager"
                            />
                            {errors.slug && (
                                <p className="text-xs text-danger">{errors.slug}</p>
                            )}
                        </div>
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label>Description</Label>
                            <Input
                                value={description}
                                onChange={(e) => setDescription(e.target.value)}
                                placeholder="What this role is for"
                            />
                        </div>
                    </div>
                    {isSystem && (
                        <p className="mt-3 text-xs text-muted-foreground">
                            This is a system role — its name is locked, but you can adjust its permissions.
                        </p>
                    )}
                </section>

                <section className="rounded-lg border border-border bg-card p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <div>
                            <h2 className="text-sm font-semibold">Permissions</h2>
                            <p className="text-xs text-muted-foreground">
                                Granting “Full access” overrides every other module.
                            </p>
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={allChecked}
                                onChange={(e) => toggleModule(allNames, e.target.checked)}
                                className="size-4 rounded border-input text-primary focus:ring-ring"
                            />
                            Select all
                        </label>
                    </div>

                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        {Object.entries(permissionGroups).map(([module, perms]) => {
                            const names = Object.keys(perms);
                            const moduleAll = names.every((n) => selected.has(n));
                            return (
                                <div
                                    key={module}
                                    className="rounded-md border border-border p-4"
                                >
                                    <div className="mb-2 flex items-center justify-between">
                                        <h3 className="text-sm font-medium">{module}</h3>
                                        <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <input
                                                type="checkbox"
                                                checked={moduleAll}
                                                onChange={(e) =>
                                                    toggleModule(names, e.target.checked)
                                                }
                                                className="size-3.5 rounded border-input text-primary focus:ring-ring"
                                            />
                                            All
                                        </label>
                                    </div>
                                    <div className="space-y-1.5">
                                        {Object.entries(perms).map(([permName, label]) => (
                                            <label
                                                key={permName}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={selected.has(permName)}
                                                    onChange={() => toggle(permName)}
                                                    className="size-4 rounded border-input text-primary focus:ring-ring"
                                                />
                                                <span>{label}</span>
                                                <code className="ml-auto text-xs text-muted-foreground">
                                                    {permName}
                                                </code>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </section>

                <div className="sticky bottom-0 flex items-center justify-end gap-2 border-t border-border bg-background/80 py-3 backdrop-blur">
                    <Button type="button" variant="outline" onClick={cancel}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        <CheckIcon className="size-4" />
                        {processing ? 'Saving…' : isEdit ? 'Save changes' : 'Create role'}
                    </Button>
                </div>
            </form>
        </>
    );
}

RoleForm.layout = (props) => ({
    breadcrumbs: [
        { title: 'Roles', href: `/${props.team}/roles` },
        { title: props.role ? 'Edit' : 'New', href: '#' },
    ],
});
