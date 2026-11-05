import { describe, expect, it } from 'vitest';
import { incentiveParts, salesSub } from './dashboardSums';

describe('dashboard sums', () => {
  it('incentive parts add up to the total', () => {
    const d = { sales_commission: 20, daily_target_bonus: 50, promotion_bonus: 10, returns_reversed: -5, manager_commission: 15, supervisor_commission: 0, total_incentive: 90 };
    const parts = incentiveParts(d);
    expect(parts.reduce((t, [, v]) => t + v, 0)).toBe(90);
    expect(parts.find(([k]) => k === 'other')).toBeUndefined();
  });
  it('anything unexplained shows as other', () => {
    const parts = incentiveParts({ sales_commission: 10, total_incentive: 12 });
    expect(parts.find(([k]) => k === 'other')[1]).toBe(2);
  });
  it('sales sub line mentions returns only when there are any', () => {
    expect(salesSub({ count: 1, returns: 0 })).toBe('1 bill');
    expect(salesSub({ count: 3, returns: 500 })).toMatch(/^3 bills · .*500.* returned$/);
  });
});
