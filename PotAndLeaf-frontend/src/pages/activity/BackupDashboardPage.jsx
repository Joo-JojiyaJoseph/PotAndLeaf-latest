import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDownTrayIcon, ArrowPathIcon, CloudArrowUpIcon } from '@heroicons/react/24/outline';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../lib/toast';
import { useConfirm } from '../../lib/confirm';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import { formatDate } from '../../lib/format';
import { downloadWithParams } from '../../lib/pdfDownload';

function formatBytes(n) {
  if (!n) return '0 B';
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

export default function BackupDashboardPage() {
  const { activeCompany, can, isSuperAdmin } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const queryClient = useQueryClient();
  const allowed = isSuperAdmin || can('backup.view') || can('*');

  const listQ = useQuery({
    queryKey: ['backups', activeCompany?.id],
    queryFn: () => api.get('/backups').then((r) => r.data.data.backups),
    enabled: Boolean(activeCompany) && allowed,
    refetchInterval: 30_000,
  });

  const runM = useMutation({
    mutationFn: () => api.post('/backups/run'),
    onSuccess: (res) => {
      toast.success(res.data?.message || 'Manual backup completed.');
      queryClient.invalidateQueries({ queryKey: ['backups'] });
    },
    onError: (err) => toast.error(err.response?.data?.message || 'Backup failed.'),
  });

  const restoreM = useMutation({
    mutationFn: (filename) => api.post(`/backups/${encodeURIComponent(filename)}/restore`),
    onSuccess: (res) => {
      toast.success(res.data?.message || 'Restore completed. A safety backup was taken first.');
      queryClient.invalidateQueries({ queryKey: ['backups'] });
    },
    onError: (err) => toast.error(err.response?.data?.message || 'Restore failed.'),
  });

  async function onDownload(filename) {
    try {
      await downloadWithParams(`/backups/${encodeURIComponent(filename)}/download`, undefined, filename, 'application/x-sqlite3');
      toast.success('Download started.');
    } catch {
      toast.error('Download failed.');
    }
  }

  async function onRestore(filename) {
    const ok = await confirm({
      title: 'Restore database?',
      message: `This will overwrite current data with ${filename}. A safety backup of the current database is taken automatically first.`,
      confirmLabel: 'Restore',
      tone: 'danger',
    });
    if (!ok) return;
    restoreM.mutate(filename);
  }

  if (!allowed) {
    return <div className="p-6 text-sm text-muted">HO Admin / Super Admin access required.</div>;
  }

  const rows = listQ.data ?? [];

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold">Backup Monitoring</h1>
          <p className="text-sm text-muted">Automatic nightly SQLite exports plus on-demand backups. Restore takes a safety copy first.</p>
        </div>
        {(isSuperAdmin || can('backup.run') || can('*')) && (
          <Button size="sm" disabled={runM.isPending} onClick={() => runM.mutate()}>
            {runM.isPending ? <Spinner className="border-white/40 border-t-white" /> : <CloudArrowUpIcon className="size-4" />}
            Backup Now
          </Button>
        )}
      </div>

      <Card className="overflow-hidden">
        {listQ.isLoading ? <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
          : rows.length === 0 ? <div className="px-4 py-16 text-center text-sm text-muted">No backups yet. Run Backup Now or wait for the nightly job.</div>
          : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-line text-left text-faint">
                  <th className="microlabel px-4 py-2.5 font-semibold">File</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Type</th>
                  <th className="microlabel px-4 py-2.5 font-semibold">Created</th>
                  <th className="microlabel px-4 py-2.5 text-right font-semibold">Size</th>
                  <th className="microlabel px-4 py-2.5" />
                </tr></thead>
                <tbody>
                  {rows.map((b) => (
                    <tr key={b.filename} className="border-b border-line/60 last:border-0">
                      <td className="tnum px-4 py-2.5 text-xs">{b.filename}</td>
                      <td className="px-4 py-2.5">
                        <Badge tone={b.type === 'automatic' ? 'active' : b.type === 'pre-restore' ? 'warning' : 'inactive'}>{b.type}</Badge>
                      </td>
                      <td className="px-4 py-2.5 text-muted">{formatDate(b.created_at)}</td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{formatBytes(b.size)}</td>
                      <td className="px-4 py-2.5">
                        <div className="flex items-center justify-end gap-1">
                          <button type="button" onClick={() => onDownload(b.filename)} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-ink" title="Download">
                            <ArrowDownTrayIcon className="size-4" />
                          </button>
                          {(isSuperAdmin || can('backup.restore') || can('*')) && (
                            <button type="button" onClick={() => onRestore(b.filename)} disabled={restoreM.isPending} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger" title="Restore">
                              <ArrowPathIcon className="size-4" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
      </Card>
    </div>
  );
}
