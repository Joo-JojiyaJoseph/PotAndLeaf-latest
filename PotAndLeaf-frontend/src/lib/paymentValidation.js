import { formatCurrency } from './format.js';

/** Epsilon for float comparisons (mirrors backend StoreSupplierPaymentRequest). */
const EPS = 1e-6;

/**
 * Validate payment amount against supplier outstanding and optional GRN balance.
 * Backend validation remains authoritative; these checks are an early UX guard.
 *
 * @returns {{ valid: boolean, errors: Record<string, string> }}
 */
export function validatePaymentAmount({ amount, supplierOutstanding, grnBalance = null, isAdvance = false }) {
  const num = Number(amount);

  if (amount === '' || amount == null || Number.isNaN(num)) {
    return { valid: false, errors: { amount: 'Amount is required.' } };
  }

  if (num <= 0) {
    return { valid: false, errors: { amount: 'Amount must be greater than 0.' } };
  }

  if (isAdvance) {
    return { valid: true, errors: {} };
  }

  const outstanding = Number(supplierOutstanding ?? 0);
  if (num > outstanding + EPS) {
    return {
      valid: false,
      errors: {
        amount: `Amount cannot exceed the supplier outstanding balance of ${formatCurrency(outstanding)}.`,
      },
    };
  }

  if (grnBalance != null && grnBalance !== '') {
    const balance = Number(grnBalance);
    if (!Number.isNaN(balance) && num > balance + EPS) {
      return {
        valid: false,
        errors: {
          amount: `Amount cannot exceed the selected GRN remaining balance of ${formatCurrency(balance)}.`,
        },
      };
    }
  }

  return { valid: true, errors: {} };
}

/**
 * Client-side checks before submitting a supplier payment.
 *
 * @returns {{ valid: boolean, errors: Record<string, string> }}
 */
export function validatePaymentForm({ supplierId, amount, supplierOutstanding, purchaseId, payables = [], isAdvance = false }) {
  if (!supplierId) {
    return { valid: false, errors: { supplier_id: 'Please select a supplier.' } };
  }

  let grnBalance = null;
  if (purchaseId && !isAdvance) {
    const grn = payables.find((p) => String(p.id) === String(purchaseId));
    if (grn) grnBalance = grn.balance;
  }

  return validatePaymentAmount({ amount, supplierOutstanding, grnBalance, isAdvance });
}

/** Normalise validation errors to Laravel-style field arrays for Field components. */
export function paymentErrorsToFieldState(errors) {
  const next = {};
  for (const [key, message] of Object.entries(errors)) {
    next[key] = [message];
  }
  return next;
}

/**
 * Run client-side payment validation and invoke mutate only when valid.
 * Returns true when the submit proceeds to the mutation.
 */
export function executePaymentSubmit({
  supplierId,
  amount,
  supplierOutstanding,
  purchaseId,
  payables = [],
  isAdvance = false,
  mutate,
  setErrors,
}) {
  setErrors({});
  const result = validatePaymentForm({
    supplierId,
    amount,
    supplierOutstanding,
    purchaseId,
    payables,
    isAdvance,
  });
  if (!result.valid) {
    setErrors(paymentErrorsToFieldState(result.errors));
    return false;
  }
  mutate();
  return true;
}
