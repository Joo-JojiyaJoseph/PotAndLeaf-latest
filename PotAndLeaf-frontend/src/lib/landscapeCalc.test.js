import { describe, expect, it } from 'vitest';
import { estimateFigures } from './landscapeCalc';

describe('estimateFigures', () => {
  it('adds work, plants, planting, service, then GST, then takes the discount off the total', () => {
    const f = estimateFigures({
      area_sqft: 1000, rate_per_sqft: 50, planting_cost: 2000, service_charge: 1000, gst_percent: 18, discount: 1000,
      items: [{ kind: 'plant', name: 'Areca', qty: 10, rate: 300 }, { kind: 'material', name: 'Soil', qty: 2, rate: 500 }],
    });
    expect(f.work).toBe(50000);
    expect(f.subtotal).toBe(57000);
    expect(f.gst).toBe(10260);
    expect(f.total).toBe(66260);
    expect(f.plantCount).toBe(10);
    expect(f.plantCost).toBe(3000);
  });
  it('never lets the discount pass the total', () => {
    const f = estimateFigures({ planting_cost: 100, discount: 500 });
    expect(f.total).toBe(0);
    expect(f.overDiscount).toBe(true);
  });
});
