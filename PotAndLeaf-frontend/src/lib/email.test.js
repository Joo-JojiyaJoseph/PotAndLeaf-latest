import { describe, it, expect } from 'vitest';
import { isEmail, emailHint, badEmails } from './email';

describe('email check', () => {
  it('accepts normal addresses', () => {
    ['ravi@gmail.com', 'a.b+c@shop.co.in', 'HO_admin@pot-leaf.in'].forEach((e) => expect(isEmail(e)).toBe(true));
  });
  it('refuses broken ones', () => {
    ['ravi@gmail', 'ravi.gmail.com', 'ravi @gmail.com', '@gmail.com', 'ravi@', 'ravi@@gmail.com', 'ravi@gmail.c'].forEach((e) => expect(isEmail(e)).toBe(false));
  });
  it('gives a hint only for typed, wrong values', () => {
    expect(emailHint('')).toBeNull();
    expect(emailHint('ravi@gmail.com')).toBeNull();
    expect(emailHint('ravi@gmail')).toMatch(/valid email/);
  });
  it('lists the wrong ones in a comma list', () => {
    expect(badEmails('a@b.com, wrong, c@d.in')).toEqual(['wrong']);
  });
});
