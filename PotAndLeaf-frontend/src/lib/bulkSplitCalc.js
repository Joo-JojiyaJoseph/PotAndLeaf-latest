// Mirror of app/Support/Purchasing/BulkSplitCalculator.php + SplitQuantityDistributor.php

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

/** @returns {number[]} */
export function splitByQtyPerSplit(total, qtyPerSplit) {
  const t = Number(total) || 0;
  const q = Number(qtyPerSplit) || 0;
  if (q <= 0 || t <= 0) return [];
  const splits = [];
  let remaining = round(t, 3);
  while (remaining > 0.0001) {
    const qty = round(Math.min(q, remaining), 3);
    splits.push(qty);
    remaining = round(remaining - qty, 3);
  }
  return splits;
}

/** @returns {number[]} */
export function splitByNumSplits(total, numSplits) {
  const t = round(Number(total) || 0, 3);
  const n = Math.max(0, Math.floor(Number(numSplits) || 0));
  if (n <= 0 || t <= 0) return [];

  const isWhole = Math.abs(t - Math.round(t)) < 0.0001;
  if (isWhole) {
    const totalInt = Math.round(t);
    const base = Math.floor(totalInt / n);
    const remainder = totalInt % n;
    const splits = Array(n).fill(base);
    for (let i = 0; i < remainder; i++) splits[i]++;
    return splits.map(Number);
  }

  const base = Math.floor((t / n) * 1000) / 1000;
  const splits = Array(n).fill(base);
  let diff = round(t - round(base * n, 3), 3);
  let i = 0;
  while (Math.abs(diff) > 0.0001 && i < n) {
    const adjust = diff > 0 ? Math.min(0.001, diff) : Math.max(-0.001, diff);
    splits[i] = round(splits[i] + adjust, 3);
    diff = round(diff - adjust, 3);
    i++;
  }
  return splits;
}

export function buildSplitLines(quantities) {
  return quantities.map((qty, i) => ({
    split_label: `Split ${String(i + 1).padStart(3, '0')}`,
    qty: String(qty),
    weight: '1',
    retail_price: '',
  }));
}
