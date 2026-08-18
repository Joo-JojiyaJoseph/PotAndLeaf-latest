// Mirror of app/Support/Sales/SaleCalculator.php for live POS totals.
const round = (n, d = 2) => {
  const f = 10 ** d;
  return Math.round((Number(n) + Number.EPSILON) * f) / f;
};

export function computeSale(lines, isInterstate) {
  const out = [];
  let subtotal = 0;
  let taxTotal = 0;

  for (const line of lines) {
    const qty = Number(line.qty) || 0;
    const rate = Number(line.rate) || 0;
    const discount = Number(line.discount) || 0;
    const gstRate = Number(line.gst_rate) || 0;

    const taxable = Math.max(0, round(qty * rate - discount));
    const tax = round((taxable * gstRate) / 100);
    let cgst = 0, sgst = 0, igst = 0;
    if (isInterstate) igst = tax;
    else { cgst = round(tax / 2); sgst = round(tax - cgst); }

    const lineTotal = round(taxable + tax);
    subtotal += taxable;
    taxTotal += tax;
    out.push({ taxable_value: taxable, cgst_amount: cgst, sgst_amount: sgst, igst_amount: igst, line_total: lineTotal });
  }

  subtotal = round(subtotal);
  taxTotal = round(taxTotal);
  const grandRaw = subtotal + taxTotal;
  const grand = Math.round(grandRaw);
  const roundOff = round(grand - grandRaw);
  return { lines: out, totals: { subtotal, tax_total: taxTotal, round_off: roundOff, grand_total: grand } };
}

// Suggested unit price from the customer's pricing tier, falling back sensibly.
export function tierPrice(product, customerType) {
  if (!product) return 0;
  const byTier = { wholesale: product.wholesale_price, dealer: product.dealer_price, retail: product.retail_price };
  return Number(byTier[customerType] || product.retail_price || product.mrp || 0);
}
