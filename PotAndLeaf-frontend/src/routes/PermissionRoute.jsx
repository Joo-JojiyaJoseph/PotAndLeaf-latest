import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

/** Friendly "not allowed" card with a way back, instead of a dead end. */
function NoAccess({ message, need = [] }) {
  return (
    <div className="p-6">
      <div className="rounded-xl bg-surface p-10 text-center shadow-card">
        <p className="text-sm font-medium text-ink">{message}</p>
        <p className="mt-1 text-sm text-muted">If you need this page for your work, send a request and an admin can give you access.</p>
        <div className="mt-4 flex flex-wrap justify-center gap-2">
          <Link to={`/request-access${need.length ? `?need=${need.join(',')}` : ''}`} className="inline-block rounded-full bg-leaf px-4 py-2 text-sm font-medium text-white hover:bg-leaf-hover">Request access</Link>
          <Link to="/" className="inline-block rounded-full border border-line px-4 py-2 text-sm font-medium text-ink hover:bg-paper">Go to dashboard</Link>
        </div>
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
    return <NoAccess message="Your role does not have access to this page." need={permission ? [permission] : (anyOf?.slice(0, 1) ?? [])} />;
  }

  return children;
}
