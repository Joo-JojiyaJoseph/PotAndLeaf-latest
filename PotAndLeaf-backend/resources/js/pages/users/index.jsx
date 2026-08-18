import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { UserCircleIcon } from '@heroicons/react/24/outline';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import PageHeader from '@/components/nursery/PageHeader';
import DataTable from '@/components/nursery/DataTable';

export default function UsersIndex({ team, users = [], roles = [], can = {} }) {
    const [editing, setEditing] = useState(null);
    const [chosen, setChosen] = useState(new Set());
    const [saving, setSaving] = useState(false);

    const roleName = (id) => roles.find((r) => String(r.id) === String(id))?.name ?? id;

    function openAssign(user) {
        setEditing(user);
        setChosen(new Set((user.role_ids ?? []).map(String)));
    }

    function toggle(roleId) {
        setChosen((prev) => {
            const next = new Set(prev);
            const key = String(roleId);
            next.has(key) ? next.delete(key) : next.add(key);
            return next;
        });
    }

    function save() {
        setSaving(true);
        router.put(
            `/${team}/users/${editing.id}/roles`,
            { roles: Array.from(chosen) },
            {
                preserveScroll: true,
                onSuccess: () => setEditing(null),
                onFinish: () => setSaving(false),
            },
        );
    }

    const columns = [
        {
            id: 'name',
            header: 'Member',
            cell: ({ row }) => (
                <div className="flex items-center gap-3">
                    <span className="flex size-9 items-center justify-center rounded-full bg-muted text-muted-foreground">
                        <UserCircleIcon className="size-5" />
                    </span>
                    <div className="min-w-0">
                        <div className="truncate font-medium">{row.original.name}</div>
                        <div className="truncate text-xs text-muted-foreground">
                            {row.original.email}
                        </div>
                    </div>
                </div>
            ),
        },
        {
            id: 'team_role',
            header: 'Team role',
            enableSorting: false,
            cell: ({ row }) =>
                row.original.team_role ? (
                    <span className="inline-flex rounded-full bg-accent px-2 py-0.5 text-xs capitalize text-accent-foreground">
                        {row.original.team_role}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            id: 'roles',
            header: 'ERP roles',
            enableSorting: false,
            cell: ({ row }) => {
                const ids = row.original.role_ids ?? [];
                if (ids.length === 0) {
                    return <span className="text-sm text-muted-foreground">None</span>;
                }
                return (
                    <div className="flex flex-wrap gap-1">
                        {ids.map((id) => (
                            <span
                                key={id}
                                className="rounded-md bg-secondary px-2 py-0.5 text-xs text-secondary-foreground"
                            >
                                {roleName(id)}
                            </span>
                        ))}
                    </div>
                );
            },
        },
        {
            id: 'actions',
            header: '',
            enableSorting: false,
            cell: ({ row }) =>
                can.assign && (
                    <div className="flex justify-end">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => openAssign(row.original)}
                        >
                            Assign roles
                        </Button>
                    </div>
                ),
        },
    ];

    return (
        <>
            <Head title="Users" />

            <div className="space-y-5 p-4 sm:p-6">
                <PageHeader
                    title="Users"
                    description="Team members and the ERP roles that grant their access."
                />

                <DataTable
                    columns={columns}
                    data={users}
                    meta={null}
                    mobileCard={(user) => (
                        <div className="space-y-2">
                            <div className="flex items-center gap-3">
                                <span className="flex size-9 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                    <UserCircleIcon className="size-5" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="truncate font-medium">
                                        {user.name}
                                    </div>
                                    <div className="truncate text-xs text-muted-foreground">
                                        {user.email}
                                    </div>
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-1">
                                {(user.role_ids ?? []).length === 0 ? (
                                    <span className="text-xs text-muted-foreground">
                                        No roles
                                    </span>
                                ) : (
                                    user.role_ids.map((id) => (
                                        <span
                                            key={id}
                                            className="rounded-md bg-secondary px-2 py-0.5 text-xs text-secondary-foreground"
                                        >
                                            {roleName(id)}
                                        </span>
                                    ))
                                )}
                            </div>
                            {can.assign && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="w-full"
                                    onClick={() => openAssign(user)}
                                >
                                    Assign roles
                                </Button>
                            )}
                        </div>
                    )}
                />
            </div>

            <Dialog open={Boolean(editing)} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Assign roles{editing ? ` — ${editing.name}` : ''}
                        </DialogTitle>
                    </DialogHeader>

                    {roles.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No roles exist yet. Create one under Roles first.
                        </p>
                    ) : (
                        <div className="space-y-2">
                            {roles.map((role) => (
                                <label
                                    key={role.id}
                                    className="flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        checked={chosen.has(String(role.id))}
                                        onChange={() => toggle(role.id)}
                                        className="size-4 rounded border-input text-primary focus:ring-ring"
                                    />
                                    {role.name}
                                </label>
                            ))}
                        </div>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setEditing(null)}
                        >
                            Cancel
                        </Button>
                        <Button type="button" onClick={save} disabled={saving}>
                            {saving ? 'Saving…' : 'Save roles'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

UsersIndex.layout = (props) => ({
    breadcrumbs: [{ title: 'Users', href: `/${props.team}/users` }],
});
