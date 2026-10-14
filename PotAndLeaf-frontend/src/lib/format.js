export function formatCurrency(value) {
  const n = Number(value ?? 0);
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 2,
  }).format(Number.isFinite(n) ? n : 0);
}

const IST = 'Asia/Kolkata';

/** Calendar date in India, without shifting a YYYY-MM-DD value to the previous day. */
export function formatDate(value) {
  if (!value) return '—';
  const raw = String(value);
  if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
    const [year, month, day] = raw.split('-').map(Number);
    return new Intl.DateTimeFormat('en-IN', {
      day: '2-digit', month: 'short', year: 'numeric', timeZone: IST,
    }).format(new Date(Date.UTC(year, month - 1, day, 12, 0, 0)));
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return new Intl.DateTimeFormat('en-IN', {
    day: '2-digit', month: 'short', year: 'numeric', timeZone: IST,
  }).format(date);
}

export function formatDateTime(value) {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return new Intl.DateTimeFormat('en-IN', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit', hour12: false, timeZone: IST,
  }).format(date);
}

/** Today's calendar date in Asia/Kolkata as YYYY-MM-DD. */
export function todayISO(date = new Date()) {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: IST, year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(date);
}

/** Shift a YYYY-MM-DD calendar date by whole days, staying on the Indian calendar. */
export function addCalendarDays(isoDate, days) {
  const [year, month, day] = String(isoDate).slice(0, 10).split('-').map(Number);
  return todayISO(new Date(Date.UTC(year, month - 1, day + days, 12, 0, 0)));
}

/** Monday of the Asia/Kolkata week that contains the given calendar date. */
export function startOfIstWeek(isoDate = todayISO()) {
  const [year, month, day] = String(isoDate).slice(0, 10).split('-').map(Number);
  const weekday = new Date(Date.UTC(year, month - 1, day, 12, 0, 0)).getUTCDay();
  const mondayOffset = weekday === 0 ? -6 : 1 - weekday;
  return addCalendarDays(isoDate, mondayOffset);
}

export function classNames(...parts) {
  return parts.filter(Boolean).join(' ');
}
