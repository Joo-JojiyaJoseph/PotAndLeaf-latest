/** Normalise Laravel validation errors (string or string[]) for Field error props. */
export function fieldError(errors, key) {
  const v = errors?.[key];
  if (v == null || v === '') return undefined;
  if (Array.isArray(v)) return v[0];
  return String(v);
}
