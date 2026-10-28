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
      { key: 'loyalty_earn_rupees', label: 'Earn: ₹ spent per point', type: 'number', step: '1', help: "Spend this much to earn points. 100 = points for every ₹100 on the bill. Used when no loyalty rule (Loyalty Points page) gives points for that bill." },
      { key: 'loyalty_earn_points', label: 'Earn: points per unit', type: 'number', step: '1', help: "Points given for each step above. 1 = 1 point for every ₹100." },
      { key: 'loyalty_redeem_rupees', label: 'Redeem: ₹ value per point', type: 'number', step: '0.01', help: "What 1 point is worth when the customer uses it. 1 = ₹1 off per point." },
      { key: 'loyalty_redeem_cap_percent', label: 'Redeem cap (% of bill)', type: 'number', step: '1', help: "Most of a bill that can be paid with points. 50 = points can cover at most half the bill." },
    ],
  },
  {
    title: 'Sales & discounts',
    keys: [
      { key: 'discount_ceiling_percent', label: 'Discount ceiling (%)', type: 'number', step: '1', help: "Biggest discount allowed on one bill line, as % of qty × rate. A bigger discount is refused. 0 = no limit. Free (complimentary) bills are not checked." },
      { key: 'sale_cancel_requires_approval', label: 'Require HO approval to cancel confirmed sales', type: 'toggle', help: "On: a confirmed sale cannot be cancelled straight away. Staff send a cancel request and HO approves it. Off: anyone allowed to cancel can do it directly." },
    ],
  },
  {
    title: 'Accounting',
    keys: [
      { key: 'cash_opening_balance', label: 'Cash opening balance (₹)', type: 'number', step: '0.01', help: "Cash in hand on the day you started using the software. The Cash Book starts from this amount." },
      { key: 'bank_opening_balance', label: 'Bank opening balance (₹)', type: 'number', step: '0.01', help: "Bank balance on the day you started. The Bank Book starts from this amount." },
      { key: 'landed_cost_payable_to_supplier', label: 'Freight / loading on purchases is paid to the supplier (off = paid to a transporter)', type: 'toggle', help: "On: freight / loading typed on a purchase bill is added to what you owe that supplier. Off: you pay it to a transporter, so it is not added to the supplier balance. Either way it is added to the product cost." },
    ],
  },
  {
    title: 'Inventory & company',
    keys: [
      { key: 'reorder_alert_default', label: 'Default reorder alert qty', type: 'number', step: '1', help: "A new product with the reorder level left blank gets this number. When stock falls to the reorder level, the product shows as low stock." },
      { key: 'daily_expense', label: 'Daily expense (₹, for approx. profit)', type: 'number', step: '0.01', help: "Rough daily running cost (rent, salary, power). Approx. Profit report takes off this × number of days from the sales profit." },
      { key: 'whatsapp_enabled', label: 'WhatsApp invoice sharing', type: 'toggle', help: "On: bills, order updates and commission messages can go out on WhatsApp. Off: nothing is sent on WhatsApp for this company." },
      { key: 'website_integration', label: 'Allow company website / app API', type: 'toggle', help: "On: this company’s own website or mobile app can show products and take orders using an API key (made below). Off: every key is blocked." },
    ],
  },
  {
    title: 'Reports & EOD',
    keys: [
      { key: 'financial_year_start_month', label: 'Financial year start month (1–12)', type: 'number', step: '1', help: "Month your accounting year starts. 4 = April (India). Used for “this financial year” in the sales reports." },
      { key: 'dead_stock_days', label: 'Dead stock threshold (days)', type: 'number', step: '1', help: "A product that has stock but nothing sold or sent out for this many days is shown as dead in Stock Levels (Fast / slow / dead)." },
      { key: 'eod_management_enabled', label: 'Send HO management EOD summary', type: 'toggle', help: "On: every day at the send time, a summary (sales, cash and bank in, stock value, low stock, top staff) goes to the phones and emails below." },
      { key: 'eod_management_whatsapp_phones', label: 'EOD WhatsApp phones (comma-separated)', type: 'text', help: "WhatsApp numbers that get the daily summary, separated by commas. Example: 9876543210, 9123456789" },
      { key: 'eod_management_email_recipients', label: 'EOD email recipients (comma-separated)', type: 'text', help: "Email addresses that get the daily summary, separated by commas." },
      { key: 'eod_management_send_time', label: 'EOD send time (HH:MM, 24h)', type: 'text', help: "Time to send the summary, 24-hour clock. 20:30 = 8:30 pm." },
    ],
  },
  {
    title: 'Rental automation',
    keys: [
      { key: 'rental_auto_bill', label: 'Auto-generate invoices on billing cycle', type: 'toggle', help: "On: each morning a rent bill is made by itself for every rental whose billing cycle has ended (if that rental has “Generate invoices automatically” ticked). Off: make each rent bill by hand." },
      { key: 'rental_whatsapp_on_bill', label: 'WhatsApp when auto-billing', type: 'toggle', help: "On: when a rent bill is made by itself, the customer gets it on WhatsApp." },
      { key: 'sms_enabled', label: 'SMS rental reminders & overdue alerts', type: 'toggle', help: "On: rental reminders and overdue alerts also go by SMS (an SMS provider must be set up on the server)." },
      { key: 'rental_payment_due_days', label: 'Payment due days after period end', type: 'number', step: '1', help: "Days the customer gets to pay a rent bill after the period ends. 7 = due one week later." },
      { key: 'rental_overdue_alert_days', label: 'Overdue alert grace (days)', type: 'number', step: '1', help: "Extra days to wait after the due / return date before sending an overdue alert. 0 = alert on the due date." },
      { key: 'rental_reminder_lead_days', label: 'Reminder lead time (days before due)', type: 'number', step: '1', help: "Days before the payment due date or return date to send a reminder. 3 = three days before." },
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
                  {field.help && <p className="mt-1.5 text-xs leading-relaxed text-muted">{field.help}</p>}
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
