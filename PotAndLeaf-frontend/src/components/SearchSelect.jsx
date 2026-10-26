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
  icon: Icon,
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  // Keyboard highlight: ↑/↓ move, Enter picks — so a cashier never needs the mouse.
  const [hi, setHi] = useState(0);
  const triggerRef = useRef(null);
  const menuRef = useRef(null);
  const searchRef = useRef(null);
  const [pos, setPos] = useState({ top: 0, bottom: undefined, left: 0, width: 240, maxHeight: 240 });

  const showSearch = searchable ?? options.length > 8;
  const selected = options.find((o) => String(o.value) === String(value));
  // An empty option whose text is a prompt ("Select company…") is not a real
  // choice: show it as the box's placeholder, not as a pickable row.
  const prompt = options.find((o) => String(o.value) === '' && /^select\b/i.test(String(o.label)));
  // Nothing picked yet → hide it. Something picked → keep it, so an optional box can be cleared.
  const hidePrompt = Boolean(prompt) && String(value ?? '') === '';
  const choices = useMemo(() => (hidePrompt ? options.filter((o) => o !== prompt) : options), [options, prompt, hidePrompt]);
  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return choices;
    return choices.filter((o) => `${o.label} ${o.sublabel ?? ''}`.toLowerCase().includes(q));
  }, [choices, query]);

  /** Is the box still visible, inside every scrolling area around it (e.g. a modal body)? */
  function triggerVisible(el) {
    const r = el.getBoundingClientRect();
    if (r.bottom <= 0 || r.top >= window.innerHeight) return false;
    for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
      const o = getComputedStyle(p).overflowY;
      if (o === 'auto' || o === 'scroll' || o === 'hidden') {
        const pr = p.getBoundingClientRect();
        if (r.bottom <= pr.top + 4 || r.top >= pr.bottom - 4) return false;
      }
    }
    return true;
  }

  function place() {
    const el = triggerRef.current;
    if (!el) return;
    // The box scrolled out of sight (e.g. up under a modal's title): close the
    // menu instead of letting it float over the page edge.
    if (!triggerVisible(el)) { setOpen(false); return; }
    const r = el.getBoundingClientRect();
    const spaceBelow = window.innerHeight - r.bottom;
    const menuH = 280;
    const openUp = spaceBelow < 180 && r.top > spaceBelow;
    const maxHeight = Math.max(140, openUp ? Math.min(menuH, r.top - 12) : Math.min(menuH, spaceBelow - 12));
    const width = Math.min(Math.max(r.width, 200), window.innerWidth - 16);
    // Opening upward: pin the menu's BOTTOM edge just above the box. Using
    // top = box top − maxHeight left a short list (e.g. Active / Inactive)
    // floating far above the box, because the menu is shorter than maxHeight.
    setPos({
      top: openUp ? undefined : r.bottom + 6,
      bottom: openUp ? window.innerHeight - r.top + 6 : undefined,
      left: Math.max(8, Math.min(r.left, window.innerWidth - width - 8)),
      width,
      maxHeight,
    });
  }

  useEffect(() => {
    if (!open) return;
    // Bring a half-hidden box fully into view first, so the menu opens next to it.
    triggerRef.current?.scrollIntoView({ block: 'nearest' });
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
    triggerRef.current?.focus();
  }

  // Start the keyboard highlight on the current choice (or the first row).
  useEffect(() => {
    const usable = filtered.filter((o) => !o.disabled);
    const at = query ? -1 : usable.findIndex((o) => String(o.value) === String(value));
    setHi(at >= 0 ? at : 0);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, open]);

  function onListKey(e) {
    const usable = filtered.filter((o) => !o.disabled);
    if (!usable.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); setHi((i) => Math.min(i + 1, usable.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setHi((i) => Math.max(i - 1, 0)); }
    else if (e.key === 'Enter') { e.preventDefault(); pick(usable[Math.min(hi, usable.length - 1)].value); }
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
          'flex w-full items-center gap-2 glass-control text-left transition-colors',
          compact ? 'h-9 rounded-[10px] px-2 text-sm' : 'h-10 rounded-[12px] px-3 text-sm',
          'focus:outline-none focus:ring-2 focus:ring-leaf/25',
          open ? 'border-leaf ring-2 ring-leaf/20' : 'hover:border-leaf/40',
          disabled && 'pointer-events-none opacity-70',
          className,
        )}
      >
        {Icon ? <Icon className="size-4 shrink-0 text-muted" strokeWidth={1.6} /> : null}
        <span className={classNames('min-w-0 flex-1 truncate', selected ? 'text-ink' : 'text-muted')}>
          {selected ? (
            <>
              {selected.label}
              {selected.sublabel ? <span className="text-muted"> · {selected.sublabel}</span> : null}
            </>
          ) : (prompt?.label ?? placeholder)}
        </span>
        <ChevronDownIcon className={classNames('size-4 shrink-0 text-muted transition-transform', open && 'rotate-180')} />
      </button>

      {open && createPortal(
        <div
          ref={menuRef}
          role="listbox"
          style={{ top: pos.top, bottom: pos.bottom, left: pos.left, width: pos.width, maxHeight: pos.maxHeight }}
          className="dialog-in glass-menu fixed z-[80] flex flex-col overflow-hidden rounded-2xl"
        >
          {showSearch && (
            <div className="shrink-0 border-b border-line p-2">
              <div className="relative">
                <MagnifyingGlassIcon className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted" />
                <input
                  ref={searchRef}
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  onKeyDown={onListKey}
                  placeholder="Type to search, Enter to pick…"
                  className="h-9 w-full rounded-xl glass-control pl-8 pr-3 text-sm placeholder:text-faint focus:outline-none focus:ring-2 focus:ring-leaf/25"
                />
              </div>
            </div>
          )}
          <div className="min-h-0 flex-1 overflow-y-auto py-1">
            {filtered.length === 0 ? (
              <p className="px-3 py-6 text-center text-sm text-muted">{choices.length === 0 ? emptyLabel : 'No matches'}</p>
            ) : filtered.map((opt) => {
              const active = String(opt.value) === String(value);
              const highlighted = !opt.disabled && filtered.filter((o) => !o.disabled).indexOf(opt) === hi;
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
                      : highlighted
                        ? 'bg-leaf-soft text-leaf-hover'
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
