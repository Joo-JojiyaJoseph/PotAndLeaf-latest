import { describe, expect, it } from 'vitest';
import { computeSale, discountAmount } from './saleCalc';

describe('POS discount %', () => {
  it('turns the % typed in the Disc. box into the rupee discount the API stores', () => {
    expect(discountAmount({ qty: '2', rate: '120', discount: '10' })).toBe(24);
    expect(discountAmount({ qty: '4', rate: '250', discount: '' })).toBe(0);
    expect(discountAmount({ qty: '1', rate: '99.99', discount: '12.5' })).toBe(12.5);
  });

  it('never gives more than 100% or a negative discount', () => {
    expect(discountAmount({ qty: '1', rate: '500', discount: '150' })).toBe(500);
    expect(discountAmount({ qty: '1', rate: '500', discount: '-5' })).toBe(0);
  });

  it('matches the server bill for 2 × ₹120 at 10% off with 5% GST', () => {
    const line = { qty: '2', rate: '120', discount: '10', gst_rate: '5' };
    const { totals } = computeSale([{ ...line, discount: discountAmount(line) }], false);
    expect(totals).toEqual({ subtotal: 216, tax_total: 10.8, round_off: 0.2, grand_total: 227 });
  });
});
