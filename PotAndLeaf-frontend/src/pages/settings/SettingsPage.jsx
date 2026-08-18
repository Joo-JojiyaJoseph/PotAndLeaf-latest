import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Button, Card, Field, Input, Spinner } from '../../components/ui';
import { useToast } from '../../lib/toast';

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
    ],
  },
  {
    title: 'Inventory & company',
    keys: [
      { key: 'reorder_alert_default', label: 'Default reorder alert qty', type: 'number', step: '1' },
      { key: 'daily_expense', label: 'Daily expense (₹, for approx. profit)', type: 'number', step: '0.01' },
      { key: 'whatsapp_enabled', label: 'WhatsApp invoice sharing', type: 'toggle' },
      { key: 'website_integration', label: 'Website integration', type: 'toggle' },
    ],
  },
];

export default function SettingsPage() {
  const { activeCompany, can, isSuperAdmin } = useAuth();
  const toast = useToast();
  const queryClient = useQueryClient();
  const [form, setForm] = useState({});
  const [darkMode, setDarkMode] = useState(() => localStorage.getItem('pl_theme') === 'dark');
  const canView = isSuperAdmin || can('settings.view') || can('*');
  const canEdit = isSuperAdmin || can('settings.update') || can('*');

  useEffect(() => {
    document.documentElement.classList.toggle('dark', darkMode);
    localStorage.setItem('pl_theme', darkMode ? 'dark' : 'light');
  }, [darkMode]);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['settings', activeCompany?.id],
    queryFn: () => api.get('/settings').then((r) => r.data.data),
    enabled: Boolean(activeCompany) && canView,
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
        <h1 className="text-lg font-semibold">Settings</h1>
        <p className="text-sm text-muted">Additional settings for {activeCompany?.name}.</p>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
      ) : isError ? (
        <Card className="px-4 py-12 text-center text-sm text-muted">Couldn't load settings.</Card>
      ) : (
        <>
          <Card className="p-5">
            <h2 className="text-sm font-semibold">Appearance</h2>
            <div className="mt-4 max-w-xs">
              <Field label="Dark mode">
                <select
                  value={darkMode ? '1' : '0'}
                  onChange={(e) => setDarkMode(e.target.value === '1')}
                  className="h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25"
                >
                  <option value="0">Light</option>
                  <option value="1">Dark</option>
                </select>
              </Field>
            </div>
          </Card>
          {GROUPS.map((group) => (
          <Card key={group.title} className="p-5">
            <h2 className="text-sm font-semibold">{group.title}</h2>
            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
              {group.keys.map((field) => (
                <Field key={field.key} label={field.label}>
                  {field.type === 'toggle' ? (
                    <select
                      value={form[field.key] === '1' || form[field.key] === true ? '1' : '0'}
                      disabled={!canEdit}
                      onChange={(e) => setForm((f) => ({ ...f, [field.key]: e.target.value }))}
                      className="h-10 w-full rounded-xl border border-line bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25"
                    >
                      <option value="0">Off</option>
                      <option value="1">On</option>
                    </select>
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
        ))}
        </>
      )}
    </div>
  );
}
