import { useCallback, useEffect, useState } from 'react';
import { Outlet } from 'react-router-dom';
import Sidebar from './Sidebar';
import Topbar from './Topbar';
import { ConfirmProvider } from '../lib/confirm';

export default function AppShell() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const closeSidebar = useCallback(() => setSidebarOpen(false), []);

  useEffect(() => {
    if (!sidebarOpen) return undefined;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = prev;
    };
  }, [sidebarOpen]);

  return (
    <ConfirmProvider>
      <div className="app-canvas flex h-full min-h-0 gap-0 p-0 sm:gap-3 sm:p-3">
        <div className="app-canvas-photos" aria-hidden>
          <img className="app-canvas-garden" src="/images/erp-garden-bg.png" alt="" />
          <img className="app-canvas-leaves" src="/images/erp-leaves-right.png" alt="" />
        </div>
        <Sidebar open={sidebarOpen} onClose={closeSidebar} />
        <div className="app-shell-main glass-panel flex min-h-0 w-full min-w-0 flex-1 flex-col overflow-hidden rounded-none sm:rounded-[28px]">
          <Topbar onMenu={() => setSidebarOpen(true)} />
          <main className="app-main relative z-0 min-h-0 flex-1 overflow-x-hidden overflow-y-auto">
            <Outlet />
          </main>
        </div>
      </div>
    </ConfirmProvider>
  );
}
