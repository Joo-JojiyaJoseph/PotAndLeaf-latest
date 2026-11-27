import { describe, it, expect } from 'vitest';
import { priceFromMargin, marginFromPrice } from './pricing';

describe('pricing', () => {
  it('adds the margin and rounds to a whole rupee', () => {
    expect(priceFromMargin(100, 25)).toBe(125);
    expect(priceFromMargin(83.5, 30)).toBe(109);
  });
  it('returns null when cost or margin is missing', () => {
    expect(priceFromMargin(0, 25)).toBeNull();
    expect(priceFromMargin(100, '')).toBeNull();
  });
  it('derives a margin from a price', () => {
    expect(marginFromPrice(100, 125)).toBe('25');
    expect(marginFromPrice(0, 125)).toBe('');
  });
});
