import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { Card } from '../components/ui';

/**
 * Route guard — hides pages the user cannot access (direct URL protection).
 * Pass `permission` for a single check, or `anyOf` for modules like Master data.
 */
export default function PermissionRoute({ permission, anyOf, superAdmin, children }) {
  const { can, isSuperAdmin, booting } = useAuth();

  if (booting) return null;

  if (superAdmin && !isSuperAdmin) {
    return (
      <div className="p-6">
        <Card className="p-10 text-center text-sm text-muted">This area is restricted to HO super admins.</Card>
      </div>
    );
  }

  const allowed = isSuperAdmin
    || (anyOf?.length ? anyOf.some((p) => can(p)) : permission ? can(permission) : true);

  if (!allowed) {
    return (
      <div className="p-6">
        <Card className="p-10 text-center text-sm text-muted">
          You do not have permission to access this page.
        </Card>
      </div>
    );
  }

  return children;
}
