import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useIsFetching } from '@tanstack/react-query';
import {
  ArrowPathIcon,
  ArrowRightStartOnRectangleIcon,
  Bars3Icon,
  MoonIcon,
  SunIcon,
  UserCircleIcon,
  MagnifyingGlassIcon,
} from '@heroicons/react/24/outline';
import { useAuth } from '../context/AuthContext';
import { queryClient } from '../lib/queryClient';
import { classNames } from '../lib/format';

export default function Topbar({ onMenu }) {
  const { user, activeCompany, logout } = useAuth();
  const [menuOpen, setMenuOpen] = useState(false);
  const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));
  const menuRef = useRef(null);
  const fetching = useIsFetching();

  const initials = (user?.name ?? '?')
    .split(' ')
    .map((p) => p[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();

  useEffect(() => {
    if (!menuOpen) return;
    const onDown = (e) => {
      if (menuRef.current && !menuRef.current.contains(e.target)) setMenuOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, [menuOpen]);

  function toggleDark() {
    const next = !dark;
    setDark(next);
    document.documentElement.classList.toggle('dark', next);
    localStorage.setItem('pl_theme', next ? 'dark' : 'light');
  }

  return (
    <header className="sticky top-0 z-20 flex h-14 items-center gap-2 border-b border-line bg-surface/90 px-3 backdrop-blur sm:gap-3 sm:px-4">
      <button
        onClick={onMenu}
        className="rounded-[10px] p-1.5 text-muted hover:bg-paper hover:text-ink lg:hidden"
        aria-label="Open menu"
      >
        <Bars3Icon className="size-5" />
      </button>

      <div className="relative hidden max-w-sm flex-1 sm:block">
        {/* <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
        <input
          placeholder="Search products, suppliers, bills…"
          className="h-9 w-full rounded-[10px] border border-line bg-paper pl-9 pr-3 text-sm placeholder:text-muted/70 focus:outline-none focus:ring-2 focus:ring-leaf/30"
        /> */}
      </div>

      <div className="ml-auto flex items-center gap-1.5 sm:gap-3">
        <button
          onClick={() => queryClient.invalidateQueries()}
          className="group relative rounded-[10px] p-2 text-muted hover:bg-paper hover:text-ink"
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
          onClick={toggleDark}
          className="rounded-[10px] p-2 text-muted hover:bg-paper hover:text-ink"
          aria-label={dark ? 'Switch to light mode' : 'Switch to dark mode'}
          title={dark ? 'Light mode' : 'Dark mode'}
        >
          {dark ? <SunIcon className="size-[18px]" /> : <MoonIcon className="size-[18px]" />}
        </button>

        {activeCompany && (
          <span className="hidden max-w-44 truncate rounded-full border border-line bg-paper px-3 py-1 font-mono text-xs text-muted md:inline">
            {activeCompany.name}
          </span>
        )}

        <div className="relative" ref={menuRef}>
          <button
            onClick={() => setMenuOpen((v) => !v)}
            className="flex size-8 items-center justify-center rounded-full bg-leaf-soft text-xs font-semibold text-leaf ring-leaf/30 transition-shadow hover:ring-2"
            aria-label="Account menu"
          >
            {initials}
          </button>
          {menuOpen && (
            <div className="dialog-in absolute right-0 mt-2 w-56 rounded-xl border border-line bg-surface py-1 shadow-pop">
              <div className="border-b border-line px-3 py-2">
                <div className="truncate text-sm font-medium">{user?.name}</div>
                <div className="truncate text-xs text-muted">{user?.email}</div>
                {activeCompany && (
                  <div className="mt-1 truncate font-mono text-[10px] uppercase tracking-wide text-faint md:hidden">
                    {activeCompany.name}
                  </div>
                )}
              </div>
              <Link
                to="/profile"
                onClick={() => setMenuOpen(false)}
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-muted hover:bg-paper hover:text-ink"
              >
                <UserCircleIcon className="size-4" />
                My profile
              </Link>
              <button
                onClick={logout}
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-muted hover:bg-paper hover:text-ink"
              >
                <ArrowRightStartOnRectangleIcon className="size-4" />
                Sign out
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
