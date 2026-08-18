// Mirror of app/Support/Purchasing/PurchaseCalculator.php — keep the two in
// sync so the on-screen preview matches exactly what the server persists.
const round = (n, d = 2) => {
  const f = 10 ** d;
  return Math.round((Number(n) + Number.EPSILON) * f) / f;
};

export function computePurchase(items, isInterstate, landedCostTotal = 0) {
  let subtotal = 0;
  let discountTotal = 0;

  const lines = items.map((it) => {
    const qty = Math.max(0, Number(it.qty) || 0);
    const rate = Math.max(0, Number(it.rate) || 0);
    const discount = Math.max(0, Number(it.discount) || 0);
    const taxable = round(Math.max(0, qty * rate - discount));
    subtotal += taxable;
    discountTotal += discount;
    return { qty, rate, discount, taxable_value: taxable, gst_rate: Math.max(0, Number(it.gst_rate) || 0) };
  });

  subtotal = round(subtotal);
  let taxTotal = 0;
  let allocated = 0;
  const n = lines.length;

  lines.forEach((line, i) => {
    const gst = round((line.taxable_value * line.gst_rate) / 100);
    if (isInterstate) {
      line.igst_amount = gst;
      line.cgst_amount = 0;
      line.sgst_amount = 0;
    } else {
      const cgst = round(gst / 2);
      line.cgst_amount = cgst;
      line.sgst_amount = round(gst - cgst);
      line.igst_amount = 0;
    }
    line.line_total = round(line.taxable_value + gst);
    taxTotal += gst;

    let alloc = 0;
    if (landedCostTotal > 0 && subtotal > 0) {
      if (i === n - 1) alloc = round(landedCostTotal - allocated);
      else {
        alloc = round((landedCostTotal * line.taxable_value) / subtotal);
        allocated += alloc;
      }
    }
    line.landed_alloc = alloc;
    line.landed_unit_cost = line.qty > 0 ? round((line.taxable_value + alloc) / line.qty, 4) : 0;
  });

  return {
    items: lines,
    totals: {
      subtotal,
      discount_total: round(discountTotal),
      tax_total: round(taxTotal),
      landed_cost_total: round(landedCostTotal),
      grand_total: round(subtotal + round(taxTotal)),
    },
  };
}
