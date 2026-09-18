import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link, useLocation } from 'react-router-dom';
import { useIsFetching } from '@tanstack/react-query';
import {
  ArrowPathIcon,
  ArrowRightStartOnRectangleIcon,
  Bars3Icon,
  BellIcon,
  MoonIcon,
  SunIcon,
  UserCircleIcon,
} from '@heroicons/react/24/outline';
import { useAuth } from '../context/AuthContext';
import { queryClient } from '../lib/queryClient';
import { classNames } from '../lib/format';

const iconBtn =
  'relative flex size-9 items-center justify-center rounded-full glass-control text-ink hover:bg-white hover:text-ink';

export default function Topbar({ onMenu }) {
  const { user, activeCompany, logout } = useAuth();
  const location = useLocation();
  const [menuOpen, setMenuOpen] = useState(false);
  const [notesOpen, setNotesOpen] = useState(false);
  const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));
  const menuRef = useRef(null);
  const notesRef = useRef(null);
  const menuBtnRef = useRef(null);
  const notesBtnRef = useRef(null);
  const fetching = useIsFetching();
  const pathLabel = `${typeof window !== 'undefined' ? window.location.host : ''}${location.pathname}`;

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

      <div className="hidden min-w-0 flex-1 justify-center sm:flex">
        <div className="flex max-w-lg items-center gap-2 truncate rounded-full border border-line bg-white px-4 py-1.5 text-[12px] text-ink shadow-soft">
          <span className="size-1.5 shrink-0 rounded-full bg-leaf" aria-hidden />
          <span className="truncate">{pathLabel}</span>
        </div>
      </div>

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
          onClick={() => { setNotesOpen((v) => !v); setMenuOpen(false); }}
        >
          <BellIcon className="size-[18px]" />
        </button>

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
            <p className="text-xs text-muted">Activity for this session</p>
          </div>
          <div className="px-4 py-8 text-center">
            <span className="mx-auto flex size-10 items-center justify-center rounded-full bg-leaf-soft text-leaf">
              <BellIcon className="size-5" />
            </span>
            <p className="mt-3 text-sm font-medium text-ink">You are all caught up</p>
            <p className="mt-1 text-xs text-muted">New alerts will appear here.</p>
          </div>
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
