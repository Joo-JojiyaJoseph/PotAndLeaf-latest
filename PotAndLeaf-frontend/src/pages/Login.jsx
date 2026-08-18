import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { EnvelopeIcon, LockClosedIcon, EyeIcon, EyeSlashIcon, ArrowRightIcon, MapPinIcon } from '@heroicons/react/24/outline';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../lib/toast';
import { Spinner } from '../components/ui';

// Lush greenhouse photo — free to use under the Unsplash License (no attribution
// required). A leaf-green base sits behind it so the panel stays on-brand if the
// image is slow or blocked.
const HERO_IMAGE =
  'https://images.unsplash.com/photo-1684479924024-3b243f3155e8?q=80&w=1600&auto=format&fit=crop';

function PotLeafMark({ className = 'size-9' }) {
  return (
    <svg viewBox="0 0 32 32" className={className} aria-hidden>
      <path d="M16 4c5 2 8 6 8 10-4 1-7-1-8-4-1 3-4 5-8 4 0-4 3-8 8-10z" fill="var(--color-leaf)" />
      <path d="M9 19h14l-1.6 7.2a2 2 0 0 1-2 1.6h-6.8a2 2 0 0 1-2-1.6L9 19z" fill="var(--color-terracotta)" />
      <rect x="8" y="17.4" width="16" height="2.2" rx="1.1" fill="var(--color-terracotta)" />
    </svg>
  );
}

function GoogleGlyph() {
  return (
    <svg viewBox="0 0 24 24" className="size-4" aria-hidden>
      <path fill="#4285F4" d="M22.5 12.2c0-.7-.06-1.4-.18-2.06H12v3.9h5.9a5.05 5.05 0 0 1-2.19 3.32v2.76h3.54c2.07-1.9 3.25-4.72 3.25-7.92z" />
      <path fill="#34A853" d="M12 23c2.95 0 5.43-.98 7.25-2.64l-3.54-2.76c-.98.66-2.24 1.05-3.71 1.05-2.85 0-5.27-1.92-6.13-4.51H2.2v2.85A11 11 0 0 0 12 23z" />
      <path fill="#FBBC05" d="M5.87 14.14a6.6 6.6 0 0 1 0-4.28V7.01H2.2a11 11 0 0 0 0 9.98l3.67-2.85z" />
      <path fill="#EA4335" d="M12 5.5c1.61 0 3.05.55 4.19 1.64l3.13-3.13C17.43 2.22 14.95 1.25 12 1.25A11 11 0 0 0 2.2 7.01l3.67 2.85C6.73 7.42 9.15 5.5 12 5.5z" />
    </svg>
  );
}
function FacebookGlyph() {
  return (
    <svg viewBox="0 0 24 24" className="size-4" aria-hidden>
      <path fill="#1877F2" d="M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3 1.79-4.67 4.53-4.67 1.31 0 2.68.24 2.68.24v2.95H15.8c-1.49 0-1.95.93-1.95 1.87V12h3.32l-.53 3.47h-2.79v8.38A12 12 0 0 0 24 12z" />
    </svg>
  );
}
function AppleGlyph() {
  return (
    <svg viewBox="0 0 24 24" className="size-4 text-ink" aria-hidden>
      <path fill="currentColor" d="M17.05 12.54c-.03-2.6 2.12-3.85 2.22-3.91-1.21-1.77-3.1-2.02-3.77-2.05-1.6-.16-3.13.94-3.94.94-.82 0-2.07-.92-3.4-.9-1.75.03-3.37 1.02-4.27 2.58-1.82 3.16-.47 7.84 1.31 10.41.87 1.26 1.9 2.67 3.26 2.62 1.31-.05 1.8-.85 3.39-.85 1.58 0 2.02.85 3.4.82 1.4-.02 2.29-1.28 3.15-2.55.99-1.46 1.4-2.88 1.42-2.95-.03-.01-2.72-1.05-2.75-4.16zM14.6 4.9c.72-.88 1.21-2.09 1.08-3.3-1.04.04-2.3.69-3.05 1.56-.67.77-1.26 2.01-1.1 3.19 1.16.09 2.35-.59 3.07-1.45z" />
    </svg>
  );
}

/* Image washes shared by the desktop panel and the mobile band. */
function HeroImage() {
  return (
    <>
      <div className="absolute inset-0 bg-leaf" aria-hidden />
      <img
        src={HERO_IMAGE}
        alt=""
        className="absolute inset-0 size-full object-cover"
        onError={(e) => { e.currentTarget.style.display = 'none'; }}
      />
      <div className="absolute inset-0 bg-gradient-to-br from-leaf-hover/85 via-leaf/45 to-[#232c0d]/90 mix-blend-multiply" aria-hidden />
      <div className="absolute inset-0 bg-gradient-to-t from-black/55 via-black/10 to-black/30" aria-hidden />
    </>
  );
}

export default function Login() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const toast = useToast();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(e) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await login(email, password);
      navigate('/', { replace: true });
    } catch (err) {
      setError(
        err.response?.data?.message ??
          err.response?.data?.errors?.email?.[0] ??
          'Unable to sign in. Check your details and try again.',
      );
    } finally {
      setSubmitting(false);
    }
  }

  const sso = () => toast.info('Single sign-on isn\u2019t set up yet \u2014 use your email and password.');

  return (
    <div className="login-shell grid min-h-screen bg-surface lg:grid-cols-[1.08fr_1fr]">
      {/* ── Full-bleed image side (desktop) ─────────────────────── */}
      <aside className="relative hidden overflow-hidden p-12 text-white lg:flex lg:flex-col lg:justify-between">
        <HeroImage />

        <div className="relative flex items-center gap-2.5">
          <span className="flex size-10 items-center justify-center rounded-xl bg-white/15 backdrop-blur-sm">
            <PotLeafMark />
          </span>
          <div className="leading-tight">
            <div className="font-semibold">Pot &amp; Leaf ERP</div>
            <div className="text-[11px] text-white/75">Cheerakuzhy Group of Nurseries</div>
          </div>
        </div>

        <div className="relative max-w-md">
          <p className="login-script text-[40px] leading-none text-white/95">grow good things</p>
          <h1 className="mt-4 text-[34px] font-semibold leading-[1.15]">
            From seedling to storefront, every pot accounted for.
          </h1>

          {/* glass value card — one signature element */}
          <div className="mt-8 inline-flex flex-col gap-2 rounded-2xl border border-white/20 bg-white/10 p-5 backdrop-blur-md">
            <span className="text-xs font-medium uppercase tracking-wider text-white/70">One platform</span>
            <div className="flex flex-wrap gap-x-2 gap-y-1 text-sm font-medium text-white/95">
              <span>Procurement</span><span className="text-white/40">·</span>
              <span>Production</span><span className="text-white/40">·</span>
              <span>POS</span><span className="text-white/40">·</span>
              <span>Rentals</span><span className="text-white/40">·</span>
              <span>Reporting</span>
            </div>
          </div>
        </div>

        <div className="relative flex items-center gap-2 text-[11px] text-white/70">
          <span className="inline-block size-1.5 rounded-full bg-terracotta" />
          Mannarkkad · Kerala
        </div>
      </aside>

      {/* ── Form side ───────────────────────────────────────────── */}
      <main className="login-main relative flex flex-col">
        {/* mobile image band (image side is hidden on small screens) */}
        <div className="relative flex h-40 items-end overflow-hidden p-6 text-white lg:hidden">
          <HeroImage />
          <div className="relative flex items-center gap-2.5">
            <span className="flex size-9 items-center justify-center rounded-xl bg-white/15 backdrop-blur-sm">
              <PotLeafMark className="size-7" />
            </span>
            <div className="leading-tight">
              <div className="text-sm font-semibold">Pot &amp; Leaf ERP</div>
              <div className="text-[10px] text-white/75">Cheerakuzhy Group of Nurseries</div>
            </div>
          </div>
        </div>

        {/* centered form */}
        <div className="flex flex-1 items-center justify-center px-6 py-10 sm:px-10 lg:px-16">
          <div className="pl-rise w-full max-w-[430px]">
            <div className="login-form-card">
            <h2 className="text-[32px] font-semibold leading-tight text-[#6d532f]">Welcome back</h2>
            <p className="mt-2 text-sm text-muted">Sign in to your workspace to continue.</p>
            {error && (
              <div className="mt-6 rounded-xl border border-danger/30 bg-danger-soft px-3 py-2 text-sm text-danger">
                {error}
              </div>
            )}

            <form onSubmit={onSubmit} className="mt-6 space-y-4">
              <label className="block space-y-1.5">
                <span className="text-xs font-medium text-muted">Email <span className="text-danger">*</span></span>
                <div className="relative">
                  <EnvelopeIcon className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-faint" />
                  <input
                    type="email"
                    autoComplete="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="you@company.com"
                    className="login-input h-12 w-full rounded-full border border-[#ded5c0] bg-[#f4efdf] pl-11 pr-3 text-sm placeholder:text-[#8b8068] focus:border-leaf/40 focus:outline-none focus:ring-2 focus:ring-leaf/25"
                  />
                </div>
              </label>

              <label className="block space-y-1.5">
                <span className="text-xs font-medium text-muted">Password <span className="text-danger">*</span></span>
                <div className="relative">
                  <LockClosedIcon className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-faint" />
                  <input
                    type={showPassword ? 'text' : 'password'}
                    autoComplete="current-password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="••••••••"
                    className="login-input h-12 w-full rounded-full border border-[#ded5c0] bg-[#f4efdf] pl-11 pr-11 text-sm placeholder:text-[#8b8068] focus:border-leaf/40 focus:outline-none focus:ring-2 focus:ring-leaf/25"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((v) => !v)}
                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                    className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-faint hover:text-muted"
                  >
                    {showPassword ? <EyeSlashIcon className="size-4" /> : <EyeIcon className="size-4" />}
                  </button>
                </div>
              </label>

              <button
                type="submit"
                disabled={submitting}
                className="login-submit group flex h-12 w-full items-center justify-center gap-2 rounded-full bg-leaf font-medium text-white transition-all hover:bg-leaf-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-leaf/40 disabled:opacity-60"
              >
                {submitting ? <Spinner className="border-white/40 border-t-white" /> : (
                  <>Sign in <ArrowRightIcon className="size-4 transition-transform group-hover:translate-x-0.5" /></>
                )}
              </button>
            </form>
            {/* <button type="button" onClick={() => toast.info('Password reset is not set up yet.')} className="mt-3 block w-full text-right text-xs font-medium text-[#59602e] hover:text-leaf-hover">
              Forgot password?
            </button> */}

            </div>
            {/* <button type="button" onClick={sso} className="mx-auto mt-5 flex items-center gap-2 text-sm text-[#4d4a35] hover:text-ink">
              <GoogleGlyph />
              Sign in with Google or Use SSO
            </button> */}
            <div className="mt-7 flex flex-col items-center gap-3 text-xs text-[#857e6d]">
              <span className="login-leaf-mark" aria-hidden />
              <span className="inline-flex items-center gap-1.5 rounded-full bg-white/60 px-3 py-1.5 shadow-sm">
                <MapPinIcon className="size-3.5" /> Mannarkkad - Kerala
              </span>
            </div>
          </div>
        </div>
      </main>
    </div>
  );
}