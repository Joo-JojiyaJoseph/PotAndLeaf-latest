import { describe, it, expect } from 'vitest';
import { computeSale, discountAmount, applyFinalDiscount } from './saleCalc';

describe('final-amount discount', () => {
  it('comes off the total after GST and round-off', () => {
    const base = computeSale([{ qty: '2', rate: '100', discount: 0, gst_rate: 18 }], false).totals; // 236
    expect(base.grand_total).toBe(236);
    const t = applyFinalDiscount(base, 36);
    expect(t.grand_total).toBe(200);
    expect(t.tax_total).toBe(base.tax_total); // GST is not reduced
    expect(t.final_discount).toBe(36);
  });
  it('is capped at the bill total and ignores blanks', () => {
    const base = computeSale([{ qty: '1', rate: '10', discount: 0, gst_rate: 0 }], false).totals;
    expect(applyFinalDiscount(base, 25).grand_total).toBe(0);
    expect(applyFinalDiscount(base, '').grand_total).toBe(10);
  });
});

describe('line discount', () => {
  it('turns a percentage into rupees', () => {
    expect(discountAmount({ qty: '2', rate: '50', discount: '10' })).toBe(10);
  });
});
