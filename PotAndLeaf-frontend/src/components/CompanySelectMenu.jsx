import { useEffect, useRef, useState } from 'react';
import { BuildingOffice2Icon, CheckIcon, ChevronDownIcon, FunnelIcon } from '@heroicons/react/24/outline';
import { classNames } from '../lib/format';

/**
 * Custom company picker — avoids native <select> menus that look inconsistent across browsers.
 */
export function CompanySelectMenu({
  value,
  options,
  onChange,
  onBeforeChange,
  label = 'Company',
  icon: Icon = FunnelIcon,
  className = '',
  menuClassName = '',
  align = 'left',
  disabled = false,
  placeholder = 'Select…',
}) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);

  const selected = options.find((o) => String(o.value) === String(value));

  useEffect(() => {
    if (!open) return;
    const onDown = (e) => {
      if (rootRef.current && !rootRef.current.contains(e.target)) setOpen(false);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  async function pick(next) {
    if (String(next) === String(value)) {
      setOpen(false);
      return;
    }
    if (onBeforeChange) {
      const allowed = await onBeforeChange(next);
      if (!allowed) return;
    }
    onChange(next);
    setOpen(false);
  }

  return (
    <div className={classNames('relative', className)} ref={rootRef}>
      <button
        type="button"
        disabled={disabled}
        onClick={() => setOpen((v) => !v)}
        aria-haspopup="listbox"
        aria-expanded={open}
        className={classNames(
          'inline-flex h-10 w-full min-w-[220px] max-w-xs items-center gap-2 rounded-xl border bg-surface px-3 text-left shadow-soft transition-all',
          'focus:outline-none focus-visible:ring-2 focus-visible:ring-leaf/30',
          open ? 'border-leaf/40 ring-2 ring-leaf/15' : 'border-line hover:border-leaf/30 hover:bg-paper',
          disabled && 'pointer-events-none opacity-60',
        )}
      >
        <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-leaf-soft text-leaf">
          <Icon className="size-3.5" />
        </span>
        <span className="hidden shrink-0 text-xs font-medium text-muted sm:inline">{label}</span>
        <span className="min-w-0 flex-1 truncate text-sm font-medium text-ink">
          {selected?.label ?? placeholder}
        </span>
        <ChevronDownIcon
          className={classNames('size-4 shrink-0 text-muted transition-transform', open && 'rotate-180')}
        />
      </button>

      {open && (
        <div
          role="listbox"
          aria-label={label}
          className={classNames(
            'dialog-in absolute z-50 mt-1.5 max-h-72 min-w-full overflow-y-auto rounded-xl border border-line bg-surface py-1.5 shadow-pop',
            align === 'right' ? 'right-0' : 'left-0',
            menuClassName,
          )}
        >
          {options.map((opt) => {
            const active = String(opt.value) === String(value);
            return (
              <button
                key={opt.value}
                type="button"
                role="option"
                aria-selected={active}
                onClick={() => pick(opt.value)}
                className={classNames(
                  'mx-1.5 flex w-[calc(100%-12px)] items-start gap-2.5 rounded-lg px-3 py-2.5 text-left transition-colors',
                  active ? 'bg-leaf-soft text-leaf-hover' : 'text-ink hover:bg-sidebar',
                )}
              >
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium">{opt.label}</span>
                  {opt.sublabel && (
                    <span className="mt-0.5 block truncate font-mono text-[11px] text-muted">{opt.sublabel}</span>
                  )}
                </span>
                {active && <CheckIcon className="mt-0.5 size-4 shrink-0 text-leaf" />}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

/** Sidebar workspace switcher trigger (full width, no filter label). */
export function CompanySelectMenuBlock({
  value,
  options,
  onChange,
  onBeforeChange,
  heading = 'Workspace',
  hint,
  className = '',
}) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef(null);

  const selected = options.find((o) => String(o.value) === String(value));

  useEffect(() => {
    if (!open) return;
    const onDown = (e) => {
      if (rootRef.current && !rootRef.current.contains(e.target)) setOpen(false);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  async function pick(next) {
    if (String(next) === String(value)) {
      setOpen(false);
      return;
    }
    if (onBeforeChange) {
      const allowed = await onBeforeChange(next);
      if (!allowed) return;
    }
    onChange(next);
    setOpen(false);
  }

  if (options.length <= 1) {
    return (
      <div className={classNames('mx-3 mb-4 rounded-2xl border border-line/80 bg-surface p-3 shadow-soft', className)}>
        <div className="flex items-center gap-2">
          <span className="flex size-7 items-center justify-center rounded-lg bg-leaf-soft text-leaf">
            <BuildingOffice2Icon className="size-4" />
          </span>
          <div className="min-w-0">
            <div className="font-mono text-[10px] uppercase tracking-wider text-muted">{heading}</div>
            <div className="truncate text-sm font-medium text-ink">{selected?.label ?? '—'}</div>
          </div>
        </div>
        {hint && <p className="mt-2 text-xs text-muted">{hint}</p>}
      </div>
    );
  }

  return (
    <div className={classNames('mx-3 mb-4 rounded-2xl border border-line/80 bg-surface p-3 shadow-soft', className)}>
      <div className="mb-2 flex items-center gap-2">
        <span className="flex size-7 items-center justify-center rounded-lg bg-leaf-soft text-leaf">
          <BuildingOffice2Icon className="size-4" />
        </span>
        <div className="font-mono text-[10px] uppercase tracking-wider text-muted">{heading}</div>
      </div>

      <div className="relative" ref={rootRef}>
        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          aria-haspopup="listbox"
          aria-expanded={open}
          className={classNames(
            'flex h-10 w-full items-center gap-2 rounded-xl border bg-paper px-3 text-left transition-all',
            open ? 'border-leaf/40 ring-2 ring-leaf/15' : 'border-line hover:border-leaf/30',
          )}
        >
          <span className="min-w-0 flex-1 truncate text-sm font-medium text-ink">{selected?.label ?? 'Select company'}</span>
          <ChevronDownIcon className={classNames('size-4 shrink-0 text-muted transition-transform', open && 'rotate-180')} />
        </button>

        {open && (
          <div
            role="listbox"
            className="dialog-in absolute inset-x-0 top-full z-50 mt-1 max-h-64 overflow-y-auto rounded-xl border border-line bg-surface py-1.5 shadow-pop"
          >
            {options.map((opt) => {
              const active = String(opt.value) === String(value);
              return (
                <button
                  key={opt.value}
                  type="button"
                  role="option"
                  aria-selected={active}
                  onClick={() => pick(opt.value)}
                  className={classNames(
                    'mx-1.5 flex w-[calc(100%-12px)] items-start gap-2 rounded-lg px-3 py-2.5 text-left transition-colors',
                    active ? 'bg-leaf-soft text-leaf-hover' : 'hover:bg-sidebar',
                  )}
                >
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">{opt.label}</span>
                    {opt.sublabel && (
                      <span className="mt-0.5 block truncate font-mono text-[11px] text-muted">{opt.sublabel}</span>
                    )}
                  </span>
                  {active && <CheckIcon className="mt-0.5 size-4 shrink-0 text-leaf" />}
                </button>
              );
            })}
          </div>
        )}
      </div>

      {hint && !open && <p className="mt-2 text-xs text-muted">{hint}</p>}
    </div>
  );
}
