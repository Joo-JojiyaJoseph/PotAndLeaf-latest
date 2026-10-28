import { createContext, useContext } from 'react';
import Pagination from './Pagination';
import usePaged from '../hooks/usePaged';

/** When true (e.g. while a report is being exported), every row is drawn, no page buttons. */
export const ShowAllRowsContext = createContext(false);

/**
 * Show a long table 25 rows at a time with page buttons under it.
 * Usage: <PagedTable rows={list}>{(pageRows) => <table>…pageRows.map(…)…</table>}</PagedTable>
 */
export default function PagedTable({ rows, perPage = 25, children }) {
  const showAll = useContext(ShowAllRowsContext);
  const list = Array.isArray(rows) ? rows : [];
  const { pageRows, meta, setPage } = usePaged(list, perPage, list.length);
  if (showAll) return <>{children(list)}</>;
  return (
    <>
      {children(pageRows)}
      {meta.last_page > 1 && <div className="border-t border-line px-3"><Pagination meta={meta} onPage={setPage} /></div>}
    </>
  );
}
