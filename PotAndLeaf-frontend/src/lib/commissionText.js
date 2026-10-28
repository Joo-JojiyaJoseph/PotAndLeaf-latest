import { formatCurrency } from './format';

/* Shared wording for commission screens (staff rates and earnings). */

export const TYPE_LABEL = {
  salesman_tier: 'Sales commission',
  daily_target: 'Daily target bonus',
  promotion: 'Product offer bonus',
  manager: 'Manager share',
  reversal: 'Taken back (return / cancel)',
};
export const STATUS_LABEL = { accrued: 'earned', reversed: 'taken back', paid: 'paid' };

export const n = (v) => Number(v) || 0;
// Whole rupees without “.00” — easier to read.
export const rupees = (v) => formatCurrency(v).replace(/\.00$/, '');

/** What a staff rate pays, in simple sentences (follows the existing calculation). */
export function describeRule(r) {
  const made = n(r.production_per_unit) > 0 ? [`Gets ${rupees(r.production_per_unit)} for every piece they make, when the production is completed`] : [];
  if (r.is_supervisor) {
    const sold = r.rate_type === 'per_unit'
      ? (n(r.per_unit_amount) > 0 ? [`Gets ${rupees(r.per_unit_amount)} for every piece they made that is sold or sent to another company`] : [])
      : (n(r.base_percent) > 0 ? [`Gets ${r.base_percent}% of the price of every piece they made that is sold or sent to another company`] : []);
    return [...made, ...sold];
  }
  const parts = [...made];
  if (r.tiers?.length) {
    const flat = r.tier_mode === 'flat';
    const per = r.tier_period === 'daily' ? 'day' : 'month';
    r.tiers.forEach((t) => parts.push(flat
      ? `Gets ${t.percent}% of all sales in a ${per} when the ${per}’s sales are ${t.max_amount != null ? `between ${rupees(t.min_amount)} and ${rupees(t.max_amount)}` : `above ${rupees(t.min_amount)}`}`
      : `Gets ${t.percent}% on sales ${t.max_amount != null ? `from ${rupees(t.min_amount)} to ${rupees(t.max_amount)}` : `above ${rupees(t.min_amount)}`} in a ${per}`));
  } else if (n(r.base_percent) > 0) {
    parts.push(`Gets ${r.base_percent}% of what they sell — ${rupees(n(r.base_percent))} for every ₹100`);
  }
  // The monthly target bonus only counts when no commission is worked out on the bills.
  if (!r.tiers?.length && !n(r.base_percent) && n(r.monthly_target) > 0 && n(r.target_bonus) > 0) {
    parts.push(`Gets ${rupees(r.target_bonus)} if the month’s sales reach ${rupees(r.monthly_target)}`);
  }
  (r.daily_target_tiers ?? []).forEach((t) => parts.push(`Gets ${rupees(t.bonus_amount)} extra on any day they sell ${rupees(t.min_amount)} or more`));
  if (r.max_commission) parts.push(`Commission stops at ${rupees(r.max_commission)} in a month`);
  if (!parts.length) parts.push('Nothing set yet — click Edit');
  return parts;
}
