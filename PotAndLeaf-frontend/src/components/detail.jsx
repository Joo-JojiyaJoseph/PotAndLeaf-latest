import { useNavigate } from 'react-router-dom';
import { ArrowLeftIcon } from '@heroicons/react/24/outline';
import { Button, Card, Spinner } from './ui';
import { classNames } from '../lib/format';

/** Page header with a back button, title/subtitle, and optional actions. */
export function DetailHeader({ title, subtitle, backTo, actions }) {
  const navigate = useNavigate();
  return (
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div className="flex items-center gap-3">
        <button
          onClick={() => (backTo ? navigate(backTo) : navigate(-1))}
          className="rounded-xl border border-line-strong bg-surface p-2 text-muted hover:bg-sidebar hover:text-ink"
          aria-label="Back"
        >
          <ArrowLeftIcon className="size-4" />
        </button>
        <div>
          <h1 className="text-lg font-semibold">{title}</h1>
          {subtitle && <p className="text-sm text-muted">{subtitle}</p>}
        </div>
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

/** A titled card section. */
export function Section({ title, actions, children, className }) {
  return (
    <Card className={classNames('overflow-hidden', className)}>
      {title && (
        <div className="flex items-center justify-between border-b border-line bg-sidebar px-5 py-3">
          <span className="microlabel font-semibold text-ink">{title}</span>
          {actions}
        </div>
      )}
      <div className="p-5">{children}</div>
    </Card>
  );
}

/** Responsive label/value grid. */
export function InfoGrid({ children, cols = 3 }) {
  const map = { 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-3', 4: 'sm:grid-cols-4' };
  return <dl className={classNames('grid grid-cols-1 gap-x-6 gap-y-4', map[cols])}>{children}</dl>;
}

export function InfoItem({ label, value, mono }) {
  return (
    <div>
      <dt className="microlabel text-faint">{label}</dt>
      <dd className={classNames('mt-1 text-sm', mono && 'tnum', (value === null || value === undefined || value === '') ? 'text-muted' : 'text-ink')}>
        {value === null || value === undefined || value === '' ? '—' : value}
      </dd>
    </div>
  );
}

export function DetailLoading() {
  return <div className="flex h-full items-center justify-center py-24"><Spinner className="size-6" /></div>;
}

export function DetailError({ backTo, message }) {
  const navigate = useNavigate();
  return (
    <div className="p-6">
      <Card className="p-10 text-center">
        <p className="text-sm text-muted">{message ?? "Couldn't load this record."}</p>
        <Button variant="outline" size="sm" className="mt-4" onClick={() => navigate(backTo ?? -1)}>Go back</Button>
      </Card>
    </div>
  );
}
