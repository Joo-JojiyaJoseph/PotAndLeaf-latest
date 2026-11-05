import { formatCurrency } from './format';

/** "8 bills · ₹500 returned" — the total is the bill amount after points discount. */
export function salesSub(sales) {
  const parts = [`${sales.count} bill${sales.count === 1 ? '' : 's'}`];
  if (Number(sales.returns) > 0) parts.push(`${formatCurrency(sales.returns)} returned`);
  return parts.join(' · ');
}

/**
 * The parts that make up "Total incentive", so the cards add up:
 * commission + bonuses − taken back for returns + manager + supervisor.
 */
export function incentiveParts(d) {
  const n = (v) => Math.round((Number(v) || 0) * 100) / 100;
  const parts = [
    ['commission', n(d.sales_commission)],
    ['bonuses', n(d.daily_target_bonus) + n(d.promotion_bonus)],
    ['returns', n(d.returns_reversed)],
    ['manager', n(d.manager_commission)],
    ['supervisor', n(d.supervisor_commission)],
  ];
  const known = parts.reduce((t, [, v]) => t + v, 0);
  const other = n(n(d.total_incentive) - known);
  if (Math.abs(other) >= 0.01) parts.push(['other', other]);
  return parts;
}
