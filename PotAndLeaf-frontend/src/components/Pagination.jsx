import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/outline';

export const PER_PAGE_OPTIONS = [10, 25, 50, 100];

/**
 * Page bar under a list: "1–25 of 140", page buttons, and (when `onPerPage`
 * is given) a "Rows per page" choice. Shown even when everything fits on one
 * page, so people can see how many rows there are and that paging exists.
 */
export default function Pagination({ meta, onPage, onPerPage }) {
  if (!meta) return null;
  const cur = Number(meta.current_page) || 1;
  const last = Math.max(1, Number(meta.last_page) || 1);
  const total = Number(meta.total ?? 0);
  if (total === 0 && last <= 1) return null;
  // Some lists only send per_page; work out "21–40" from it.
  const per = Number(meta.per_page) || 0;
  const from = meta.from ?? (per ? (cur - 1) * per + 1 : null);
  const to = meta.to ?? (per ? Math.min(cur * per, total || cur * per) : null);

  const pages = [];
  const add = (p) => pages.push(p);
  add(1);
  for (let p = cur - 1; p <= cur + 1; p++) if (p > 1 && p < last) add(p);
  if (last > 1) add(last);
  const uniq = [...new Set(pages)].sort((a, b) => a - b);
  const perOptions = per && !PER_PAGE_OPTIONS.includes(per) ? [...PER_PAGE_OPTIONS, per].sort((a, b) => a - b) : PER_PAGE_OPTIONS;

  return (
    <div className="flex flex-wrap items-center justify-between gap-2 px-1 py-3 text-sm">
      <div className="flex flex-wrap items-center gap-3">
        <span className="text-muted">{from != null ? `${from}–${to} of ${total}` : `Page ${cur} of ${last}`}</span>
        {onPerPage && (
          <label className="flex items-center gap-1.5 text-xs text-muted">
            Rows per page
            <select
              value={per || 25}
              onChange={(e) => onPerPage(Number(e.target.value))}
              className="h-8 rounded-lg border border-line bg-surface px-1.5 text-xs text-ink"
              aria-label="Rows per page"
            >
              {perOptions.map((n) => <option key={n} value={n}>{n}</option>)}
            </select>
          </label>
        )}
      </div>
      <div className="flex items-center gap-1">
        <button type="button" aria-label="Previous page" disabled={cur <= 1} onClick={() => onPage(cur - 1)}
          className="flex size-8 items-center justify-center rounded-xl glass-control text-muted disabled:opacity-40 enabled:hover:bg-white/90"><ChevronLeftIcon className="size-4" /></button>
        {uniq.map((p, i) => (
          <span key={p} className="flex items-center">
            {i > 0 && p - uniq[i - 1] > 1 && <span className="px-1 text-faint">…</span>}
            <button type="button" onClick={() => onPage(p)}
              className={'flex size-8 items-center justify-center rounded-xl text-sm transition-colors ' + (p === cur ? 'bg-leaf font-medium text-white shadow-soft' : 'glass-control text-muted hover:bg-white/90')}>{p}</button>
          </span>
        ))}
        <button type="button" aria-label="Next page" disabled={cur >= last} onClick={() => onPage(cur + 1)}
          className="flex size-8 items-center justify-center rounded-xl glass-control text-muted disabled:opacity-40 enabled:hover:bg-white/90"><ChevronRightIcon className="size-4" /></button>
      </div>
    </div>
  );
}
