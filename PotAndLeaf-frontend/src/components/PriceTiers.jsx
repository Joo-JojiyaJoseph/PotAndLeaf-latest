import { useEffect, useState } from 'react';
import { Field, Input } from './ui';
import { PRICE_TIERS, priceFromMargin, marginFromPrice } from '../lib/pricing';

/**
 * Cost + MRP / Retail / Wholesale / Dealer prices, each with its own margin %.
 * Typing a margin fills the price (rounded to a whole rupee); typing a price shows the margin it implies.
 * `value` holds cost_price, mrp, retail_price, wholesale_price, dealer_price; `onChange(patch)` receives changed keys.
 * Pass `costField` / `costLabel` to drive a different cost source (e.g. landed cost on a purchase line).
 */
export default function PriceTiers({ value, onChange, costField = 'cost_price', costLabel = 'Cost price', costError, costRequired = true, costReadOnly = false, tiers = PRICE_TIERS }) {
  const [typed, setTyped] = useState({});
  const cost = value[costField];

  // When the cost moves (e.g. landed cost changes on a purchase), prices set from a margin follow it.
  useEffect(() => {
    const patch = {};
    tiers.forEach((t) => {
      if (typed[t.key] !== undefined) {
        const p = priceFromMargin(cost, typed[t.key]);
        if (p !== null && Number(value[t.field]) !== p) patch[t.field] = p;
      }
    });
    if (Object.keys(patch).length) onChange(patch);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cost]);

  const setCost = (v) => {
    const patch = { [costField]: v };
    tiers.forEach((t) => {
      if (typed[t.key] !== undefined) {
        const p = priceFromMargin(v, typed[t.key]);
        if (p !== null) patch[t.field] = p;
      }
    });
    onChange(patch);
  };
  const setMargin = (t, m) => {
    setTyped((s) => ({ ...s, [t.key]: m }));
    const p = priceFromMargin(cost, m);
    if (p !== null) onChange({ [t.field]: p });
  };
  const setPrice = (t, p) => {
    setTyped((s) => { const n = { ...s }; delete n[t.key]; return n; });
    onChange({ [t.field]: p });
  };

  return (
    <div>
      <div className="mb-3 grid grid-cols-2 gap-4 sm:grid-cols-3">
        <Field label={costLabel} required={costRequired} error={costError}>
          <Input type="number" step="0.01" value={cost ?? ''} onChange={(e) => setCost(e.target.value)} readOnly={costReadOnly} disabled={costReadOnly} />
        </Field>
      </div>
      <div className="overflow-x-auto rounded-xl border border-line">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-surface-2 text-left text-xs text-muted">
              <th className="px-3 py-2 font-medium">Price</th>
              <th className="px-3 py-2 font-medium">Margin on cost %</th>
              <th className="px-3 py-2 font-medium">Amount (rounded)</th>
            </tr>
          </thead>
          <tbody>
            {tiers.map((t) => {
              const price = value[t.field] ?? '';
              const margin = typed[t.key] !== undefined ? typed[t.key] : marginFromPrice(cost, price);
              return (
                <tr key={t.key} className="border-t border-line">
                  <td className="px-3 py-2 font-medium">{t.label}</td>
                  <td className="px-3 py-1.5">
                    <Input type="number" step="0.01" value={margin} onChange={(e) => setMargin(t, e.target.value)} placeholder="%" />
                  </td>
                  <td className="px-3 py-1.5">
                    <Input type="number" step="1" value={price} onChange={(e) => setPrice(t, e.target.value)} />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <p className="mt-1.5 text-xs text-muted">Enter a margin % and the price fills in from the cost, rounded off to a whole rupee. You can still type a price directly.</p>
    </div>
  );
}
