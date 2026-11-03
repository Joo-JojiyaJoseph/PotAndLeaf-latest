import { createContext, useContext, useState } from 'react';
import Pagination from './Pagination';
import usePaged from '../hooks/usePaged';

/** When true (e.g. while a report is being exported), every row is drawn, no page buttons. */
export const ShowAllRowsContext = createContext(false);

/**
 * Show a long table 25 rows at a time (or the rows-per-page chosen under it)
 * with the page bar under it — shown whenever there are rows, like every list.
 * Usage: <PagedTable rows={list}>{(pageRows) => <table>…pageRows.map(…)…</table>}</PagedTable>
 */
export default function PagedTable({ rows, perPage = 25, children }) {
  const showAll = useContext(ShowAllRowsContext);
  const list = Array.isArray(rows) ? rows : [];
  const [size, setSize] = useState(perPage);
  const { pageRows, meta, setPage } = usePaged(list, size, `${list.length}|${size}`);
  if (showAll) return <>{children(list)}</>;
  return (
    <>
      {children(pageRows)}
      {list.length > 0 && (
        <div className="border-t border-line px-3">
          <Pagination meta={meta} onPage={setPage} onPerPage={(n) => { setSize(n); setPage(1); }} />
        </div>
      )}
    </>
  );
}
