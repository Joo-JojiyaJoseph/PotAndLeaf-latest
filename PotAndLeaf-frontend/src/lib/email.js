// Same rule as the server (app/Support/Validation/Email.php): name@domain.tld,
// no spaces, a dot in the domain.
export const EMAIL_PATTERN = /^[A-Za-z0-9._%+-]+@[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,}$/;
export const EMAIL_MESSAGE = 'Enter a valid email address, like name@example.com.';

export function isEmail(value) {
  return EMAIL_PATTERN.test(String(value ?? '').trim());
}

/** Message for a typed (non-empty) value that is not a valid email, else null. */
export function emailHint(value) {
  const v = String(value ?? '').trim();
  return v && !isEmail(v) ? EMAIL_MESSAGE : null;
}

/** For a comma-separated list: the addresses that are not valid. */
export function badEmails(list) {
  return String(list ?? '').split(/[,;\s]+/).map((e) => e.trim()).filter(Boolean).filter((e) => !isEmail(e));
}
