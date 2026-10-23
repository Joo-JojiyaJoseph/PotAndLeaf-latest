import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

/** Friendly "not allowed" card with a way back, instead of a dead end. */
function NoAccess({ message }) {
  return (
    <div className="p-6">
      <div className="rounded-xl bg-surface p-10 text-center shadow-card">
        <p className="text-sm font-medium text-ink">{message}</p>
        <p className="mt-1 text-sm text-muted">If you need this page for your work, ask your manager or HO admin to give your role access.</p>
        <Link to="/" className="mt-4 inline-block rounded-full bg-leaf px-4 py-2 text-sm font-medium text-white hover:bg-leaf-hover">Go to dashboard</Link>
      </div>
    </div>
  );
}

/**
 * Route guard — hides pages the user cannot access (direct URL protection).
 * Pass `permission` for a single check, or `anyOf` for modules like Master data.
 */
export default function PermissionRoute({ permission, anyOf, superAdmin, children }) {
  const { can, isSuperAdmin, booting } = useAuth();

  if (booting) return null;

  if (superAdmin && !isSuperAdmin) {
    return <NoAccess message="This page is only for HO super admins." />;
  }

  const allowed = isSuperAdmin
    || (anyOf?.length ? anyOf.some((p) => can(p)) : permission ? can(permission) : true);

  if (!allowed) {
    return <NoAccess message="Your role does not have access to this page." />;
  }

  return children;
}
