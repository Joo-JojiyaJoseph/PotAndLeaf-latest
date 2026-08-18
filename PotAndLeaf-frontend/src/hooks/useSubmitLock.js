import { useCallback, useRef, useState } from 'react';

/**
 * Prevents double-submit before React Query's isPending flips true.
 * Call release() from mutation onSettled.
 */
export default function useSubmitLock(isPending = false) {
  const lockRef = useRef(false);
  const [locked, setLocked] = useState(false);

  const submit = useCallback(
    (fn) => {
      if (lockRef.current || isPending) return;
      lockRef.current = true;
      setLocked(true);
      fn();
    },
    [isPending],
  );

  const release = useCallback(() => {
    lockRef.current = false;
    setLocked(false);
  }, []);

  return { submit, release, locked: locked || isPending };
}
