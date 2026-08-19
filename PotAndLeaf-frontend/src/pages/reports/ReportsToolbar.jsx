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

  return (
    <div className="rounded-2xl border border-line bg-surface p-4 shadow-soft sm:p-5">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="flex items-start gap-3">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-leaf-soft text-leaf">
            <ChartBarIcon className="size-6" strokeWidth={1.5} />
          </span>
          <div>
            <h1 className="text-xl font-semibold tracking-tight text-ink">Reports</h1>
            <p className="mt-0.5 text-sm text-muted">{subtitle}</p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {isSuperAdmin ? (
            <CompanyFilter value={companyFilterValue} onChange={onCompanyChange} className="max-w-[240px]" />
          ) : (
            <div className="inline-flex h-10 items-center gap-2 rounded-xl border border-line bg-paper px-3 text-sm">
              <BuildingOffice2Icon className="size-4 text-leaf" />
              <span className="font-medium text-ink">{activeCompanyName}</span>
            </div>
          )}
          {extraFilters}
        </div>
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
        {!hideDateRange && (
          <>
            <div className="flex overflow-hidden rounded-xl border border-line">
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
                    className={classNames(
                      'px-3.5 py-2 text-xs font-semibold uppercase tracking-wide transition-colors',
                      on ? 'bg-leaf text-white' : 'bg-surface text-muted hover:bg-paper hover:text-ink',
                    )}
                  >
                    {p.label}
                  </button>
                );
              })}
              <button
                type="button"
                onClick={() => onToggleCustomDates?.(!showCustomDates)}
                className={classNames(
                  'inline-flex items-center gap-1 border-l border-line px-3.5 py-2 text-xs font-semibold uppercase tracking-wide transition-colors',
                  showCustomDates ? 'bg-leaf text-white' : 'bg-surface text-muted hover:bg-paper hover:text-ink',
                )}
              >
                <CalendarDaysIcon className="size-3.5" />
                Custom
              </button>
            </div>
            {showCustomDates && (
              <div className="flex flex-wrap items-center gap-2">
                <input
                  type="date"
                  value={range.from}
                  onChange={(e) => onRangeChange({ ...range, from: e.target.value })}
                  className="h-9 rounded-lg border border-line bg-paper px-2 text-sm"
                />
                <span className="text-muted">–</span>
                <input
                  type="date"
                  value={range.to}
                  onChange={(e) => onRangeChange({ ...range, to: e.target.value })}
                  className="h-9 rounded-lg border border-line bg-paper px-2 text-sm"
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
          <div className="relative ml-auto" ref={exportRef}>
            <Button
              size="sm"
              className="gap-1.5"
              onClick={() => setExportOpen((v) => !v)}
            >
              <ArrowDownTrayIcon className="size-4" />
              Export
              <ChevronDownIcon className={classNames('size-3.5 transition-transform', exportOpen && 'rotate-180')} />
            </Button>
            {exportOpen && (
              <div className="absolute right-0 z-20 mt-1 min-w-[160px] rounded-xl border border-line bg-surface py-1 shadow-pop">
                {exportOptions.map((opt) => (
                  <button
                    key={opt.label}
                    type="button"
                    className="block w-full px-4 py-2 text-left text-sm hover:bg-paper"
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
