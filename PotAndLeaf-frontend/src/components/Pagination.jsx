import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/outline';

export default function Pagination({ meta, onPage }) {
  if (!meta || (meta.last_page ?? 1) <= 1) return null;
  const { current_page: cur, last_page: last, from, to, total } = meta;

  const pages = [];
  const add = (p) => pages.push(p);
  add(1);
  for (let p = cur - 1; p <= cur + 1; p++) if (p > 1 && p < last) add(p);
  if (last > 1) add(last);
  const uniq = [...new Set(pages)].sort((a, b) => a - b);

  return (
    <div className="flex items-center justify-between px-1 py-3 text-sm">
      <span className="text-muted">{from}–{to} of {total}</span>
      <div className="flex items-center gap-1">
        <button disabled={cur <= 1} onClick={() => onPage(cur - 1)}
          className="flex size-8 items-center justify-center rounded-lg border border-line text-muted disabled:opacity-40 enabled:hover:bg-sidebar"><ChevronLeftIcon className="size-4" /></button>
        {uniq.map((p, i) => (
          <span key={p} className="flex items-center">
            {i > 0 && p - uniq[i - 1] > 1 && <span className="px-1 text-faint">…</span>}
            <button onClick={() => onPage(p)}
              className={'flex size-8 items-center justify-center rounded-lg text-sm ' + (p === cur ? 'bg-leaf font-medium text-white' : 'border border-line text-muted hover:bg-sidebar')}>{p}</button>
          </span>
        ))}
        <button disabled={cur >= last} onClick={() => onPage(cur + 1)}
          className="flex size-8 items-center justify-center rounded-lg border border-line text-muted disabled:opacity-40 enabled:hover:bg-sidebar"><ChevronRightIcon className="size-4" /></button>
      </div>
    </div>
  );
}
