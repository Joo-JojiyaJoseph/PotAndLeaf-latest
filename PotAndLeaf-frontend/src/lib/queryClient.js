import { QueryClient } from '@tanstack/react-query';

/**
 * Shared query client. Lives in its own module so the axios layer can
 * invalidate caches after writes without an import cycle through main.jsx.
 *
 * Freshness strategy (no hard refresh needed):
 *  - data is considered stale after 15s
 *  - active screens poll every 30s while the tab is visible
 *  - returning to the tab / reconnecting refetches immediately
 */
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 15_000,
      refetchInterval: 30_000,
      refetchIntervalInBackground: false,
      refetchOnWindowFocus: true,
      refetchOnReconnect: true,
    },
  },
});

export default queryClient;
