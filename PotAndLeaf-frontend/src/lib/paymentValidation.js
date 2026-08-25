/** Epsilon for float comparisons (mirrors backend StoreSupplierPaymentRequest). */
const EPS = 1e-6;

/**
 * Validate payment amount against supplier outstanding and optional GRN balance.
 * Messages mirror StoreSupplierPaymentRequest for consistent UX.
 *
 * @returns {{ valid: boolean, errors: Record<string, string> }}
 */
export function validatePaymentAmount({ amount, supplierOutstanding, grnBalance = null }) {
  const num = Number(amount);

  if (amount === '' || amount == null || Number.isNaN(num)) {
    return { valid: false, errors: { amount: 'Amount is required.' } };
  }

  if (num <= 0) {
    return { valid: false, errors: { amount: 'Amount must be greater than zero.' } };
  }

  const outstanding = Number(supplierOutstanding ?? 0);
  if (num > outstanding + EPS) {
    return {
      valid: false,
      errors: { amount: `Payment amount cannot exceed supplier outstanding (${outstanding}).` },
    };
  }

  if (grnBalance != null && grnBalance !== '') {
    const balance = Number(grnBalance);
    if (!Number.isNaN(balance) && num > balance + EPS) {
      return {
        valid: false,
        errors: { amount: `Payment amount cannot exceed the remaining GRN balance (${balance}).` },
      };
    }
  }

  return { valid: true, errors: {} };
}

/**
 * Client-side checks before submitting a supplier payment.
 * Backend validation remains authoritative.
 *
 * @returns {{ valid: boolean, errors: Record<string, string> }}
 */
export function validatePaymentForm({ supplierId, amount, supplierOutstanding, purchaseId, payables = [] }) {
  if (!supplierId) {
    return { valid: false, errors: { supplier_id: 'Please select a supplier.' } };
  }

  let grnBalance = null;
  if (purchaseId) {
    const grn = payables.find((p) => String(p.id) === String(purchaseId));
    if (grn) grnBalance = grn.balance;
  }

  return validatePaymentAmount({ amount, supplierOutstanding, grnBalance });
}
