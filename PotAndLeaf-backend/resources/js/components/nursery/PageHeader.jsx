// PageHeader — title, optional description, and a right-aligned action slot.
// Heroicons only (per the design system). Used at the top of every module page.

export default function PageHeader({ title, description, actions = null }) {
    return (
        <div className="flex flex-col gap-3 border-b border-border pb-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="space-y-0.5">
                <h1 className="text-xl font-semibold tracking-tight text-foreground">
                    {title}
                </h1>
                {description && (
                    <p className="text-sm text-muted-foreground">{description}</p>
                )}
            </div>
            {actions && (
                <div className="flex flex-wrap items-center gap-2">{actions}</div>
            )}
        </div>
    );
}
