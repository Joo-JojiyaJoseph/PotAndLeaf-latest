// Shared price helpers: a selling price is cost + margin %, rounded off to a whole rupee.
export const PRICE_TIERS = [
  { key: 'mrp', label: 'MRP', field: 'mrp' },
  { key: 'retail', label: 'Retail', field: 'retail_price' },
  { key: 'wholesale', label: 'Wholesale', field: 'wholesale_price' },
  { key: 'dealer', label: 'Dealer', field: 'dealer_price' },
];

export function priceFromMargin(cost, pct) {
  const c = Number(cost);
  const m = Number(pct);
  if (!Number.isFinite(c) || c <= 0 || pct === '' || pct == null || !Number.isFinite(m)) return null;
  return Math.round(c * (1 + m / 100));
}

export function marginFromPrice(cost, price) {
  const c = Number(cost);
  const p = Number(price);
  if (!Number.isFinite(c) || c <= 0 || price === '' || price == null || !Number.isFinite(p) || p <= 0) return '';
  return String(Math.round(((p - c) / c) * 10000) / 100);
}
