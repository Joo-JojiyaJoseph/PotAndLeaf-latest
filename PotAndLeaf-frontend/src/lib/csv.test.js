import { describe, expect, it } from 'vitest';
import { parseCsv, toCsv } from './csv';

describe('CSV import reader', () => {
  it('reads quoted values, commas and line breaks', () => {
    const { headers, rows } = parseCsv('﻿name,address,cost_price\n"Jade, small","Line 1\nLine 2",60\nRose,,40\n\n');
    expect(headers).toEqual(['name', 'address', 'cost_price']);
    expect(rows).toEqual([
      { name: 'Jade, small', address: 'Line 1\nLine 2', cost_price: '60' },
      { name: 'Rose', address: '', cost_price: '40' },
    ]);
  });

  it('reads semicolon files from Excel and round-trips our own export', () => {
    expect(parseCsv('name;phone\r\nAnu;98765\r\n').rows).toEqual([{ name: 'Anu', phone: '98765' }]);
    const csv = toCsv([{ name: 'He said "hi"', city: 'Kochi' }]);
    expect(parseCsv(csv).rows).toEqual([{ name: 'He said "hi"', city: 'Kochi' }]);
  });
});
