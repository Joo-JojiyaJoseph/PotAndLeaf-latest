// Small formatting helpers shared across ERP screens.

export function formatCurrency(value, currency = 'INR', locale = 'en-IN') {
    const number = Number(value ?? 0);
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        maximumFractionDigits: 2,
    }).format(number);
}

export function formatDate(iso, locale = 'en-IN') {
    if (!iso) return '—';
    return new Intl.DateTimeFormat(locale, {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
    }).format(new Date(iso));
}

export function classNames(...values) {
    return values.filter(Boolean).join(' ');
}
