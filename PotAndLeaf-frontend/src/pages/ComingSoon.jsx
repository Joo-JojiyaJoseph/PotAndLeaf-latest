import { useParams } from 'react-router-dom';
import { WrenchScrewdriverIcon } from '@heroicons/react/24/outline';

const LABELS = {
  purchase: 'Purchase Management',
  inventory: 'Inventory Management',
  transfers: 'Stock Transfer',
  production: 'Production & Value Addition',
  pos: 'Sales & POS',
  rental: 'Plant Rental',
  customers: 'Customers',
  loyalty: 'Loyalty Points',
  products: 'Product Master',
  roles: 'Roles & Permissions',
  users: 'Users',
  reports: 'Reporting',
  settings: 'Settings',
};

export default function ComingSoon() {
  const { module } = useParams();
  const label = LABELS[module] ?? 'This module';

  return (
    <div className="flex h-full items-center justify-center p-6">
      <div className="max-w-md text-center">
        <div className="mx-auto flex size-12 items-center justify-center rounded-2xl bg-leaf-soft text-leaf">
          <WrenchScrewdriverIcon className="size-6" />
        </div>
        <h1 className="mt-4 text-lg font-semibold">{label}</h1>
        <p className="mt-1 text-sm text-muted">
          This module is on the build roadmap. The foundation — auth, company
          scoping, and the API — is already in place, so it plugs straight into
          this shell when it's built.
        </p>
      </div>
    </div>
  );
}
