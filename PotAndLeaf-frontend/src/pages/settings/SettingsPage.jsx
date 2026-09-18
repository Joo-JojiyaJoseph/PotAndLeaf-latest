import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Spinner, Select } from '../../components/ui';
import { useToast } from '../../lib/toast';
import { FormCompanyField } from '../../components/FormCompanyField';
import { formatDate } from '../../lib/format';

const GROUPS = [
  {
    title: 'Loyalty points',
    keys: [
      { key: 'loyalty_earn_rupees', label: 'Earn: ₹ spent per point', type: 'number', step: '1' },
      { key: 'loyalty_earn_points', label: 'Earn: points per unit', type: 'number', step: '1' },
      { key: 'loyalty_redeem_rupees', label: 'Redeem: ₹ value per point', type: 'number', step: '0.01' },
      { key: 'loyalty_redeem_cap_percent', label: 'Redeem cap (% of bill)', type: 'number', step: '1' },
    ],
  },
  {
    title: 'Sales & discounts',
    keys: [
      { key: 'discount_ceiling_percent', label: 'Discount ceiling (%)', type: 'number', step: '1' },
      { key: 'sale_cancel_requires_approval', label: 'Require HO approval to cancel confirmed sales', type: 'toggle' },
    ],
  },
  {
    title: 'Accounting',
    keys: [
      { key: 'cash_opening_balance', label: 'Cash opening balance (₹)', type: 'number', step: '0.01' },
      { key: 'bank_opening_balance', label: 'Bank opening balance (₹)', type: 'number', step: '0.01' },
    ],
  },
  {
    title: 'Inventory & company',
    keys: [
      { key: 'reorder_alert_default', label: 'Default reorder alert qty', type: 'number', step: '1' },
      { key: 'daily_expense', label: 'Daily expense (₹, for approx. profit)', type: 'number', step: '0.01' },
      { key: 'whatsapp_enabled', label: 'WhatsApp invoice sharing', type: 'toggle' },
      { key: 'website_integration', label: 'Allow company website / app API', type: 'toggle' },
    ],
  },
  {
    title: 'Reports & EOD',
    keys: [
      { key: 'financial_year_start_month', label: 'Financial year start month (1–12)', type: 'number', step: '1' },
      { key: 'dead_stock_days', label: 'Dead stock threshold (days)', type: 'number', step: '1' },
      { key: 'eod_management_enabled', label: 'Send HO management EOD summary', type: 'toggle' },
      { key: 'eod_management_whatsapp_phones', label: 'EOD WhatsApp phones (comma-separated)', type: 'text' },
      { key: 'eod_management_email_recipients', label: 'EOD email recipients (comma-separated)', type: 'text' },
      { key: 'eod_management_send_time', label: 'EOD send time (HH:MM, 24h)', type: 'text' },
    ],
  },
  {
    title: 'Rental automation',
    keys: [
      { key: 'rental_auto_bill', label: 'Auto-generate invoices on billing cycle', type: 'toggle' },
      { key: 'rental_whatsapp_on_bill', label: 'WhatsApp when auto-billing', type: 'toggle' },
      { key: 'sms_enabled', label: 'SMS rental reminders & overdue alerts', type: 'toggle' },
      { key: 'rental_payment_due_days', label: 'Payment due days after period end', type: 'number', step: '1' },
      { key: 'rental_overdue_alert_days', label: 'Overdue alert grace (days)', type: 'number', step: '1' },
      { key: 'rental_reminder_lead_days', label: 'Reminder lead time (days before due)', type: 'number', step: '1' },
    ],
  },
];

function CompanyApiKeysCard() {
  const { activeCompany, can, isSuperAdmin } = useAuth();
  const toast = useToast();
  const queryClient = useQueryClient();
  const canView = isSuperAdmin || can('api.view') || can('api.manage') || can('*');
  const canManage = isSuperAdmin || can('api.manage') || can('*');
  const [name, setName] = useState('Website');
  const [plaintext, setPlaintext] = useState('');

  const keysQ = useQuery({
    queryKey: ['company-api-keys', activeCompany?.id],
    queryFn: () => api.get('/company-api-keys').then((r) => r.data.data),
    enabled: canView && Boolean(activeCompany),
  });

  const createM = useMutation({
    mutationFn: () => api.post('/company-api-keys', { name: name.trim() || 'Website' }),
    onSuccess: (res) => {
      setPlaintext(res.data.data?.api_key || '');
      queryClient.invalidateQueries({ queryKey: ['company-api-keys'] });
      toast.success(res.data.message || 'API key created.');
    },
    onError: (err) => toast.error(err.response?.data?.message ?? 'Could not create API key.'),
  });

  const revokeM = useMutation({
    mutationFn: (id) => api.post(`/company-api-keys/${id}/revoke`),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['company-api-keys'] }); toast.success('API key disabled.'); },
    onError: (err) => toast.error(err.response?.data?.message ?? 'Could not disable API key.'),
  });

  const regenM = useMutation({
    mutationFn: (id) => api.post(`/company-api-keys/${id}/regenerate`),
    onSuccess: (res) => {
      setPlaintext(res.data.data?.api_key || '');
      queryClient.invalidateQueries({ queryKey: ['company-api-keys'] });
      toast.success(res.data.message || 'API key regenerated.');
    },
    onError: (err) => toast.error(err.response?.data?.message ?? 'Could not regenerate API key.'),
  });

  if (!canView) return null;
  const keys = keysQ.data ?? [];

  return (
    <Card className="p-5">
      <h2 className="text-sm font-semibold">Company website API</h2>
      <p className="mt-1 text-sm text-muted">
        Generate a key so this company can build a website or app against <span className="font-mono text-xs">/api/v1</span>.
        Turn on “Allow company website / app API” above, then send the key as <span className="font-mono text-xs">X-Api-Key</span>.
        The company is taken from the key — never send a company id from the client.
      </p>
      {plaintext && (
        <div className="mt-3 rounded-xl border border-leaf/30 bg-leaf-soft/40 p-3 text-sm">
          <p className="text-xs font-medium text-leaf-hover">Copy this key now. It will not be shown again.</p>
          <code className="mt-1 block break-all text-xs">{plaintext}</code>
          <Button size="sm" variant="outline" className="mt-2" onClick={() => { navigator.clipboard.writeText(plaintext); toast.success('Copied.'); }}>Copy key</Button>
        </div>
      )}
      {canManage && (
        <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-end">
          <Field label="Key name" className="flex-1">
            <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Website" />
          </Field>
          <Button size="sm" disabled={createM.isPending} onClick={() => createM.mutate()}>
            {createM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Generate key'}
          </Button>
        </div>
      )}
      {keysQ.isLoading ? <div className="flex justify-center py-8"><Spinner /></div>
        : keys.length === 0 ? <p className="mt-4 text-sm text-muted">No API keys yet.</p>
        : (
          <div className="mt-4 overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-line text-left text-faint">
                <th className="microlabel py-2 pr-3">Name</th>
                <th className="microlabel py-2 pr-3">Prefix</th>
                <th className="microlabel py-2 pr-3">Status</th>
                <th className="microlabel py-2 pr-3">Last used</th>
                {canManage && <th className="microlabel py-2" />}
              </tr></thead>
              <tbody>
                {keys.map((k) => (
                  <tr key={k.id} className="border-b border-line/60">
                    <td className="py-2.5 font-medium">{k.name}</td>
                    <td className="tnum py-2.5 text-muted">{k.key_prefix}</td>
                    <td className="py-2.5 capitalize">{k.status}</td>
                    <td className="py-2.5 text-muted">{k.last_used_at ? formatDate(k.last_used_at) : 'Never'}</td>
                    {canManage && (
                      <td className="py-2.5 text-right">
                        <div className="flex flex-wrap justify-end gap-2">
                          {k.status === 'active' && (
                            <Button size="sm" variant="ghost" disabled={revokeM.isPending} onClick={() => revokeM.mutate(k.id)}>Disable</Button>
                          )}
                          <Button size="sm" variant="outline" disabled={regenM.isPending} onClick={() => regenM.mutate(k.id)}>Regenerate</Button>
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      <p className="mt-3 text-xs text-muted">Docs: <span className="font-mono">GET /api/v1/openapi.json</span> · Header <span className="font-mono">X-Api-Key</span></p>
    </Card>
  );
}

export default function SettingsPage() {
  const { activeCompany, can, isSuperAdmin, selectCompany } = useAuth();
  const toast = useToast();
  const queryClient = useQueryClient();
  const [form, setForm] = useState({});
  const [darkMode, setDarkMode] = useState(() => localStorage.getItem('pl_theme') === 'dark');
  const canApi = isSuperAdmin || can('api.view') || can('api.manage') || can('*');
  const canView = isSuperAdmin || can('settings.view') || canApi || can('*');
  const canEdit = isSuperAdmin || can('settings.update') || can('*');
  const canSettings = isSuperAdmin || can('settings.view') || can('*');

  useEffect(() => {
    document.documentElement.classList.toggle('dark', darkMode);
    localStorage.setItem('pl_theme', darkMode ? 'dark' : 'light');
  }, [darkMode]);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['settings', activeCompany?.id],
    queryFn: () => api.get('/settings').then((r) => r.data.data),
    enabled: Boolean(activeCompany) && canSettings,
  });

  useEffect(() => {
    if (data) setForm(data);
  }, [data]);

  const saveM = useMutation({
    mutationFn: (payload) => api.put('/settings', payload),
    onSuccess: (res) => {
      setForm(res.data.data);
      queryClient.invalidateQueries({ queryKey: ['settings'] });
      toast.success('Settings saved.');
    },
    onError: (err) => toast.error(err.response?.data?.message ?? 'Could not save settings.'),
  });

  if (!canView) {
    return (
      <div className="p-6">
        <Card className="p-10 text-center text-sm text-muted">You don't have access to settings.</Card>
      </div>
    );
  }

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <div>
        <h1 className="page-title">Settings</h1>
        <p className="text-sm text-muted">Additional settings for {activeCompany?.name}.</p>
      </div>

      <FormCompanyField value={activeCompany?.id ?? ''} onChange={selectCompany} hint="Settings, API keys and loyalty rates apply to this company." />

      <Card className="p-5">
        <h2 className="text-sm font-semibold">Appearance</h2>
        <div className="mt-4 max-w-xs">
          <Field label="Dark mode">
            <Select
              value={darkMode ? '1' : '0'}
              onChange={(e) => setDarkMode(e.target.value === '1')}
              className="h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25"
            >
              <option value="0">Light</option>
              <option value="1">Dark</option>
            </Select>
          </Field>
        </div>
      </Card>

      {canSettings && (isLoading ? (
        <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
      ) : isError ? (
        <Card className="px-4 py-12 text-center text-sm text-muted">Couldn't load settings.</Card>
      ) : (
        GROUPS.map((group) => (
          <Card key={group.title} className="p-5">
            <h2 className="text-sm font-semibold">{group.title}</h2>
            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
              {group.keys.map((field) => (
                <Field key={field.key} label={field.label}>
                  {field.type === 'toggle' ? (
                    <Select
                      value={form[field.key] === '1' || form[field.key] === true ? '1' : '0'}
                      disabled={!canEdit}
                      onChange={(e) => setForm((f) => ({ ...f, [field.key]: e.target.value }))}
                      className="h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25"
                    >
                      <option value="0">Off</option>
                      <option value="1">On</option>
                    </Select>
                  ) : field.type === 'text' ? (
                    <Input
                      type="text"
                      disabled={!canEdit}
                      value={form[field.key] ?? ''}
                      onChange={(e) => setForm((f) => ({ ...f, [field.key]: e.target.value }))}
                    />
                  ) : (
                    <Input
                      type="number"
                      step={field.step}
                      disabled={!canEdit}
                      value={form[field.key] ?? ''}
                      onChange={(e) => setForm((f) => ({ ...f, [field.key]: e.target.value }))}
                    />
                  )}
                </Field>
              ))}
            </div>
            {canEdit && (
              <div className="mt-4 flex justify-end">
                <Button
                  size="sm"
                  disabled={saveM.isPending}
                  onClick={() => {
                    const payload = {};
                    group.keys.forEach(({ key }) => { payload[key] = form[key]; });
                    saveM.mutate(payload);
                  }}
                >
                  {saveM.isPending ? <Spinner className="border-white/40 border-t-white" /> : 'Save'}
                </Button>
              </div>
            )}
          </Card>
        ))
      ))}

      <CompanyApiKeysCard />
    </div>
  );
}
