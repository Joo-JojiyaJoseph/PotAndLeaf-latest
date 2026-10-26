import { useState } from 'react';
import { ArrowDownTrayIcon, ArrowUpTrayIcon } from '@heroicons/react/24/outline';
import { Button, Spinner } from './ui';
import ImportModal from './ImportModal';
import { exportList } from '../lib/exportList';
import { useToast } from '../lib/toast';

/** Import + Export buttons for the master lists (products, customers, suppliers). */
export default function ImportExportButtons({ entity, title, columns, exportColumns, exportParams, canImport, onDone }) {
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const toast = useToast();

  async function runExport() {
    setBusy(true);
    try {
      const n = await exportList({ url: `/${entity}`, params: exportParams, columns: exportColumns, filename: entity });
      toast?.success?.(`${n} ${entity} downloaded.`);
    } catch {
      toast?.error?.('Could not download the list. Try again.');
    } finally { setBusy(false); }
  }

  return (
    <>
      {canImport && <Button variant="outline" size="sm" onClick={() => setOpen(true)}><ArrowUpTrayIcon className="size-4" /> Import</Button>}
      <Button variant="outline" size="sm" onClick={runExport} disabled={busy} title="Download the list (with the filters you chose) as a spreadsheet that opens in Excel">
        {busy ? <Spinner className="size-4" /> : <ArrowDownTrayIcon className="size-4" />} Export
      </Button>
      {canImport && <ImportModal open={open} onClose={() => setOpen(false)} entity={entity} title={title} columns={columns} onDone={onDone} />}
    </>
  );
}
