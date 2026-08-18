# Pot & Leaf ERP — React SPA

A standalone Vite + React frontend that talks to the Laravel API over JSON
(Sanctum token auth). This is the new decoupled frontend, replacing the earlier
Inertia pages.

## Stack
React 19 · React Router 7 · TanStack Query 5 · Axios · Tailwind v4 · Heroicons.
JavaScript (JSX), no TypeScript.

## Run it
```bash
npm install          # or pnpm install
npm run dev          # http://localhost:5173
```
`/api` is proxied to the Laravel API at `http://localhost:8000` (see vite.config.js),
so there's no CORS to configure in development. Start the API with `php artisan serve`
first.

## How auth + company scoping work
- Login posts to `/api/login` and gets a Sanctum token, stored in `localStorage`.
- Every request carries `Authorization: Bearer <token>` and, once a company is
  chosen, `X-Company-Id: <id>` (see `src/lib/api.js`). The API scopes all data to
  that company and checks permissions against it.
- `AuthContext` fetches `/api/permissions` for the active company so the UI can
  gate actions with `can('suppliers.create')`.

## What's here
- `src/components/` — app shell (sidebar + company switcher + topbar) and UI primitives.
- `src/pages/Login.jsx`, `Dashboard.jsx`, `ComingSoon.jsx`
- `src/pages/suppliers/SuppliersList.jsx` — full list + create/edit/delete against the API.

Modules tagged "soon" in the sidebar route to a placeholder; they plug into this
shell as their API endpoints and screens are built.

## Design
IBM Plex Sans/Mono, warm paper canvas, deep leaf-green primary with a terracotta
("pot") accent — the two-tone Pot & Leaf identity. Monospace numerals for codes,
amounts and metrics, since this is a data-dense operations tool.
