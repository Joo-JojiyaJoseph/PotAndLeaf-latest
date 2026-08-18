import { useState } from 'react';

/** Instant on/off switch. `onToggle(next)` should return a promise; the switch
 *  shows the pending state and reverts on failure. */
export default function StatusToggle({ active, onToggle, disabled, labels = ['Active', 'Inactive'] }) {
  const [pending, setPending] = useState(false);
  const [optimistic, setOptimistic] = useState(null);
  const on = optimistic ?? active;

  async function flip() {
    if (disabled || pending) return;
    const next = !on;
    setOptimistic(next); setPending(true);
    try { await onToggle(next); }
    catch { setOptimistic(!next); setTimeout(() => setOptimistic(null), 0); }
    finally { setPending(false); setOptimistic((v) => v); }
  }

  return (
    <button type="button" onClick={flip} disabled={disabled || pending} aria-pressed={on} title={on ? labels[0] : labels[1]}
      className={'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors ' + (on ? 'bg-leaf' : 'bg-line-strong') + (pending ? ' opacity-70' : '')}>
      <span className={'inline-block size-5 transform rounded-full bg-white shadow transition-transform ' + (on ? 'translate-x-5' : 'translate-x-0.5')} />
    </button>
  );
}
