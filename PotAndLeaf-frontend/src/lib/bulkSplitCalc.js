// Mirror of app/Support/Purchasing/BulkSplitCalculator.php for live preview.
const round = (n, d = 2) => {
  const f = 10 ** d;
  return Math.round((Number(n) + Number.EPSILON) * f) / f;
};

export function allocateSplit(totalCost, outputs) {
  const rows = outputs.map((o) => {
    const qty = Math.max(0, Number(o.qty) || 0);
    const weight = Math.max(0, Number(o.weight) || 1) || 1;
    return { qty, weight };
  });
  const totalWeight = rows.reduce((s, r) => s + r.qty * r.weight, 0);
  let allocated = 0;
  const n = rows.length;
  rows.forEach((r, i) => {
    let alloc = 0;
    if (totalWeight > 0) {
      if (i === n - 1) alloc = round(totalCost - allocated);
      else {
        alloc = round((totalCost * (r.qty * r.weight)) / totalWeight);
        allocated += alloc;
      }
    }
    r.cost_alloc = alloc;
    r.unit_cost = r.qty > 0 ? round(alloc / r.qty, 4) : 0;
  });
  return rows;
}
