import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider } from './context/AuthContext';
import { ToastProvider } from './lib/toast';
import { ConfirmProvider } from './lib/confirm';
import App from './App';
import './index.css';

if (localStorage.getItem('pl_theme') === 'dark') {
  document.documentElement.classList.add('dark');
}

const queryClient = new QueryClient({
  // Keep data fresh like Livewire: refetch when a screen mounts and when the
  // window regains focus, so lists and dropdowns reflect the latest writes.
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 0,
      refetchOnMount: 'always',
      refetchOnWindowFocus: true,
      refetchOnReconnect: true,
    },
  },
});

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <AuthProvider>
          <ToastProvider>
            <ConfirmProvider>
              <App />
            </ConfirmProvider>
          </ToastProvider>
        </AuthProvider>
      </BrowserRouter>
    </QueryClientProvider>
  </StrictMode>,
);
