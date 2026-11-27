import { useEffect, useState } from 'react';
import api, { withCompany } from '../lib/api';
import { apiMessage } from '../lib/formErrors';
import { useToast } from '../lib/toast';
import { Button, Field, Input, Modal } from './ui';

/**
 * Quick stock fix while billing. The product is on the shelf but the system says there is not enough.
 * The stock is set to the quantity really here at once (no approval), head office / admin are told to
 * check it, and billing carries on.
 */
export default function QuickStockFixModal({ open, onClose, product, locationName, locationId, neededQty, companyId, onFixed }) {
  const toast = useToast();
  const [counted, setCounted] = useState('');
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (open && product) {
      setCounted(String(Math.max(Number(neededQty) || 0, Number(product.current_stock) || 0)));
      setNotes('');
      setError('');
    }
  }, [open, product, neededQty]);

  async function save() {
    setError('');
    setSaving(true);
    try {
      const res = await api.post('/stock-verifications/quick', {
        product_id: product.id,
        counted_qty: Number(counted),
        location_id: locationId || undefined,
        notes: notes || undefined,
      }, companyId ? withCompany(companyId) : {});
      toast.success(res.data?.message ?? 'Stock corrected.');
      onFixed?.();
    } catch (e) {
      setError(apiMessage(e, 'Could not correct the stock.'));
    } finally {
      setSaving(false);
    }
  }

  if (!product) return null;
  const valid = counted !== '' && Number(counted) >= 0;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Fix the stock now"
      footer={(
        <>
          <Button variant="outline" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button onClick={save} disabled={saving || !valid}>{saving ? 'Saving…' : 'Update stock'}</Button>
        </>
      )}
    >
      <p className="text-sm text-muted">
        The system shows <b className="text-ink">{Number(product.current_stock) || 0}</b> of <b className="text-ink">{product.name}</b>
        {locationName ? <> at <b className="text-ink">{locationName}</b></> : null}, but you need <b className="text-ink">{Number(neededQty) || 0}</b>.
        If the plants are really here, enter how many there are. The stock changes at once and you can bill. Head office / admin are told to check it.
      </p>
      <div className="mt-4 grid grid-cols-1 gap-4">
        <Field label="How many are really here?" required>
          <Input type="number" min="0" step="0.001" value={counted} onChange={(e) => setCounted(e.target.value)} autoFocus />
        </Field>
        <Field label="Note (optional)">
          <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="e.g. found on the display shelf" />
        </Field>
      </div>
      {error && <p className="mt-3 text-sm text-danger">{error}</p>}
    </Modal>
  );
}
