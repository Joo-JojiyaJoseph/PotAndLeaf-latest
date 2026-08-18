import { createContext, useCallback, useContext, useRef, useState } from 'react';
import { ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import { Button } from '../components/ui';

const ConfirmCtx = createContext(null);
export function useConfirm() {
  return useContext(ConfirmCtx) ?? (async () => window.confirm('Are you sure?'));
}

export function ConfirmProvider({ children }) {
  const [state, setState] = useState(null);
  const resolver = useRef(null);

  const confirm = useCallback((opts = {}) => new Promise((resolve) => {
    resolver.current = resolve;
    setState({
      title: opts.title ?? 'Are you sure?',
      message: opts.message ?? 'This action cannot be undone.',
      confirmLabel: opts.confirmLabel ?? 'Confirm',
      cancelLabel: opts.cancelLabel ?? 'Cancel',
      tone: opts.tone ?? 'danger',
    });
  }), []);

  const close = (val) => { resolver.current?.(val); resolver.current = null; setState(null); };

  return (
    <ConfirmCtx.Provider value={confirm}>
      {children}
      {state && (
        <div className="fixed inset-0 z-[110] flex items-center justify-center p-4">
          <div className="absolute inset-0 bg-ink/30 backdrop-blur-sm" onClick={() => close(false)} />
          <div className="dialog-in relative w-full max-w-sm rounded-3xl bg-surface p-6 shadow-pop">
            <div className="flex items-start gap-3">
              <span className={'flex size-10 shrink-0 items-center justify-center rounded-2xl ' + (state.tone === 'danger' ? 'bg-danger-soft text-danger' : 'bg-leaf-soft text-leaf')}>
                <ExclamationTriangleIcon className="size-5" />
              </span>
              <div className="min-w-0">
                <h3 className="text-base font-semibold text-ink">{state.title}</h3>
                <p className="mt-1 text-sm text-muted">{state.message}</p>
              </div>
            </div>
            <div className="mt-5 flex justify-end gap-2">
              <Button variant="ghost" size="sm" onClick={() => close(false)}>{state.cancelLabel}</Button>
              <Button variant={state.tone === 'danger' ? 'danger' : 'primary'} size="sm" onClick={() => close(true)}>{state.confirmLabel}</Button>
            </div>
          </div>
        </div>
      )}
    </ConfirmCtx.Provider>
  );
}
