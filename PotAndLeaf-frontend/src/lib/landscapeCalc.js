const n = (v) => Number(v) || 0;
const r2 = (v) => Math.round((v + Number.EPSILON) * 100) / 100;

/**
 * Landscape estimate sums (same as the server):
 * work charge (area × rate) + plant/material lines + planting cost + service charge + other,
 * then GST on that, then the discount comes off the total (after GST).
 */
export function estimateFigures(d = {}) {
  const lines = (d.items ?? []).reduce((t, i) => t + r2(n(i.qty) * n(i.rate)), 0);
  const plants = (d.items ?? []).filter((i) => i.kind === 'plant');
  const work = r2(n(d.area_sqft) * n(d.rate_per_sqft));
  const subtotal = r2(work + lines + n(d.planting_cost) + n(d.service_charge) + n(d.other_charge));
  const gst = r2((subtotal * n(d.gst_percent)) / 100);
  const before = r2(subtotal + gst);
  const discount = r2(Math.min(n(d.discount), before));
  return {
    work, lines, subtotal, gst, before, discount, total: r2(before - discount),
    plantCount: plants.reduce((t, i) => t + n(i.qty), 0),
    plantCost: r2(plants.reduce((t, i) => t + n(i.qty) * n(i.rate), 0)),
    overDiscount: n(d.discount) > before + 0.005,
  };
}
