import Pagination from './Pagination';
import usePaged from '../hooks/usePaged';

/**
 * Show a long table 25 rows at a time with page buttons under it.
 * Usage: <PagedTable rows={list}>{(pageRows) => <table>…pageRows.map(…)…</table>}</PagedTable>
 */
export default function PagedTable({ rows, perPage = 25, children }) {
  const list = Array.isArray(rows) ? rows : [];
  const { pageRows, meta, setPage } = usePaged(list, perPage, list.length);
  return (
    <>
      {children(pageRows)}
      {meta.last_page > 1 && <div className="border-t border-line px-3"><Pagination meta={meta} onPage={setPage} /></div>}
    </>
  );
}
