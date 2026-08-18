import { createContext, useCallback, useContext, useState } from 'react';
import { CheckCircleIcon, ExclamationCircleIcon, InformationCircleIcon, XMarkIcon } from '@heroicons/react/24/outline';

const ToastCtx = createContext(null);
export function useToast() {
  return useContext(ToastCtx) ?? { success() {}, error() {}, info() {}, show() {} };
}

let counter = 0;
const toneStyles = {
  success: { icon: CheckCircleIcon, bar: 'bg-leaf', text: 'text-leaf' },
  error: { icon: ExclamationCircleIcon, bar: 'bg-danger', text: 'text-danger' },
  info: { icon: InformationCircleIcon, bar: 'bg-info', text: 'text-info' },
};

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);
  const dismiss = useCallback((id) => setToasts((t) => t.filter((x) => x.id !== id)), []);
  const push = useCallback((message, tone = 'success', opts = {}) => {
    const id = ++counter;
    setToasts((t) => [...t, { id, message, tone }]);
    setTimeout(() => dismiss(id), opts.duration ?? 3500);
    return id;
  }, [dismiss]);

  const api = {
    success: (m, o) => push(m, 'success', o),
    error: (m, o) => push(m, 'error', o),
    info: (m, o) => push(m, 'info', o),
    show: push,
  };

  return (
    <ToastCtx.Provider value={api}>
      {children}
      <div className="pointer-events-none fixed bottom-4 right-4 z-[100] flex w-full max-w-sm flex-col gap-2">
        {toasts.map((t) => {
          const s = toneStyles[t.tone] ?? toneStyles.info;
          const Icon = s.icon;
          return (
            <div key={t.id} className="toast-in pointer-events-auto flex items-start gap-3 overflow-hidden rounded-2xl bg-surface/95 p-3 pr-2 shadow-pop ring-1 ring-line backdrop-blur">
              <span className={'mt-0.5 h-full w-1 shrink-0 self-stretch rounded-full ' + s.bar} />
              <Icon className={'mt-0.5 size-5 shrink-0 ' + s.text} />
              <p className="flex-1 py-0.5 text-sm text-ink">{t.message}</p>
              <button onClick={() => dismiss(t.id)} className="rounded-lg p-1 text-faint hover:bg-paper hover:text-ink"><XMarkIcon className="size-4" /></button>
            </div>
          );
        })}
      </div>
    </ToastCtx.Provider>
  );
}
