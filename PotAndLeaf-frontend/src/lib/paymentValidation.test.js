import { describe, expect, it } from 'vitest';
import { validatePaymentAmount, validatePaymentForm } from './paymentValidation.js';

describe('validatePaymentAmount', () => {
  it('accepts a valid payment below outstanding', () => {
    const result = validatePaymentAmount({ amount: '500', supplierOutstanding: 1000 });
    expect(result.valid).toBe(true);
    expect(result.errors).toEqual({});
  });

  it('accepts a payment equal to supplier outstanding', () => {
    const result = validatePaymentAmount({ amount: 1000, supplierOutstanding: 1000 });
    expect(result.valid).toBe(true);
  });

  it('rejects a payment above supplier outstanding', () => {
    const result = validatePaymentAmount({ amount: 1500, supplierOutstanding: 1000 });
    expect(result.valid).toBe(false);
    expect(result.errors.amount).toBe('Payment amount cannot exceed supplier outstanding (1000).');
  });

  it('rejects a payment above the selected GRN balance', () => {
    const result = validatePaymentAmount({
      amount: 800,
      supplierOutstanding: 2000,
      grnBalance: 500,
    });
    expect(result.valid).toBe(false);
    expect(result.errors.amount).toBe('Payment amount cannot exceed the remaining GRN balance (500).');
  });

  it('rejects zero amount', () => {
    const result = validatePaymentAmount({ amount: 0, supplierOutstanding: 1000 });
    expect(result.valid).toBe(false);
    expect(result.errors.amount).toBe('Amount must be greater than zero.');
  });

  it('rejects negative amount', () => {
    const result = validatePaymentAmount({ amount: -100, supplierOutstanding: 1000 });
    expect(result.valid).toBe(false);
    expect(result.errors.amount).toBe('Amount must be greater than zero.');
  });
});

describe('validatePaymentForm', () => {
  const payables = [
    { id: 'grn-1', purchase_no: 'GRN-001', balance: 500 },
    { id: 'grn-2', purchase_no: 'GRN-002', balance: 1200 },
  ];

  it('accepts a valid payment with GRN allocation', () => {
    const result = validatePaymentForm({
      supplierId: 'sup-1',
      amount: '400',
      supplierOutstanding: 2000,
      purchaseId: 'grn-1',
      payables,
    });
    expect(result.valid).toBe(true);
  });

  it('rejects payment above GRN balance when GRN is selected via payables', () => {
    const result = validatePaymentForm({
      supplierId: 'sup-1',
      amount: 600,
      supplierOutstanding: 2000,
      purchaseId: 'grn-1',
      payables,
    });
    expect(result.valid).toBe(false);
    expect(result.errors.amount).toBe('Payment amount cannot exceed the remaining GRN balance (500).');
  });

  it('requires a supplier', () => {
    const result = validatePaymentForm({
      supplierId: '',
      amount: '100',
      supplierOutstanding: 1000,
      payables,
    });
    expect(result.valid).toBe(false);
    expect(result.errors.supplier_id).toBe('Please select a supplier.');
  });
});
