import { useEffect, useMemo, useState } from 'react';

/**
 * Page a list that is already loaded in the browser (25 rows a page by
 * default). Returns the rows for the current page and a `meta` object in the
 * same shape as the server's, so it works with <Pagination meta onPage />.
 * Goes back to page 1 whenever `resetKey` (e.g. the search text) changes.
 */
export default function usePaged(rows, perPage = 25, resetKey = '') {
  const list = useMemo(() => (Array.isArray(rows) ? rows : []), [rows]);
  const [page, setPage] = useState(1);
  const last = Math.max(1, Math.ceil(list.length / perPage));

  useEffect(() => { setPage(1); }, [resetKey]);
  useEffect(() => { if (page > last) setPage(last); }, [page, last]);

  const cur = Math.min(page, last);
  const start = (cur - 1) * perPage;
  const pageRows = useMemo(() => list.slice(start, start + perPage), [list, start, perPage]);
  const meta = {
    current_page: cur,
    last_page: last,
    total: list.length,
    from: list.length ? start + 1 : 0,
    to: Math.min(start + perPage, list.length),
    per_page: perPage,
  };

  return { pageRows, meta, setPage, offset: start };
}
