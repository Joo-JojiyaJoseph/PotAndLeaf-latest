import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { Spinner } from '../components/ui';

export default function ProtectedRoute() {
  const { token, booting } = useAuth();

  if (booting) {
    return (
      <div className="flex h-full items-center justify-center">
        <Spinner className="size-6" />
      </div>
    );
  }

  if (!token) return <Navigate to="/login" replace />;

  return <Outlet />;
}
