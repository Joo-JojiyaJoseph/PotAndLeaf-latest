import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CheckIcon, ChevronDownIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import { classNames } from '../lib/format';

/**
 * Themed dropdown (green focus, hover, and selected state). Portals the menu
 * so options stay visible inside overflow:auto cards and modals.
 */
export default function SearchSelect({
  value,
  onChange,
  options = [],
  placeholder = 'Select…',
  disabled = false,
  emptyLabel = 'No options',
  className = '',
  size = 'md',
  searchable,
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const triggerRef = useRef(null);
  const menuRef = useRef(null);
  const searchRef = useRef(null);
  const [pos, setPos] = useState({ top: 0, left: 0, width: 240, maxHeight: 240 });

  const showSearch = searchable ?? options.length > 7;
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
      left: Math.min(r.left, window.innerWidth - Math.max(r.width, 220) - 8),
      width: Math.max(r.width, 220),
      maxHeight,
    });
  }

  useEffect(() => {
    if (!open) return;
    place();
    setQuery('');
    const id = requestAnimationFrame(() => (showSearch ? searchRef.current?.focus() : triggerRef.current?.focus()));
    let ticking = false;
    const onScroll = () => {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(() => {
        place();
        ticking = false;
      });
    };
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
  }, [open, showSearch]);

  function pick(next) {
    onChange(next);
    setOpen(false);
  }

  const compact = size === 'sm';

  return (
    <div className="relative min-w-0">
      <button
        ref={triggerRef}
        type="button"
        disabled={disabled}
        onClick={() => !disabled && setOpen((v) => !v)}
        aria-haspopup="listbox"
        aria-expanded={open}
        className={classNames(
          'flex w-full items-center gap-2 border bg-surface text-left transition-colors',
          compact ? 'h-9 rounded-[10px] px-2 text-sm' : 'h-10 rounded-xl px-3 text-sm',
          'focus:outline-none focus:ring-2 focus:ring-leaf/25',
          open ? 'border-leaf ring-2 ring-leaf/20' : 'border-line hover:border-leaf/40',
          disabled && 'pointer-events-none bg-paper text-muted opacity-70',
          className,
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
        <ChevronDownIcon className={classNames('size-4 shrink-0 text-leaf transition-transform', open && 'rotate-180')} />
      </button>

      {open && createPortal(
        <div
          ref={menuRef}
          role="listbox"
          style={{ top: pos.top, left: pos.left, width: pos.width, maxHeight: pos.maxHeight }}
          className="dialog-in fixed z-[80] flex flex-col overflow-hidden rounded-xl border border-line bg-surface shadow-pop"
        >
          {showSearch && (
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
          )}
          <div className="min-h-0 flex-1 overflow-y-auto py-1">
            {filtered.length === 0 ? (
              <p className="px-3 py-6 text-center text-sm text-muted">{options.length === 0 ? emptyLabel : 'No matches'}</p>
            ) : filtered.map((opt) => {
              const active = String(opt.value) === String(value);
              return (
                <button
                  key={String(opt.value) + opt.label}
                  type="button"
                  role="option"
                  aria-selected={active}
                  disabled={opt.disabled}
                  onClick={() => !opt.disabled && pick(opt.value)}
                  className={classNames(
                    'mx-1 flex w-[calc(100%-8px)] items-start gap-2 rounded-lg px-3 py-2 text-left text-sm transition-colors',
                    opt.disabled && 'cursor-not-allowed opacity-40',
                    active
                      ? 'bg-leaf text-white'
                      : 'text-ink hover:bg-leaf-soft hover:text-leaf-hover',
                  )}
                >
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-medium">{opt.label}</span>
                    {opt.sublabel && (
                      <span className={classNames('mt-0.5 block truncate text-[11px]', active ? 'text-white/80' : 'text-muted')}>
                        {opt.sublabel}
                      </span>
                    )}
                  </span>
                  {active && <CheckIcon className="mt-0.5 size-4 shrink-0 text-white" />}
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
