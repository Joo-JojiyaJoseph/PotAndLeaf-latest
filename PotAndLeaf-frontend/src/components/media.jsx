import { useEffect, useRef, useState } from 'react';
import { ArrowUpTrayIcon, PhotoIcon, XMarkIcon, PlusIcon } from '@heroicons/react/24/outline';
import api from '../lib/api';
import { useToast } from '../lib/toast';
import { Spinner } from './ui';

/** Dev: same-origin /storage via Vite proxy. Prod: VITE_ASSET_URL prefix. */
const ASSET_BASE = import.meta.env.DEV ? '' : (import.meta.env.VITE_ASSET_URL || '').replace(/\/$/, '');

export function mediaUrl(value) {
  if (!value) return null;
  if (value.startsWith('data:') || value.startsWith('blob:')) return value;

  // Repair legacy corrupted URLs saved as http:https://host/storage/...
  const embedded = value.match(/(https?:\/\/[^\s]+)/i);
  if (embedded && !/^https?:\/\//i.test(value)) {
    value = embedded[1];
  }

  // Absolute URL from API — use as-is (backend already resolved APP_URL).
  if (/^https?:\/\//i.test(value)) {
    return value;
  }

  if (value.startsWith('/storage/')) return `${ASSET_BASE}${value}`;
  if (value.startsWith('storage/')) return `${ASSET_BASE}/${value}`;
  if (value.startsWith('/')) return `${ASSET_BASE}${value}`;
  if (value.startsWith('uploads/')) return `${ASSET_BASE}/storage/${value}`;
  return `${ASSET_BASE}/storage/${value.replace(/^\/+/, '')}`;
}

/** Image with automatic fallback when the URL is missing or fails to load. */
export function MediaImg({ value, className = '', iconClassName = 'size-7 text-leaf/50', alt = '' }) {
  const [broken, setBroken] = useState(false);
  const src = mediaUrl(value);

  useEffect(() => {
    setBroken(false);
  }, [value]);

  if (!src || broken) {
    return <PhotoIcon className={iconClassName} aria-hidden="true" />;
  }

  return (
    <img
      src={src}
      alt={alt}
      className={className}
      onError={() => setBroken(true)}
    />
  );
}

async function uploadFile(file) {
  const fd = new FormData();
  fd.append('file', file);
  const res = await api.post('/uploads', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
  return res.data.data.url;
}

/** Single image picker — avatar/logo. `value` is a URL string (or null). */
export function ImageUpload({ value, onChange, shape = 'circle', hint = 'PNG or JPG, up to 5MB', onBusyChange }) {
  const inputRef = useRef(null);
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const [broken, setBroken] = useState(false);
  const blobRef = useRef(null);
  const rounded = shape === 'circle' ? 'rounded-full' : shape === 'rounded' ? 'rounded-2xl' : 'rounded-2xl';

  const revokeBlob = () => {
    if (blobRef.current) {
      URL.revokeObjectURL(blobRef.current);
      blobRef.current = null;
    }
  };

  async function pick(e) {
    const file = e.target.files?.[0];
    if (!file) return;

    revokeBlob();
    setBusy(true);
    onBusyChange?.(true);
    setBroken(false);

    const localPreview = URL.createObjectURL(file);
    blobRef.current = localPreview;
    onChange(localPreview);
    try {
      const url = await uploadFile(file);
      revokeBlob();
      onChange(url);
    } catch {
      revokeBlob();
      onChange(null);
      toast.error('Upload failed. Try a smaller image.');
    } finally {
      setBusy(false);
      onBusyChange?.(false);
      e.target.value = '';
    }
  }

  function clear() {
    revokeBlob();
    onChange(null);
  }

  const src = mediaUrl(value);

  useEffect(() => {
    setBroken(false);
  }, [value]);

  useEffect(() => () => revokeBlob(), []);

  return (
    <div className="flex items-center gap-4">
      <div className={'relative flex size-20 shrink-0 items-center justify-center overflow-hidden bg-leaf-soft ' + rounded}>
        {busy ? (
          <>
            {src && !broken && (
              <img src={src} alt="" className="size-full object-cover opacity-60" aria-hidden />
            )}
            <Spinner className="absolute size-5" />
          </>
        ) : src && !broken ? (
          <img src={src} alt="" className="size-full object-cover" onError={() => setBroken(true)} />
        ) : (
          <PhotoIcon className="size-8 text-leaf/60" />
        )}
      </div>
      <div>
        <input ref={inputRef} type="file" accept="image/*" className="hidden" onChange={pick} />
        <div className="flex items-center gap-2">
          <button type="button" onClick={() => inputRef.current?.click()} disabled={busy}
            className="inline-flex items-center gap-1.5 rounded-xl border border-line-strong bg-surface px-3 py-1.5 text-sm font-medium text-ink transition-colors hover:bg-sidebar">
            <ArrowUpTrayIcon className="size-4" /> {value ? 'Replace' : 'Upload'}
          </button>
          {value && (
            <button type="button" onClick={clear} disabled={busy} className="rounded-lg p-1.5 text-muted hover:bg-paper hover:text-danger">
              <XMarkIcon className="size-4" />
            </button>
          )}
        </div>
        <p className="mt-1 text-xs text-muted">{hint}</p>
      </div>
    </div>
  );
}

/** Multi-image gallery — `value` is an array of URL strings. */
export function ImageGallery({ value = [], onChange, max = 6, onBusyChange }) {
  const inputRef = useRef(null);
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const list = value ?? [];

  async function pick(e) {
    const files = Array.from(e.target.files ?? []);
    if (!files.length) return;
    setBusy(true);
    onBusyChange?.(true);

    const room = Math.max(0, max - list.length);
    const slice = files.slice(0, room);
    const placeholders = slice.map((f) => URL.createObjectURL(f));
    onChange([...list, ...placeholders]);

    try {
      const urls = [];
      for (const f of slice) {
        urls.push(await uploadFile(f));
      }
      placeholders.forEach((u) => URL.revokeObjectURL(u));
      onChange([...list, ...urls]);
      if (files.length > room) toast.info(`Only ${max} images allowed.`);
    } catch {
      placeholders.forEach((u) => URL.revokeObjectURL(u));
      onChange(list);
      toast.error('One or more uploads failed.');
    } finally {
      setBusy(false);
      onBusyChange?.(false);
      e.target.value = '';
    }
  }

  const removeAt = (i) => {
    const removed = list[i];
    if (typeof removed === 'string' && removed.startsWith('blob:')) {
      URL.revokeObjectURL(removed);
    }
    onChange(list.filter((_, idx) => idx !== i));
  };

  return (
    <div>
      <div className="flex flex-wrap gap-3">
        {list.map((url, i) => (
          <div key={`${url}-${i}`} className="group relative size-24 overflow-hidden rounded-2xl border border-line">
            <MediaImg value={url} className="size-full object-cover" iconClassName="size-8 text-leaf/50" />
            <button type="button" onClick={() => removeAt(i)}
              className="absolute right-1 top-1 flex size-6 items-center justify-center rounded-full bg-ink/60 text-white opacity-0 transition-opacity group-hover:opacity-100"><XMarkIcon className="size-3.5" /></button>
            {i === 0 && <span className="absolute bottom-1 left-1 rounded bg-leaf px-1.5 py-0.5 text-[9px] font-medium text-white">Primary</span>}
          </div>
        ))}
        {list.length < max && (
          <button type="button" onClick={() => inputRef.current?.click()} disabled={busy}
            className="flex size-24 flex-col items-center justify-center gap-1 rounded-2xl border border-dashed border-line-strong text-muted transition-colors hover:bg-sidebar">
            {busy ? <Spinner className="size-5" /> : <><PlusIcon className="size-5" /><span className="text-[11px]">Add photo</span></>}
          </button>
        )}
      </div>
      <input ref={inputRef} type="file" accept="image/*" multiple className="hidden" onChange={pick} />
      <p className="mt-2 text-xs text-muted">First image is the primary. Up to {max} photos.</p>
    </div>
  );
}
