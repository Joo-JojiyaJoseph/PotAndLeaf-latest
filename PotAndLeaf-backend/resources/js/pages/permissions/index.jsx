import { Head } from '@inertiajs/react';
import { KeyIcon } from '@heroicons/react/24/outline';
import PageHeader from '@/components/nursery/PageHeader';

export default function PermissionsIndex({ groups = {} }) {
    return (
        <>
            <Head title="Permissions" />

            <div className="space-y-5 p-4 sm:p-6">
                <PageHeader
                    title="Permission catalog"
                    description="Every capability in the app. Permissions are defined in code and granted through roles."
                />

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {Object.entries(groups).map(([module, perms]) => (
                        <div
                            key={module}
                            className="rounded-lg border border-border bg-card p-5"
                        >
                            <div className="mb-3 flex items-center gap-2">
                                <span className="flex size-8 items-center justify-center rounded-md bg-accent text-primary">
                                    <KeyIcon className="size-4" />
                                </span>
                                <h2 className="text-sm font-semibold">{module}</h2>
                            </div>
                            <ul className="space-y-2">
                                {Object.entries(perms).map(([name, label]) => (
                                    <li
                                        key={name}
                                        className="flex items-center justify-between gap-2 text-sm"
                                    >
                                        <span>{label}</span>
                                        <code className="text-xs text-muted-foreground">
                                            {name}
                                        </code>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

PermissionsIndex.layout = (props) => ({
    breadcrumbs: [
        { title: 'Roles', href: `/${props.team}/roles` },
        { title: 'Permissions', href: `/${props.team}/permissions` },
    ],
});
