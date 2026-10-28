import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router-dom';
import { useIsFetching, useQuery } from '@tanstack/react-query';
import api from '../lib/api';
import { formatDateTime } from '../lib/format';
import {
  ArrowPathIcon,
  ArrowRightStartOnRectangleIcon,
  Bars3Icon,
  BellIcon,
  MoonIcon,
  QuestionMarkCircleIcon,
  SunIcon,
  UserCircleIcon,
  KeyIcon,
  InboxArrowDownIcon,
} from '@heroicons/react/24/outline';
import { useAuth } from '../context/AuthContext';
import { queryClient } from '../lib/queryClient';
import { classNames } from '../lib/format';

const iconBtn =
  'relative flex size-9 items-center justify-center rounded-full glass-control text-ink hover:bg-white hover:text-ink';

export default function Topbar({ onMenu }) {
  const { user, activeCompany, logout, isSuperAdmin, can, reloadPermissions } = useAuth();
  // Access requests: how many wait for me (admins) and answers to my own requests.
  const noticesQ = useQuery({
    queryKey: ['access-notices', activeCompany?.id],
    queryFn: () => api.get('/access-requests/notices').then((r) => r.data.data),
    enabled: Boolean(activeCompany && user),
    refetchInterval: 60_000,
  });
  const waiting = noticesQ.data?.waiting_for_me ?? 0;
  const answers = noticesQ.data?.my_answers ?? [];
  const seenKey = `pl_seen_answers_${user?.id ?? ''}`;
  const [seen, setSeen] = useState(() => { try { return JSON.parse(localStorage.getItem(seenKey) || '[]'); } catch { return []; } });
  const unseen = answers.filter((a) => !seen.includes(a.id));
  const badge = waiting + unseen.length;
  // A newly approved request: load the new permissions straight away (menu updates, no refresh needed).
  const newlyApproved = unseen.filter((a) => a.status === 'approved').map((a) => a.id).join(',');
  useEffect(() => { if (newlyApproved) reloadPermissions?.(); }, [newlyApproved, reloadPermissions]);
  function markSeen() {
    const ids = [...new Set([...seen, ...answers.map((a) => a.id)])].slice(-50);
    setSeen(ids);
    try { localStorage.setItem(seenKey, JSON.stringify(ids)); } catch { /* ignore */ }
  }
  const [menuOpen, setMenuOpen] = useState(false);
  const [notesOpen, setNotesOpen] = useState(false);
  const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));
  const menuRef = useRef(null);
  const notesRef = useRef(null);
  const menuBtnRef = useRef(null);
  const notesBtnRef = useRef(null);
  const fetching = useIsFetching();

  const initials = (user?.name ?? '?')
    .split(' ')
    .map((p) => p[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();

  useEffect(() => {
    const onDown = (e) => {
      if (menuRef.current?.contains(e.target) || menuBtnRef.current?.contains(e.target)) return;
      setMenuOpen(false);
    };
    const onNotes = (e) => {
      if (notesRef.current?.contains(e.target) || notesBtnRef.current?.contains(e.target)) return;
      setNotesOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('mousedown', onNotes);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('mousedown', onNotes);
    };
  }, []);

  function toggleDark() {
    const next = !dark;
    setDark(next);
    document.documentElement.classList.toggle('dark', next);
    localStorage.setItem('pl_theme', next ? 'dark' : 'light');
  }

  return (
    <header className="relative z-30 flex h-14 min-w-0 shrink-0 items-center gap-1.5 border-b border-line/70 bg-white/55 px-2.5 backdrop-blur-md sm:h-[3.75rem] sm:gap-3 sm:px-5">
      <button
        onClick={onMenu}
        className={classNames(iconBtn, 'lg:hidden')}
        aria-label="Open menu"
      >
        <Bars3Icon className="size-5" />
      </button>

      <div className="min-w-0 flex-1" />

      <div className="ml-auto flex shrink-0 items-center gap-1.5">
        <button
          onClick={() => queryClient.invalidateQueries()}
          className={iconBtn}
          aria-label="Refresh data"
          title={fetching ? 'Refreshing…' : 'Refresh data'}
        >
          <ArrowPathIcon className={classNames('size-[18px]', fetching && 'animate-spin text-leaf')} />
          <span
            className={classNames(
              'absolute right-1.5 top-1.5 size-1.5 rounded-full transition-colors',
              fetching ? 'bg-leaf' : 'bg-transparent',
            )}
            aria-hidden
          />
        </button>

        <button
          ref={notesBtnRef}
          type="button"
          className={iconBtn}
          aria-label="Notifications"
          aria-expanded={notesOpen}
          title="Notifications"
          onClick={() => { setNotesOpen((v) => !v); setMenuOpen(false); if (!notesOpen) markSeen(); }}
        >
          <BellIcon className="size-[18px]" />
          {badge > 0 && <span className="absolute -right-0.5 -top-0.5 flex min-w-[18px] items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold leading-[18px] text-white">{badge}</span>}
        </button>

        <Link to="/help" className={iconBtn} aria-label="Help and user guide" title="Help & user guide">
          <QuestionMarkCircleIcon className="size-[18px]" />
        </Link>

        <button
          onClick={toggleDark}
          className={iconBtn}
          aria-label={dark ? 'Switch to light mode' : 'Switch to dark mode'}
          title={dark ? 'Light mode' : 'Dark mode'}
        >
          {dark ? <SunIcon className="size-[18px]" /> : <MoonIcon className="size-[18px]" />}
        </button>

        <button
          ref={menuBtnRef}
          onClick={() => { setMenuOpen((v) => !v); setNotesOpen(false); }}
          className="ml-1 flex size-9 items-center justify-center rounded-full bg-leaf-soft text-xs font-semibold text-leaf-hover shadow-soft ring-1 ring-line transition-shadow hover:ring-2 hover:ring-leaf/40"
          aria-label="Account menu"
          aria-expanded={menuOpen}
        >
          {initials}
        </button>
      </div>

      {notesOpen && createPortal(
        <div
          ref={notesRef}
          className="dialog-in glass-menu fixed right-4 top-[4.25rem] z-[120] w-[min(22rem,calc(100vw-1.5rem))] rounded-2xl py-1 sm:right-6"
          role="dialog"
          aria-label="Notifications"
        >
          <div className="border-b border-line px-4 py-3">
            <p className="text-sm font-semibold text-ink">Notifications</p>
            <p className="text-xs text-muted">Access requests and answers</p>
          </div>
          {waiting > 0 && (
            <Link to="/access-requests" onClick={() => setNotesOpen(false)} className="flex items-start gap-3 border-b border-line px-4 py-3 hover:bg-leaf-soft/60">
              <InboxArrowDownIcon className="mt-0.5 size-5 shrink-0 text-amber" />
              <span className="text-sm"><b>{waiting}</b> access request{waiting === 1 ? '' : 's'} waiting for you to approve or reject.</span>
            </Link>
          )}
          {answers.map((a) => (
            <Link key={a.id} to="/request-access" onClick={() => setNotesOpen(false)} className="flex items-start gap-3 border-b border-line px-4 py-3 last:border-0 hover:bg-leaf-soft/60">
              <KeyIcon className={'mt-0.5 size-5 shrink-0 ' + (a.status === 'approved' ? 'text-leaf' : 'text-danger')} />
              <span className="text-sm">
                Your access request was <b>{a.status}</b>{a.decided_by ? ` by ${a.decided_by}` : ''}.
                {a.status === 'approved' && ' You can use it now.'}
                {a.decision_note && <span className="block text-xs text-muted">“{a.decision_note}”</span>}
                <span className="block text-[11px] text-faint">{formatDateTime(a.decided_at)}</span>
              </span>
            </Link>
          ))}
          {waiting === 0 && answers.length === 0 && (
            <div className="px-4 py-8 text-center">
              <span className="mx-auto flex size-10 items-center justify-center rounded-full bg-leaf-soft text-leaf">
                <BellIcon className="size-5" />
              </span>
              <p className="mt-3 text-sm font-medium text-ink">You are all caught up</p>
              <p className="mt-1 text-xs text-muted">New alerts will appear here.</p>
            </div>
          )}
        </div>,
        document.body,
      )}

      {menuOpen && createPortal(
        <div
          ref={menuRef}
          className="dialog-in glass-menu fixed right-4 top-[4.25rem] z-[120] w-56 rounded-2xl py-1 sm:right-6"
        >
          <div className="border-b border-line px-3 py-2">
            <div className="truncate text-sm font-medium text-ink">{user?.name}</div>
            <div className="truncate text-xs text-muted">{user?.email}</div>
            {activeCompany && (
              <div className="mt-1 truncate font-mono text-[10px] uppercase tracking-wide text-faint">
                {activeCompany.name}
              </div>
            )}
          </div>
          <Link
            to="/profile"
            onClick={() => setMenuOpen(false)}
            className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-ink hover:bg-leaf-soft/70"
          >
            <UserCircleIcon className="size-4" />
            My profile
          </Link>
          {!isSuperAdmin && (
            <Link
              to="/request-access"
              onClick={() => setMenuOpen(false)}
              className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-ink hover:bg-leaf-soft/70"
            >
              <KeyIcon className="size-4" />
              Request access
            </Link>
          )}
          {(isSuperAdmin || can('users.update')) && (
            <Link
              to="/access-requests"
              onClick={() => setMenuOpen(false)}
              className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-ink hover:bg-leaf-soft/70"
            >
              <InboxArrowDownIcon className="size-4" />
              Access requests{waiting > 0 ? ` (${waiting})` : ''}
            </Link>
          )}
          <button
            onClick={logout}
            className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-ink hover:bg-leaf-soft/70"
          >
            <ArrowRightStartOnRectangleIcon className="size-4" />
            Sign out
          </button>
        </div>,
        document.body,
      )}
    </header>
  );
}
