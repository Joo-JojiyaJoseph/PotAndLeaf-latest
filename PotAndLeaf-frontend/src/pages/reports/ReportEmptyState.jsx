import { MagnifyingGlassIcon, ClipboardDocumentListIcon } from '@heroicons/react/24/outline';
import { Button } from '../../components/ui';

export default function ReportEmptyState({
  title = 'No data found',
  description = 'Try adjusting your filters or date range.',
  onChangeFilters,
}) {
  return (
    <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-line bg-surface/50 px-6 py-16 text-center">
      <div className="relative mb-5">
        <ClipboardDocumentListIcon className="size-16 text-leaf/25" strokeWidth={1.25} />
        <MagnifyingGlassIcon className="absolute -bottom-1 -right-2 size-8 text-muted/60" strokeWidth={1.5} />
      </div>
      <h3 className="text-base font-semibold text-ink">{title}</h3>
      <p className="mt-1.5 max-w-md text-sm text-muted">{description}</p>
      {onChangeFilters && (
        <Button className="mt-5" size="sm" onClick={onChangeFilters}>
          Change filters
        </Button>
      )}
    </div>
  );
}
