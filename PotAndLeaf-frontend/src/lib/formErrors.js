/** Normalise Laravel validation errors (string or string[]) for Field error props. */
export function fieldError(errors, key) {
  const v = errors?.[key];
  if (v == null || v === '') return undefined;
  if (Array.isArray(v)) return v[0];
  return String(v);
}

/** First useful API error message from an axios/Laravel error. */
export function apiMessage(err, fallback = 'Something went wrong.') {
  const data = err?.response?.data;
  const first = Object.values(data?.errors ?? {}).flat()[0];
  if (first) return String(first);
  if (typeof data?.message === 'string' && data.message) return data.message;
  return fallback;
}
