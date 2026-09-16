export function Spinner({ className }) {
  return (
    <span
      className={['inline-block size-4 animate-spin rounded-full border-2 border-line border-t-leaf', className].filter(Boolean).join(' ')}
      aria-label="Loading"
    />
  );
}
