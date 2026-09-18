import { MagnifyingGlassIcon } from '@heroicons/react/24/outline';
import { classNames } from '../../lib/format';

export default function ReportNavGrid({ tabs, activeTab, onSelect, search, onSearchChange }) {
  const q = search.trim().toLowerCase();
  const visible = q
    ? tabs.filter((t) => t.label.toLowerCase().includes(q) || t.shortLabel?.toLowerCase().includes(q))
    : tabs;

  return (
    <div className="rounded-2xl border border-line bg-surface p-3 shadow-soft sm:p-4">
      <div className="relative mb-3 w-full max-w-md sm:mb-4">
        <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
        <input
          type="search"
          value={search}
          onChange={(e) => onSearchChange(e.target.value)}
          placeholder="Search reports…"
          className="h-10 w-full rounded-xl border border-line bg-paper pl-9 pr-3 text-sm focus:border-leaf/40 focus:outline-none focus:ring-2 focus:ring-leaf/15"
        />
      </div>
      <div className="-mx-1 flex gap-1 overflow-x-auto overscroll-x-contain px-1 pb-1 sm:mx-0 sm:grid sm:grid-cols-4 sm:overflow-visible sm:px-0 md:grid-cols-6 lg:grid-cols-8 xl:grid-cols-11">
        {visible.map((t) => {
          const Icon = t.icon;
          const active = t.value === activeTab;
          return (
            <button
              key={t.value}
              type="button"
              onClick={() => onSelect(t.value)}
              title={t.label}
              className={classNames(
                'group flex min-w-[4.75rem] shrink-0 flex-col items-center gap-1 rounded-xl px-2 py-2.5 text-center transition-all sm:min-w-0 sm:gap-1.5 sm:py-3',
                active
                  ? 'bg-leaf-soft text-leaf-hover'
                  : 'text-muted hover:bg-paper hover:text-ink',
              )}
            >
              <Icon className={classNames('size-5 shrink-0 sm:size-6', active ? 'text-leaf' : 'text-muted group-hover:text-leaf/70')} strokeWidth={1.5} />
              <span className={classNames('line-clamp-2 text-[10px] leading-tight sm:text-[11px]', active ? 'font-semibold text-leaf-hover' : 'font-medium')}>
                {t.shortLabel ?? t.label}
              </span>
              {active && <span className="h-0.5 w-6 rounded-full bg-leaf sm:w-8" />}
            </button>
          );
        })}
      </div>
      {visible.length === 0 && (
        <p className="py-6 text-center text-sm text-muted">No reports match &ldquo;{search}&rdquo;</p>
      )}
    </div>
  );
}
