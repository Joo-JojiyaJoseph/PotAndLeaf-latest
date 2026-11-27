import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import api, { withCompany } from '../../lib/api';
import { useToast } from '../../lib/toast';
import { apiMessage } from '../../lib/formErrors';
import { Button, Field, Input, Modal, Select } from '../../components/ui';

export const blankLocation = (type) => ({ name: '', code: '', type, is_default: false, is_active: true, is_landscape: false });
export const typeLabel = (t) => (t === 'godown' ? 'Godown' : 'Branch');
const suggestCode = (name, type, existing) => {
  const base = (name.replace(/[^A-Za-z0-9 ]/g, '').trim().split(/\s+/).map((w) => w[0]).join('').slice(0, 3) || (type === 'godown' ? 'GDN' : 'BR')).toUpperCase();
  let n = 1;
  while (existing.includes(`${base}${n}`)) n += 1;
  return `${base}${n}`;
};

/** Add / edit one branch or godown of a company (used on the company card and the company page). */
export default function LocationFormModal({ open, onClose, companyId, initial, editingId = null, existingCodes = [] }) {
  const toast = useToast();
  const queryClient = useQueryClient();
  const [form, setForm] = useState(initial);
  const [errors, setErrors] = useState({});
  useEffect(() => { if (open) { setForm(initial); setErrors({}); } }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

  const saveM = useMutation({
    mutationFn: (f) => {
      const body = { name: f.name, code: f.code?.trim() || suggestCode(f.name, f.type, existingCodes), type: f.type, is_default: Boolean(f.is_default), is_active: Boolean(f.is_active), is_landscape: f.type === 'godown' ? false : Boolean(f.is_landscape) };
      return editingId ? api.put(`/locations/${editingId}`, body, withCompany(companyId)) : api.post('/locations', body, withCompany(companyId));
    },
    onSuccess: (res) => {
      queryClient.invalidateQueries({ queryKey: ['locations'] });
      queryClient.invalidateQueries({ queryKey: ['companies'] });
      queryClient.invalidateQueries({ queryKey: ['company'] });
      toast.success(res?.data?.message || 'Saved.');
      onClose();
    },
    onError: (e) => { setErrors(e.response?.data?.errors ?? {}); toast.error(apiMessage(e, 'Could not save.')); },
  });
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const err = (k) => errors[k]?.[0];

  return (
    <Modal
      open={open && Boolean(form)}
      onClose={onClose}
      title={editingId ? `Edit ${form?.name || typeLabel(form?.type)}` : `Add ${form?.type === 'godown' ? 'godown' : 'branch'}`}
      footer={<><Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" disabled={saveM.isPending || !form?.name?.trim()} onClick={() => saveM.mutate(form)}>Save</Button></>}
    >
      {form && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Name" required error={err('name')}><Input autoFocus value={form.name} onChange={set('name')} placeholder={form.type === 'godown' ? 'e.g. Main godown' : 'e.g. Palakkad shop'} /></Field>
          <Field label="Short code" error={err('code')}><Input value={form.code} onChange={set('code')} placeholder="Auto if left empty" /></Field>
          <Field label="Type"><Select value={form.type} onChange={set('type')}><option value="shop">Branch (sells)</option><option value="godown">Godown (stores)</option></Select></Field>
          <Field label="Status"><Select value={form.is_active ? '1' : '0'} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.value === '1' }))}><option value="1">Active</option><option value="0">Inactive</option></Select></Field>
          {form.type !== 'godown' && (
            <Field label="Landscape division?" error={err('is_landscape')} className="sm:col-span-2">
              <Select value={form.is_landscape ? '1' : '0'} onChange={(e) => setForm((f) => ({ ...f, is_landscape: e.target.value === '1' }))}>
                <option value="0">No — an ordinary branch</option>
                <option value="1">Yes — this branch does landscaping work</option>
              </Select>
              <span className="mt-1 block text-xs text-muted">A landscape division gets <b>Landscape Projects</b>: estimates, work orders, daily work, reports and commission.</span>
            </Field>
          )}
          <label className="flex items-start gap-2 text-sm sm:col-span-2">
            <input type="checkbox" checked={Boolean(form.is_default)} disabled={Boolean(editingId && form.is_default)} onChange={(e) => setForm((f) => ({ ...f, is_default: e.target.checked }))} className="mt-0.5 size-4 shrink-0 rounded border-line text-leaf" />
            <span>Make this the company&apos;s main location{editingId && form.is_default && <span className="text-xs text-muted"> (already the main one)</span>}</span>
          </label>
        </div>
      )}
    </Modal>
  );
}
