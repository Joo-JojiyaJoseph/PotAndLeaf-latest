import {
    CheckCircleIcon,
    NoSymbolIcon,
    PauseCircleIcon,
} from '@heroicons/react/20/solid';

// Maps a supplier status to a coloured pill. Colours come from the semantic
// design-system tokens (success / muted / danger), never hardcoded hex.
const MAP = {
    active: {
        label: 'Active',
        Icon: CheckCircleIcon,
        className: 'bg-success/10 text-success ring-success/20',
    },
    inactive: {
        label: 'Inactive',
        Icon: PauseCircleIcon,
        className: 'bg-muted text-muted-foreground ring-border',
    },
    blocked: {
        label: 'Blocked',
        Icon: NoSymbolIcon,
        className: 'bg-danger/10 text-danger ring-danger/20',
    },
};

export default function StatusBadge({ status }) {
    const config = MAP[status] ?? MAP.inactive;
    const { label, Icon, className } = config;

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${className}`}
        >
            <Icon className="size-3.5" aria-hidden="true" />
            {label}
        </span>
    );
}
