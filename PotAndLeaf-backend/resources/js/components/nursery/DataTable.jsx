import { useEffect, useState } from 'react';
import {
    flexRender,
    getCoreRowModel,
    useReactTable,
} from '@tanstack/react-table';
import {
    ChevronDownIcon,
    ChevronUpDownIcon,
    ChevronUpIcon,
} from '@heroicons/react/16/solid';

/**
 * Presentational, server-driven table.
 *
 * The page owns the data + query params (search/sort/page) and passes them in;
 * this component only renders and reports intent back through callbacks. That
 * keeps every list page consistent and the Inertia wiring in one place.
 *
 * Props:
 *   columns        TanStack column definitions
 *   data           current page rows
 *   meta           Laravel paginator meta { current_page, last_page, from, to, total }
 *   sort, dir      active sort column + direction ('asc' | 'desc')
 *   onSort(colId)  request a sort change
 *   onPage(page)   request a page change
 *   selectable     show the bulk-select checkbox column
 *   onSelect(ids)  report selected row ids
 *   mobileCard(row) optional renderer for the < sm card layout
 *   emptyState     node shown when there are no rows
 */
export default function DataTable({
    columns,
    data,
    meta,
    sort,
    dir,
    onSort,
    onPage,
    selectable = false,
    onSelect,
    mobileCard,
    emptyState = null,
}) {
    const [rowSelection, setRowSelection] = useState({});

    const table = useReactTable({
        data,
        columns,
        state: { rowSelection },
        enableRowSelection: selectable,
        onRowSelectionChange: setRowSelection,
        getRowId: (row) => row.id,
        getCoreRowModel: getCoreRowModel(),
        manualSorting: true,
        manualPagination: true,
    });

    useEffect(() => {
        if (onSelect) onSelect(Object.keys(rowSelection));
    }, [rowSelection, onSelect]);

    const allVisibleSelected =
        data.length > 0 && data.every((r) => rowSelection[r.id]);

    function toggleAll(checked) {
        const next = {};
        if (checked) data.forEach((r) => (next[r.id] = true));
        setRowSelection(next);
    }

    function SortIcon({ columnId, canSort }) {
        if (!canSort) return null;
        if (sort !== columnId)
            return <ChevronUpDownIcon className="size-4 text-muted-foreground/60" />;
        return dir === 'asc' ? (
            <ChevronUpIcon className="size-4 text-primary" />
        ) : (
            <ChevronDownIcon className="size-4 text-primary" />
        );
    }

    if (data.length === 0 && emptyState) return emptyState;

    return (
        <div className="w-full">
            {/* Desktop / tablet: real table with a sticky header */}
            <div className="hidden overflow-x-auto rounded-lg border border-border bg-card sm:block">
                <table className="w-full text-sm">
                    <thead className="sticky top-0 z-10 bg-muted/60 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        <tr>
                            {selectable && (
                                <th className="w-10 px-3 py-2.5">
                                    <input
                                        type="checkbox"
                                        className="size-4 rounded border-input text-primary focus:ring-ring"
                                        checked={allVisibleSelected}
                                        onChange={(e) => toggleAll(e.target.checked)}
                                        aria-label="Select all rows on this page"
                                    />
                                </th>
                            )}
                            {table.getFlatHeaders().map((header) => {
                                const canSort =
                                    header.column.columnDef.enableSorting !== false &&
                                    Boolean(header.column.id);
                                return (
                                    <th
                                        key={header.id}
                                        className="px-3 py-2.5 font-semibold"
                                    >
                                        <button
                                            type="button"
                                            disabled={!canSort}
                                            onClick={() => canSort && onSort(header.column.id)}
                                            className="inline-flex items-center gap-1 disabled:cursor-default"
                                        >
                                            {flexRender(
                                                header.column.columnDef.header,
                                                header.getContext(),
                                            )}
                                            <SortIcon
                                                columnId={header.column.id}
                                                canSort={canSort}
                                            />
                                        </button>
                                    </th>
                                );
                            })}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {table.getRowModel().rows.map((row) => (
                            <tr
                                key={row.id}
                                className="transition-colors hover:bg-accent/60 data-[selected=true]:bg-accent"
                                data-selected={Boolean(rowSelection[row.id])}
                            >
                                {selectable && (
                                    <td className="px-3 py-2.5">
                                        <input
                                            type="checkbox"
                                            className="size-4 rounded border-input text-primary focus:ring-ring"
                                            checked={Boolean(rowSelection[row.id])}
                                            onChange={row.getToggleSelectedHandler()}
                                            aria-label="Select row"
                                        />
                                    </td>
                                )}
                                {row.getVisibleCells().map((cell) => (
                                    <td
                                        key={cell.id}
                                        className="px-3 py-2.5 text-foreground"
                                    >
                                        {flexRender(
                                            cell.column.columnDef.cell,
                                            cell.getContext(),
                                        )}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Mobile: stacked cards, no horizontal scroll */}
            {mobileCard && (
                <div className="space-y-3 sm:hidden">
                    {data.map((row) => (
                        <div
                            key={row.id}
                            className="rounded-lg border border-border bg-card p-4"
                        >
                            {mobileCard(row)}
                        </div>
                    ))}
                </div>
            )}

            {/* Pagination */}
            {meta && meta.last_page > 1 && (
                <div className="mt-4 flex flex-col items-center justify-between gap-3 text-sm text-muted-foreground sm:flex-row">
                    <span>
                        Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
                    </span>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => onPage(meta.current_page - 1)}
                            disabled={meta.current_page <= 1}
                            className="rounded-md border border-border px-3 py-1.5 font-medium text-foreground transition-colors hover:bg-accent disabled:opacity-40"
                        >
                            Previous
                        </button>
                        <span className="px-1">
                            Page {meta.current_page} of {meta.last_page}
                        </span>
                        <button
                            type="button"
                            onClick={() => onPage(meta.current_page + 1)}
                            disabled={meta.current_page >= meta.last_page}
                            className="rounded-md border border-border px-3 py-1.5 font-medium text-foreground transition-colors hover:bg-accent disabled:opacity-40"
                        >
                            Next
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
