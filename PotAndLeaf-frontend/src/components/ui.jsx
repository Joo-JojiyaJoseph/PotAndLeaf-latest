import { Children, isValidElement, useEffect } from 'react';
import { XMarkIcon } from '@heroicons/react/24/outline';
import { classNames } from '../lib/format';
import SearchSelect from './SearchSelect';
export { Spinner } from './Spinner';

const variants = {
  primary: 'btn-primary-grad text-white hover:brightness-105',
  outline: 'glass-control text-ink hover:border-leaf/30 hover:bg-white/90',
  secondary: 'glass-control text-ink hover:border-leaf/30 hover:bg-white/90',
  ghost: 'text-muted border border-transparent hover:bg-white/50 hover:text-ink',
  soft: 'bg-leaf-soft text-leaf-hover hover:brightness-[0.97]',
  danger: 'bg-danger text-white shadow-soft hover:brightness-95',
};

const sizes = {
  sm: 'min-h-10 px-3 text-[12px] sm:h-9 sm:min-h-9',
  md: 'min-h-11 h-10 px-4 text-[13px]',
  icon: 'h-11 w-11 sm:h-9 sm:w-9',
};

export function Button({ variant = 'primary', size = 'md', className, children, ...props }) {
  return (
    <button
      className={classNames(
        'inline-flex items-center justify-center gap-1.5 rounded-[12px] font-medium transition-all duration-150',
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
    <div className={classNames('glass-card', className)}>
      {children}
    </div>
  );
}

export function PageHeader({ icon: Icon, title, subtitle, actions, className }) {
  return (
    <div className={classNames('flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between', className)}>
      <div className="flex min-w-0 items-start gap-3">
        {Icon ? (
          <span className="mt-0.5 flex size-11 shrink-0 items-center justify-center rounded-2xl bg-white/90 text-leaf shadow-soft ring-1 ring-line">
            <Icon className="size-5" strokeWidth={1.6} />
          </span>
        ) : null}
        <div className="min-w-0">
          <h1 className="page-title">{title}</h1>
          {subtitle ? <p className="mt-1 text-[13px] text-muted sm:text-sm">{subtitle}</p> : null}
        </div>
      </div>
      {actions ? <div className="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">{actions}</div> : null}
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
    <div className="flex items-center justify-between gap-2 border-b border-line/70 bg-white/30 px-4 py-3">
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
export function StatCard({ label, value, sub, tone = 'default', icon: Icon }) {
  return (
    <div className="glass-card relative min-w-0 overflow-hidden p-3 transition-transform duration-150 sm:p-5 sm:hover:-translate-y-0.5">
      <span className={classNames('absolute inset-y-3 left-0 w-[3px] rounded-full sm:inset-y-4', statAccent[tone] ?? statAccent.default)} />
      <div className="flex items-start justify-between gap-2 pl-2">
        <div className="microlabel min-w-0 truncate text-muted">{label}</div>
        {Icon ? <Icon className="size-4 shrink-0 text-leaf/80 sm:size-5" strokeWidth={1.6} /> : null}
      </div>
      <div className="tnum mt-1.5 break-words pl-2 text-lg font-semibold leading-tight text-ink sm:mt-2 sm:text-[22px] lg:text-[26px] lg:leading-none">{value}</div>
      {sub && <div className="mt-1 pl-2 text-[11px] leading-snug text-muted sm:mt-1.5">{sub}</div>}
    </div>
  );
}

export function Input({ className, icon: Icon, prefix, suffix, ...props }) {
  const padded = Icon || prefix || suffix;
  const field = (
    <input
      className={classNames(
        'h-10 w-full rounded-[12px] glass-control text-sm text-ink',
        padded ? '' : 'px-3',
        Icon || prefix ? 'pl-9 pr-3' : '',
        suffix ? 'pr-8' : '',
        !Icon && !prefix && suffix ? 'pl-3' : '',
        'placeholder:text-faint focus:border-leaf/40 focus:outline-none focus:ring-2 focus:ring-leaf/25',
        className,
      )}
      {...props}
    />
  );
  if (!padded) return field;
  return (
    <div className="relative">
      {Icon ? <Icon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" strokeWidth={1.6} /> : null}
      {prefix ? <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted">{prefix}</span> : null}
      {field}
      {suffix ? <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted">{suffix}</span> : null}
    </div>
  );
}

export function Textarea({ className, rows = 4, ...props }) {
  return (
    <textarea
      rows={rows}
      className={classNames(
        'w-full rounded-[12px] glass-control px-3 py-2 text-sm text-ink',
        'placeholder:text-faint focus:border-leaf/40 focus:outline-none focus:ring-2 focus:ring-leaf/25',
        className,
      )}
      {...props}
    />
  );
}

function textOf(node) {
  if (node == null || node === false) return '';
  if (typeof node === 'string' || typeof node === 'number') return String(node);
  if (Array.isArray(node)) return node.map(textOf).join('');
  if (isValidElement(node)) return textOf(node.props.children);
  return '';
}

function optionsFromSelectChildren(children) {
  return Children.toArray(children).flatMap((child) => {
    if (!isValidElement(child)) return [];
    if (child.type === 'optgroup') {
      return optionsFromSelectChildren(child.props.children);
    }
    const value = child.props.value ?? '';
    const label = textOf(child.props.children).replace(/\s+/g, ' ').trim();
    return [{
      value: String(value),
      label: label || (value === '' ? 'Select…' : String(value)),
      disabled: Boolean(child.props.disabled),
    }];
  });
}

/** Drop-in replacement for native select — green focus, hover, and selected option. */
export function Select({ value, onChange, children, className = '', disabled, name, id, placeholder, icon }) {
  const options = optionsFromSelectChildren(children);
  const size = /\bh-[89]\b/.test(className) ? 'sm' : 'md';
  const empty = options.find((o) => o.value === '');
  return (
    <SearchSelect
      id={id}
      value={value ?? ''}
      onChange={(v) => onChange?.({ target: { value: v, name }, currentTarget: { value: v, name } })}
      options={options}
      disabled={disabled}
      className={className}
      size={size}
      icon={icon}
      placeholder={placeholder || empty?.label || 'Select…'}
    />
  );
}

export function Field({ label, required, error, children, className }) {
  return (
    <label className={classNames('block space-y-1.5', className)}>
      <span className="text-xs font-medium text-muted">
        {label}
        {required && <span className="text-danger"> *</span>}
      </span>
      {children}
      {error && <span className="block text-xs text-danger">{error}</span>}
    </label>
  );
}

const modalWidths = {
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-3xl',
};

export function Modal({ open, onClose, title, children, footer, dismissible = true, size = 'md' }) {
  useEffect(() => {
    if (!open || !dismissible) return;
    const onKey = (e) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose, dismissible]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-ink/25 p-0 backdrop-blur-[6px] sm:items-center sm:p-4">
      <div className="absolute inset-0" onClick={dismissible ? onClose : undefined} aria-hidden />
      <div
        className={classNames(
          'dialog-in glass-card relative z-10 flex max-h-[92dvh] w-full flex-col rounded-t-[22px] sm:max-h-[88dvh] sm:rounded-[22px]',
          modalWidths[size] ?? modalWidths.md,
        )}
        onClick={(e) => e.stopPropagation()}
        onMouseDown={(e) => e.stopPropagation()}
      >
        <div className="flex shrink-0 items-center justify-between border-b border-line/70 px-5 py-4">
          <h2 className="text-base font-semibold tracking-tight">{title}</h2>
          <button onClick={onClose} className="rounded-xl p-1.5 text-muted hover:bg-white/60 hover:text-ink" aria-label="Close">
            <XMarkIcon className="size-5" />
          </button>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto overflow-x-hidden px-5 py-4">{children}</div>
        {footer && (
          <div className="flex shrink-0 flex-wrap justify-end gap-2 border-t border-line px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
            {footer}
          </div>
        )}
      </div>
    </div>
  );
}
