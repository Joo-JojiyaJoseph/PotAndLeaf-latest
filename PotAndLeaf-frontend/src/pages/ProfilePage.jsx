import { useState } from 'react';
import { UserCircleIcon } from '@heroicons/react/24/outline';
import api from '../lib/api';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../lib/toast';
import { Button, Card, Field, Input, Spinner } from '../components/ui';

export default function ProfilePage() {
  const { user, updateUser, isSuperAdmin } = useAuth();
  const toast = useToast();
  const [form, setForm] = useState({ name: user?.name ?? '', email: user?.email ?? '', phone: user?.phone ?? '' });
  const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const err = (k) => errors[k]?.[0];

  async function save() {
    setErrors({}); setSaving(true);
    const payload = { ...form };
    if (pw.password) Object.assign(payload, pw);
    try {
      const res = await api.put('/me', payload);
      updateUser?.(res.data.data.user);
      setPw({ current_password: '', password: '', password_confirmation: '' });
      toast.success('Profile updated.');
    } catch (e) {
      setErrors(e.response?.data?.errors ?? {});
      toast.error(e.response?.data?.message ?? 'Could not update profile.');
    } finally { setSaving(false); }
  }

  const initials = (user?.name ?? '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();

  return (
    <div className="mx-auto max-w-2xl space-y-5 p-4 sm:p-6">
      <div>
        <h1 className="text-lg font-semibold">My profile</h1>
        <p className="text-sm text-muted">Manage your account details and password.</p>
      </div>

      <Card className="flex items-center gap-4 p-5">
        <span className="flex size-16 items-center justify-center rounded-full bg-leaf-soft text-xl font-semibold text-leaf">{initials}</span>
        <div>
          <div className="text-base font-semibold text-ink">{user?.name}</div>
          <div className="text-sm text-muted">{user?.email}</div>
          {isSuperAdmin && <span className="mt-1 inline-block rounded-full bg-leaf-soft px-2 py-0.5 text-[11px] font-medium text-leaf-hover">Super admin</span>}
        </div>
      </Card>

      <Card className="p-5">
        <h2 className="mb-4 text-sm font-semibold">Account details</h2>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Name" required error={err('name')}><Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} /></Field>
          <Field label="Email" required error={err('email')}><Input type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} /></Field>
          <Field label="Phone" error={err('phone')}><Input value={form.phone} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} /></Field>
        </div>
      </Card>

      <Card className="p-5">
        <h2 className="mb-1 text-sm font-semibold">Change password</h2>
        <p className="mb-4 text-xs text-muted">Leave blank to keep your current password.</p>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2"><Field label="Current password" error={err('current_password')}><Input type="password" value={pw.current_password} onChange={(e) => setPw((p) => ({ ...p, current_password: e.target.value }))} autoComplete="current-password" /></Field></div>
          <Field label="New password" error={err('password')}><Input type="password" value={pw.password} onChange={(e) => setPw((p) => ({ ...p, password: e.target.value }))} autoComplete="new-password" /></Field>
          <Field label="Confirm new password"><Input type="password" value={pw.password_confirmation} onChange={(e) => setPw((p) => ({ ...p, password_confirmation: e.target.value }))} autoComplete="new-password" /></Field>
        </div>
      </Card>

      <div className="flex justify-end">
        <Button onClick={save} disabled={saving}>{saving ? <Spinner className="border-white/40 border-t-white" /> : 'Save changes'}</Button>
      </div>
    </div>
  );
}
