import { useState } from 'react';
import { Button, Field, Input, Modal, Select } from './ui';
import api, { withCompany } from '../lib/api';
import { useToast } from '../lib/toast';

const EMPTY = { name: '', phone: '', type: 'retail' };

/** Small "add customer" window used while billing. Only the basics; the rest can be filled in on Customers later. */
export default function QuickCustomerModal({ open, onClose, onCreated, companyId }) {
  const toast = useToast();
  const [form, setForm] = useState(EMPTY);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const err = (k) => errors[k]?.[0];
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

  function close() {
    setForm(EMPTY);
    setErrors({});
    onClose();
  }

  async function save() {
    setErrors({});
    setSaving(true);
    try {
      const body = { name: form.name.trim(), type: form.type, status: 'active', phone: form.phone.trim() || null };
      const res = await api.post('/customers', body, companyId ? withCompany(companyId) : {});
      toast.success('Customer added.');
      const created = res.data?.data;
      setForm(EMPTY);
      onCreated?.(created);
    } catch (e) {
      setErrors(e.response?.data?.errors ?? {});
      toast.error(e.response?.data?.message ?? 'Could not add the customer.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={close}
      title="Add customer"
      footer={(
        <>
          <Button variant="outline" onClick={close} disabled={saving}>Cancel</Button>
          <Button onClick={save} disabled={saving || !form.name.trim()}>{saving ? 'Saving…' : 'Add customer'}</Button>
        </>
      )}
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Field label="Name" required error={err('name')}><Input value={form.name} onChange={set('name')} autoFocus /></Field>
        </div>
        <Field label="Phone" error={err('phone')}><Input value={form.phone} onChange={set('phone')} placeholder="Optional" /></Field>
        <Field label="Customer type" error={err('type')}>
          <Select value={form.type} onChange={set('type')}>
            <option value="retail">Retail</option>
            <option value="wholesale">Wholesale</option>
            <option value="dealer">Dealer</option>
          </Select>
        </Field>
      </div>
    </Modal>
  );
}
