import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ChevronDownIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import { classNames } from '../lib/format';

/**
 * Searchable dropdown that portals its menu to document.body so options stay
 * visible inside overflow:auto modals (native <select> lists get clipped).
 */
export default function SearchSelect({
  value,
  onChange,
  options = [],
  placeholder = 'Select…',
  disabled = false,
  emptyLabel = 'No options',
  className = '',
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const triggerRef = useRef(null);
  const menuRef = useRef(null);
  const searchRef = useRef(null);
  const [pos, setPos] = useState({ top: 0, left: 0, width: 240, maxHeight: 240 });

  const selected = options.find((o) => String(o.value) === String(value));
  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return options;
    return options.filter((o) => `${o.label} ${o.sublabel ?? ''}`.toLowerCase().includes(q));
  }, [options, query]);

  function place() {
    const el = triggerRef.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const spaceBelow = window.innerHeight - r.bottom;
    const menuH = 280;
    const openUp = spaceBelow < 180 && r.top > spaceBelow;
    const maxHeight = Math.max(140, openUp ? Math.min(menuH, r.top - 12) : Math.min(menuH, spaceBelow - 12));
    setPos({
      top: openUp ? Math.max(8, r.top - maxHeight - 6) : r.bottom + 6,
      left: Math.min(r.left, window.innerWidth - Math.max(r.width, 240) - 8),
      width: Math.max(r.width, 240),
      maxHeight,
    });
  }

  useEffect(() => {
    if (!open) return;
    place();
    setQuery('');
    const id = requestAnimationFrame(() => searchRef.current?.focus());
    const onScroll = () => place();
    const onDown = (e) => {
      if (triggerRef.current?.contains(e.target) || menuRef.current?.contains(e.target)) return;
      setOpen(false);
    };
    const onKey = (e) => {
      if (e.key === 'Escape') setOpen(false);
    };
    window.addEventListener('scroll', onScroll, true);
    window.addEventListener('resize', onScroll);
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      cancelAnimationFrame(id);
      window.removeEventListener('scroll', onScroll, true);
      window.removeEventListener('resize', onScroll);
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  function pick(next) {
    onChange(next);
    setOpen(false);
  }

  return (
    <div className={classNames('relative min-w-0', className)}>
      <button
        ref={triggerRef}
        type="button"
        disabled={disabled}
        onClick={() => !disabled && setOpen((v) => !v)}
        aria-haspopup="listbox"
        aria-expanded={open}
        className={classNames(
          'flex h-10 w-full items-center gap-2 rounded-xl border bg-surface px-3 text-left text-sm transition-colors',
          'focus:outline-none focus:ring-2 focus:ring-leaf/25',
          open ? 'border-leaf/40 ring-2 ring-leaf/15' : 'border-line hover:border-leaf/30',
          disabled && 'pointer-events-none bg-paper text-muted opacity-70',
        )}
      >
        <span className={classNames('min-w-0 flex-1 truncate', selected ? 'text-ink' : 'text-muted')}>
          {selected ? (
            <>
              {selected.label}
              {selected.sublabel ? <span className="text-muted"> · {selected.sublabel}</span> : null}
            </>
          ) : placeholder}
        </span>
        <ChevronDownIcon className={classNames('size-4 shrink-0 text-muted transition-transform', open && 'rotate-180')} />
      </button>

      {open && createPortal(
        <div
          ref={menuRef}
          role="listbox"
          style={{ top: pos.top, left: pos.left, width: pos.width, maxHeight: pos.maxHeight }}
          className="dialog-in fixed z-[80] flex flex-col overflow-hidden rounded-xl border border-line bg-surface shadow-pop"
        >
          <div className="shrink-0 border-b border-line p-2">
            <div className="relative">
              <MagnifyingGlassIcon className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted" />
              <input
                ref={searchRef}
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Search…"
                className="h-9 w-full rounded-lg border border-line bg-paper pl-8 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25"
              />
            </div>
          </div>
          <div className="min-h-0 flex-1 overflow-y-auto py-1">
            {filtered.length === 0 ? (
              <p className="px-3 py-6 text-center text-sm text-muted">{options.length === 0 ? emptyLabel : 'No matches'}</p>
            ) : filtered.map((opt) => {
              const active = String(opt.value) === String(value);
              return (
                <button
                  key={opt.value}
                  type="button"
                  role="option"
                  aria-selected={active}
                  onClick={() => pick(opt.value)}
                  className={classNames(
                    'flex w-full items-start px-3 py-2 text-left text-sm transition-colors',
                    active ? 'bg-leaf-soft text-leaf-hover' : 'text-ink hover:bg-sidebar',
                  )}
                >
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-medium">{opt.label}</span>
                    {opt.sublabel && <span className="mt-0.5 block truncate text-[11px] text-muted">{opt.sublabel}</span>}
                  </span>
                </button>
              );
            })}
          </div>
        </div>,
        document.body,
      )}
    </div>
  );
}
