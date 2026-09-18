import { useState, useRef, useEffect } from 'react';
import {
  ChartBarIcon,
  CalendarDaysIcon,
  ChevronDownIcon,
  ArrowDownTrayIcon,
  BuildingOffice2Icon,
} from '@heroicons/react/24/outline';
import { Button } from '../../components/ui';
import { formatDate } from '../../lib/format';
import { classNames } from '../../lib/format';
import CompanyFilter from '../../components/CompanyFilter';

const PRESETS = [
  { label: '7D', days: 6 },
  { label: '30D', days: 29 },
  { label: '90D', days: 89 },
];

const BUSINESS_PERIODS = [
  { label: 'Today', kind: 'today' },
  { label: 'Week', kind: 'week' },
  { label: 'Month', kind: 'month' },
  { label: 'Year', kind: 'year' },
];

function rangeForKind(kind) {
  const to = new Date();
  const from = new Date();
  if (kind === 'today') {
    // from = to
  } else if (kind === 'week') {
    const day = from.getDay() || 7;
    from.setDate(from.getDate() - day + 1);
  } else if (kind === 'month') {
    from.setDate(1);
  } else if (kind === 'year') {
    from.setMonth(0, 1);
  }
  const iso = (d) => d.toISOString().slice(0, 10);
  return { from: iso(from), to: iso(to) };
}

export default function ReportsToolbar({
  subtitle,
  isSuperAdmin,
  companyFilterValue,
  onCompanyChange,
  activeCompanyName,
  range,
  onRangeChange,
  hideDateRange,
  showCustomDates,
  onToggleCustomDates,
  exportOptions,
  onExport,
  extraFilters,
  showBusinessPeriods,
}) {
  const [exportOpen, setExportOpen] = useState(false);
  const exportRef = useRef(null);
  const today = new Date().toISOString().slice(0, 10);

  const presetActive = (days) => {
    const from = new Date();
    from.setDate(from.getDate() - days);
    return range.from === from.toISOString().slice(0, 10) && range.to === today;
  };

  useEffect(() => {
    if (!exportOpen) return;
    const close = (e) => {
      if (exportRef.current && !exportRef.current.contains(e.target)) setExportOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [exportOpen]);

  const chip = (on) => classNames(
    'shrink-0 px-3 py-2 text-[11px] font-semibold uppercase tracking-wide transition-colors sm:px-3.5 sm:text-xs',
    on ? 'bg-leaf text-white' : 'bg-surface text-muted hover:bg-paper hover:text-ink',
  );

  return (
    <div className="glass-card p-3 sm:p-4 lg:p-5">
      <div className="flex flex-col gap-3 sm:gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="flex min-w-0 items-start gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-leaf-soft text-leaf sm:size-11">
            <ChartBarIcon className="size-5 sm:size-6" strokeWidth={1.5} />
          </span>
          <div className="min-w-0">
            <h1 className="page-title">Reports</h1>
            <p className="mt-0.5 text-xs text-muted sm:text-sm">{subtitle}</p>
          </div>
        </div>

        <div className="flex w-full min-w-0 flex-col gap-2 [&>*]:w-full sm:flex-row sm:flex-wrap sm:items-center sm:[&>*]:w-auto lg:w-auto lg:justify-end">
          {isSuperAdmin ? (
            <CompanyFilter value={companyFilterValue} onChange={onCompanyChange} className="w-full min-w-0 sm:w-auto sm:max-w-[240px]" />
          ) : (
            <div className="inline-flex h-10 min-w-0 max-w-full items-center gap-2 rounded-xl glass-control px-3 text-sm">
              <BuildingOffice2Icon className="size-4 shrink-0 text-leaf" />
              <span className="truncate font-medium text-ink">{activeCompanyName}</span>
            </div>
          )}
          {extraFilters}
        </div>
      </div>

      <div className="mt-3 flex flex-col gap-2 border-t border-line pt-3 sm:mt-4 sm:flex-row sm:flex-wrap sm:items-center sm:gap-2 sm:pt-4">
        {!hideDateRange && (
          <>
            <div className="-mx-1 flex max-w-full gap-0 overflow-x-auto overscroll-x-contain px-1 pb-0.5 sm:mx-0 sm:overflow-visible sm:rounded-xl sm:border sm:border-line sm:px-0">
              <div className="flex min-w-max overflow-hidden rounded-xl border border-line sm:min-w-0 sm:rounded-none sm:border-0">
                {PRESETS.map((p) => {
                  const on = presetActive(p.days);
                  return (
                    <button
                      key={p.label}
                      type="button"
                      onClick={() => {
                        const from = new Date();
                        from.setDate(from.getDate() - p.days);
                        onRangeChange({ from: from.toISOString().slice(0, 10), to: today });
                        onToggleCustomDates?.(false);
                      }}
                      className={chip(on)}
                    >
                      {p.label}
                    </button>
                  );
                })}
                {showBusinessPeriods && BUSINESS_PERIODS.map((p) => {
                  const r = rangeForKind(p.kind);
                  const on = range.from === r.from && range.to === r.to;
                  return (
                    <button
                      key={p.kind}
                      type="button"
                      onClick={() => {
                        onRangeChange(rangeForKind(p.kind));
                        onToggleCustomDates?.(false);
                      }}
                      className={classNames(chip(on), 'border-l border-line')}
                    >
                      {p.label}
                    </button>
                  );
                })}
                <button
                  type="button"
                  onClick={() => onToggleCustomDates?.(!showCustomDates)}
                  className={classNames(
                    'inline-flex shrink-0 items-center gap-1 border-l border-line',
                    chip(showCustomDates),
                  )}
                >
                  <CalendarDaysIcon className="size-3.5" />
                  Custom
                </button>
              </div>
            </div>
            {showCustomDates && (
              <div className="flex w-full min-w-0 flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center">
                <input
                  type="date"
                  value={range.from}
                  onChange={(e) => onRangeChange({ ...range, from: e.target.value })}
                  className="h-10 w-full rounded-xl glass-control px-2 text-sm sm:h-9 sm:w-auto"
                />
                <span className="hidden text-muted sm:inline">–</span>
                <input
                  type="date"
                  value={range.to}
                  onChange={(e) => onRangeChange({ ...range, to: e.target.value })}
                  className="h-10 w-full rounded-xl glass-control px-2 text-sm sm:h-9 sm:w-auto"
                />
              </div>
            )}
            {!showCustomDates && (
              <span className="text-xs text-muted">
                {formatDate(range.from)} – {formatDate(range.to)}
              </span>
            )}
          </>
        )}

        {exportOptions?.length > 0 && (
          <div className="relative w-full sm:ml-auto sm:w-auto" ref={exportRef}>
            <Button
              size="sm"
              className="w-full gap-1.5 sm:w-auto"
              onClick={() => setExportOpen((v) => !v)}
            >
              <ArrowDownTrayIcon className="size-4" />
              Export
              <ChevronDownIcon className={classNames('size-3.5 transition-transform', exportOpen && 'rotate-180')} />
            </Button>
            {exportOpen && (
              <div className="absolute right-0 z-20 mt-1 min-w-[160px] rounded-2xl glass-menu py-1">
                {exportOptions.map((opt) => (
                  <button
                    key={opt.label}
                    type="button"
                    className="block w-full px-4 py-2.5 text-left text-sm hover:bg-paper"
                    onClick={() => { setExportOpen(false); onExport(opt); }}
                  >
                    {opt.label}
                  </button>
                ))}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
