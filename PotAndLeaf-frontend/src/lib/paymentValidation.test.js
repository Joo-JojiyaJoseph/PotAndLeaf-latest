import { describe, expect, it, vi } from 'vitest';
import { formatCurrency } from './format.js';
import {
  executePaymentSubmit,
  validatePaymentAmount,
  validatePaymentForm,
} from './paymentValidation.js';

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
    expect(result.errors.amount).toBe(
      `Amount cannot exceed the supplier outstanding balance of ${formatCurrency(1000)}.`,
    );
  });

  it('rejects a payment above the selected GRN balance', () => {
    const result = validatePaymentAmount({
      amount: 800,
      supplierOutstanding: 2000,
      grnBalance: 500,
    });
    expect(result.valid).toBe(false);
    expect(result.errors.amount).toBe(
      `Amount cannot exceed the selected GRN remaining balance of ${formatCurrency(500)}.`,
    );
  });

  it('rejects zero amount', () => {
    const result = validatePaymentAmount({ amount: 0, supplierOutstanding: 1000 });
    expect(result.valid).toBe(false);
    expect(result.errors.amount).toBe('Amount must be greater than 0.');
  });

  it('rejects negative amount', () => {
    const result = validatePaymentAmount({ amount: -100, supplierOutstanding: 1000 });
    expect(result.valid).toBe(false);
    expect(result.errors.amount).toBe('Amount must be greater than 0.');
  });
});

describe('validatePaymentForm', () => {
  const payables = [
    { id: 'grn-1', purchase_no: 'GRN-001', balance: 1000 },
    { id: 'grn-2', purchase_no: 'GRN-002', balance: 500 },
  ];

  it('accepts a valid payment with GRN allocation', () => {
    const result = validatePaymentForm({
      supplierId: 'sup-1',
      amount: '400',
      supplierOutstanding: 2000,
      purchaseId: 'grn-2',
      payables,
    });
    expect(result.valid).toBe(true);
  });

  it('rejects payment above GRN balance when GRN is selected via payables', () => {
    const result = validatePaymentForm({
      supplierId: 'sup-1',
      amount: 600,
      supplierOutstanding: 2000,
      purchaseId: 'grn-2',
      payables,
    });
    expect(result.valid).toBe(false);
    expect(result.errors.amount).toBe(
      `Amount cannot exceed the selected GRN remaining balance of ${formatCurrency(500)}.`,
    );
  });

  it('rejects amount above GRN balance even when below supplier outstanding', () => {
    const result = validatePaymentForm({
      supplierId: 'sup-1',
      amount: 1500,
      supplierOutstanding: 2000,
      purchaseId: 'grn-1',
      payables,
    });
    expect(result.valid).toBe(false);
    expect(result.errors.amount).toBe(
      `Amount cannot exceed the selected GRN remaining balance of ${formatCurrency(1000)}.`,
    );
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

describe('executePaymentSubmit', () => {
  const payables = [{ id: 'grn-1', balance: 1000 }];

  it('calls mutate for a valid payment below outstanding', () => {
    const mutate = vi.fn();
    const setErrors = vi.fn();

    const submitted = executePaymentSubmit({
      supplierId: 'sup-1',
      amount: '500',
      supplierOutstanding: 1000,
      purchaseId: null,
      payables,
      mutate,
      setErrors,
    });

    expect(submitted).toBe(true);
    expect(mutate).toHaveBeenCalledOnce();
    expect(setErrors).toHaveBeenCalledWith({});
  });

  it('calls mutate for amount exactly equal to supplier outstanding', () => {
    const mutate = vi.fn();
    const setErrors = vi.fn();

    const submitted = executePaymentSubmit({
      supplierId: 'sup-1',
      amount: 1000,
      supplierOutstanding: 1000,
      purchaseId: null,
      payables,
      mutate,
      setErrors,
    });

    expect(submitted).toBe(true);
    expect(mutate).toHaveBeenCalledOnce();
  });

  it('does not call mutate when amount exceeds supplier outstanding', () => {
    const mutate = vi.fn();
    const setErrors = vi.fn();

    const submitted = executePaymentSubmit({
      supplierId: 'sup-1',
      amount: 1500,
      supplierOutstanding: 1000,
      purchaseId: null,
      payables,
      mutate,
      setErrors,
    });

    expect(submitted).toBe(false);
    expect(mutate).not.toHaveBeenCalled();
    expect(setErrors).toHaveBeenCalledWith({
      amount: [`Amount cannot exceed the supplier outstanding balance of ${formatCurrency(1000)}.`],
    });
  });

  it('does not call mutate when amount exceeds selected GRN balance', () => {
    const mutate = vi.fn();
    const setErrors = vi.fn();

    const submitted = executePaymentSubmit({
      supplierId: 'sup-1',
      amount: 1500,
      supplierOutstanding: 2000,
      purchaseId: 'grn-1',
      payables,
      mutate,
      setErrors,
    });

    expect(submitted).toBe(false);
    expect(mutate).not.toHaveBeenCalled();
    expect(setErrors).toHaveBeenCalledWith({
      amount: [`Amount cannot exceed the selected GRN remaining balance of ${formatCurrency(1000)}.`],
    });
  });

  it('does not call mutate for zero amount', () => {
    const mutate = vi.fn();
    const setErrors = vi.fn();

    const submitted = executePaymentSubmit({
      supplierId: 'sup-1',
      amount: 0,
      supplierOutstanding: 1000,
      purchaseId: null,
      payables,
      mutate,
      setErrors,
    });

    expect(submitted).toBe(false);
    expect(mutate).not.toHaveBeenCalled();
    expect(setErrors).toHaveBeenCalledWith({
      amount: ['Amount must be greater than 0.'],
    });
  });

  it('does not call mutate for negative amount', () => {
    const mutate = vi.fn();
    const setErrors = vi.fn();

    const submitted = executePaymentSubmit({
      supplierId: 'sup-1',
      amount: -50,
      supplierOutstanding: 1000,
      purchaseId: null,
      payables,
      mutate,
      setErrors,
    });

    expect(submitted).toBe(false);
    expect(mutate).not.toHaveBeenCalled();
    expect(setErrors).toHaveBeenCalledWith({
      amount: ['Amount must be greater than 0.'],
    });
  });
});
