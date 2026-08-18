import { useEffect } from 'react';
import { XMarkIcon } from '@heroicons/react/24/outline';
import { classNames } from '../lib/format';

const variants = {
  primary: 'bg-leaf text-white shadow-soft hover:bg-leaf-hover',
  outline: 'bg-surface text-ink border border-line-strong hover:bg-sidebar',
  ghost: 'text-muted border border-line hover:bg-sidebar hover:text-ink',
  soft: 'bg-leaf-soft text-leaf-hover hover:brightness-[0.97]',
  danger: 'bg-danger text-white hover:brightness-95',
};

const sizes = {
  sm: 'h-8 px-3 text-[12px]',
  md: 'h-10 px-4 text-[13px]',
  icon: 'h-9 w-9',
};

export function Button({ variant = 'primary', size = 'md', className, children, ...props }) {
  return (
    <button
      className={classNames(
        'inline-flex items-center justify-center gap-1.5 rounded-xl font-medium transition-all',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-leaf/40 disabled:opacity-60',
        'active:scale-[0.98]',
        variants[variant],
        sizes[size],
        className,
      )}
      {...props}
    >
      {children}
    </button>
  );
}

export function Card({ className, children }) {
  return (
    <div className={classNames('rounded-[18px] bg-surface shadow-card', className)}>
      {children}
    </div>
  );
}

/** Horizontal-scroll wrapper so tables stay usable on small screens. */
export function TableWrap({ className, children }) {
  return (
    <div className={classNames('w-full overflow-x-auto', className)}>
      {children}
    </div>
  );
}

/** Optional titled card header, matching the theme's tinted header bar. */
export function CardHeader({ title, actions }) {
  return (
    <div className="flex items-center justify-between gap-2 border-b border-line bg-sidebar px-4 py-3">
      <span className="microlabel font-semibold text-ink">{title}</span>
      {actions}
    </div>
  );
}

const badgeTones = {
  active: 'bg-leaf-soft text-leaf-hover',
  approved: 'bg-leaf-soft text-leaf-hover',
  inactive: 'bg-paper text-muted',
  draft: 'bg-paper text-muted',
  blocked: 'bg-danger-soft text-danger',
  rejected: 'bg-danger-soft text-danger',
  warning: 'bg-amber-soft text-amber',
  pending: 'bg-amber-soft text-amber',
  submitted: 'bg-info-soft text-info',
  info: 'bg-info-soft text-info',
  default: 'bg-paper text-muted',
};

export function Badge({ tone = 'default', children, className }) {
  return (
    <span
      className={classNames(
        'inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-medium capitalize',
        badgeTones[tone] ?? badgeTones.default,
        className,
      )}
    >
      {children}
    </span>
  );
}

const statAccent = {
  default: 'bg-line-strong',
  warn: 'bg-amber',
  good: 'bg-leaf',
  info: 'bg-info',
};

/** Dashboard-style metric card with a colored top accent bar. */
export function StatCard({ label, value, sub, tone = 'default' }) {
  return (
    <div className="relative overflow-hidden rounded-[18px] bg-surface p-4 shadow-card">
      <span className={classNames('absolute inset-x-0 top-0 h-[3px]', statAccent[tone] ?? statAccent.default)} />
      <div className="microlabel text-faint">{label}</div>
      <div className="tnum mt-2 text-[26px] font-semibold leading-none text-ink">{value}</div>
      {sub && <div className="mt-1.5 text-[11px] text-muted">{sub}</div>}
    </div>
  );
}

export function Input({ className, ...props }) {
  return (
    <input
      className={classNames(
        'h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm',
        'placeholder:text-faint focus:border-leaf/40 focus:outline-none focus:ring-2 focus:ring-leaf/25',
        className,
      )}
      {...props}
    />
  );
}

export function Field({ label, required, error, children }) {
  return (
    <label className="block space-y-1.5">
      <span className="text-xs font-medium text-muted">
        {label}
        {required && <span className="text-danger"> *</span>}
      </span>
      {children}
      {error && <span className="block text-xs text-danger">{error}</span>}
    </label>
  );
}

export function Spinner({ className }) {
  return (
    <span
      className={classNames(
        'inline-block size-4 animate-spin rounded-full border-2 border-line border-t-leaf',
        className,
      )}
      aria-label="Loading"
    />
  );
}

export function Modal({ open, onClose, title, children, footer, dismissible = true }) {
  useEffect(() => {
    if (!open || !dismissible) return;
    const onKey = (e) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose, dismissible]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-ink/30 p-0 backdrop-blur-[2px] sm:items-center sm:p-4">
      <div className="absolute inset-0" onClick={dismissible ? onClose : undefined} aria-hidden />
      <div
        className="dialog-in relative z-10 flex max-h-[92dvh] w-full max-w-lg flex-col rounded-t-[20px] bg-surface shadow-pop sm:max-h-[85dvh] sm:rounded-[20px]"
        onClick={(e) => e.stopPropagation()}
        onMouseDown={(e) => e.stopPropagation()}
      >
        <div className="flex shrink-0 items-center justify-between border-b border-line px-5 py-4">
          <h2 className="text-sm font-semibold">{title}</h2>
          <button onClick={onClose} className="rounded-lg p-1 text-muted hover:bg-paper hover:text-ink" aria-label="Close">
            <XMarkIcon className="size-5" />
          </button>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">{children}</div>
        {footer && (
          <div className="flex shrink-0 flex-wrap justify-end gap-2 border-t border-line px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
            {footer}
          </div>
        )}
      </div>
    </div>
  );
}
