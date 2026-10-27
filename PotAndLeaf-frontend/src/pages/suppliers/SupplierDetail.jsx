import { useState } from 'react';
import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { PencilSquareIcon } from '@heroicons/react/24/outline';
import api, { withCompany } from '../../lib/api';
import { useAuth } from '../../context/AuthContext';
import { Badge, Button, Card, Spinner } from '../../components/ui';
import { DetailHeader, Section, InfoGrid, InfoItem, DetailLoading, DetailError } from '../../components/detail';
import { mediaUrl } from '../../components/media';
import { formatCurrency, formatDate } from '../../lib/format';
import Pagination from '../../components/Pagination';
import usePaged from '../../hooks/usePaged';

const tone = { active: 'active', inactive: 'inactive', blocked: 'blocked' };
const payTone = { paid: 'active', partial: 'pending', unpaid: 'blocked', 'n/a': 'default' };

export default function SupplierDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { companyId } = useAuth();
  const headerCompanyId = searchParams.get('company_id') || companyId;
  const [histPage, setHistPage] = useState(1);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['supplier', id, headerCompanyId],
    queryFn: () => api.get(`/suppliers/${id}`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: Boolean(id && headerCompanyId),
  });

  // Payments and advance come back as two separate lists (never mixed).
  const { data: account } = useQuery({
    queryKey: ['supplier-account', id, headerCompanyId],
    queryFn: () => api.get(`/suppliers/${id}/account`, withCompany(headerCompanyId)).then((r) => r.data.data),
    enabled: Boolean(id && headerCompanyId),
  });

  const { data: history, isLoading: histLoading } = useQuery({
    queryKey: ['supplier-purchase-history', id, headerCompanyId, histPage],
    queryFn: () => api
      .get(`/suppliers/${id}/purchase-history`, { params: { page: histPage, per_page: 10 }, ...withCompany(headerCompanyId) })
      .then((r) => r.data),
    enabled: Boolean(id && headerCompanyId),
    placeholderData: keepPreviousData,
  });

  const payPage = usePaged(account?.payments ?? [], 10);
  const advPage = usePaged(account?.advance ?? [], 10);

  if (isLoading) return <DetailLoading />;
  if (isError || !data) return <DetailError backTo="/suppliers" />;
  const s = data;
  const rows = history?.data ?? [];
  const meta = history?.meta;

  return (
    <div className="space-y-5 p-4 sm:p-6">
      <DetailHeader
        title={s.name}
        subtitle={`Supplier · ${s.supplier_code}`}
        backTo="/suppliers"
        actions={
          <>
            <Badge tone={tone[s.status] ?? 'default'}>{s.status}</Badge>
            {/* {s.can?.update && <Button variant="outline" size="sm" onClick={() => navigate('/suppliers')}><PencilSquareIcon className="size-4" /> Edit</Button>} */}
          </>
        }
      />

      {account && (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <MoneyTile label="You owe this supplier" value={account.summary.you_owe} hint="Unpaid bills" tone={account.summary.you_owe > 0 ? 'warn' : 'ok'} />
          <MoneyTile label="Advance with this supplier" value={account.summary.advance_available} hint="Can be used on future bills" tone="leaf" />
          <MoneyTile label="Paid so far" value={account.summary.paid_total} hint={`${account.summary.payment_count} payment${account.summary.payment_count === 1 ? '' : 's'}`} />
        </div>
      )}

      {s.photo && (
        <div className="size-24 overflow-hidden rounded-2xl border border-line">
          <img src={mediaUrl(s.photo)} alt="" className="size-full object-cover" />
        </div>
      )}

      <Section title="Contact">
        <InfoGrid cols={3}>
          <InfoItem label="Email" value={s.email} />
          <InfoItem label="Phone" value={s.phone} mono />
          <InfoItem label="Supplier code" value={s.supplier_code} mono />
        </InfoGrid>
      </Section>

      <Section title="Tax & address">
        <InfoGrid cols={3}>
          <InfoItem label="GST number" value={s.gst_number} mono />
          <InfoItem label="PAN" value={s.pan_number} mono />
          <InfoItem label="Address" value={s.address || [s.address_line1, s.city, s.state, s.pincode].filter(Boolean).join(', ')} />
          <InfoItem label="City" value={s.city} />
          <InfoItem label="State" value={s.state} />
          <InfoItem label="Country" value={s.country} />
        </InfoGrid>
      </Section>

      <Section title="Banking">
        <InfoGrid cols={3}>
          <InfoItem label="Account name" value={s.bank_account_name} />
          <InfoItem label="Bank" value={s.bank_name} />
          <InfoItem label="Account no." value={s.bank_account_no} mono />
          <InfoItem label="IFSC" value={s.bank_ifsc} mono />
        </InfoGrid>
      </Section>

      <Section title="Credit terms">
        <InfoGrid cols={3}>
          <InfoItem label="Credit limit" value={s.credit_limit != null ? formatCurrency(s.credit_limit) : null} mono />
          <InfoItem label="Credit days" value={s.credit_days != null ? `${s.credit_days} days` : null} mono />
          <InfoItem label="Opening balance" value={s.opening_balance != null ? formatCurrency(s.opening_balance) : null} mono />
        </InfoGrid>
        {s.notes && <div className="mt-4 border-t border-line pt-4"><InfoItem label="Notes" value={s.notes} /></div>}
      </Section>


      <Section title="Payments made" actions={<Button size="sm" variant="outline" onClick={() => navigate('/payments')}>Pay supplier</Button>}>
        <p className="-mt-1 mb-3 text-xs text-muted">Money you paid to this supplier, and which bills each payment cleared.</p>
        <Card className="overflow-hidden">
          {!account ? (
            <div className="flex justify-center py-8"><Spinner className="size-5" /></div>
          ) : account.payments.length === 0 ? (
            <div className="px-4 py-8 text-center text-sm text-muted">No payments made to this supplier yet.</div>
          ) : (
            <><ul className="divide-y divide-line/60">
              {payPage.pageRows.map((p) => (
                <li key={p.id} className="flex items-start justify-between gap-3 px-4 py-3">
                  <div className="min-w-0 space-y-1.5">
                    <p className="text-sm">
                      <span className="font-medium">{p.payment_no}</span>
                      <span className="text-muted"> · {formatDate(p.date)} · <span className="capitalize">{p.mode}</span>{p.reference ? ` · Ref ${p.reference}` : ''}</span>
                    </p>
                    <div className="flex flex-wrap gap-1.5">
                      {p.bills.map((b) => (
                        <button key={b.purchase_id} type="button" onClick={() => navigate(`/purchases/${b.purchase_id}`)}
                          className="rounded-full bg-paper px-2 py-0.5 text-xs text-ink ring-1 ring-line hover:text-leaf">
                          Paid bill {b.purchase_no} · {formatCurrency(b.amount)}
                        </button>
                      ))}
                      {p.to_advance > 0 && (
                        <span className="rounded-full bg-leaf-soft px-2 py-0.5 text-xs text-leaf-hover">
                          {p.is_advance ? 'Paid as advance' : 'Extra kept as advance'} · {formatCurrency(p.to_advance)}
                        </span>
                      )}
                      {p.bills.length === 0 && p.to_advance <= 0 && <span className="text-xs text-muted">Against what was owed</span>}
                    </div>
                  </div>
                  <p className="tnum shrink-0 text-sm font-semibold">{formatCurrency(p.amount)}</p>
                </li>
              ))}
            </ul>
            <div className="border-t border-line px-3"><Pagination meta={payPage.meta} onPage={payPage.setPage} /></div>
            </>
          )}
        </Card>
      </Section>

      <Section title="Advance" actions={account?.summary.advance_available > 0 ? <Button size="sm" variant="outline" onClick={() => navigate('/payments')}>Use advance on a bill</Button> : null}>
        <p className="-mt-1 mb-3 text-xs text-muted">Money held with this supplier: where it came from, which bills it was used on, and what is left.</p>
        <Card className="overflow-hidden">
          {!account ? (
            <div className="flex justify-center py-8"><Spinner className="size-5" /></div>
          ) : account.advance.length === 0 ? (
            <div className="px-4 py-8 text-center text-sm text-muted">No advance with this supplier.</div>
          ) : (
            <><ul className="divide-y divide-line/60">
              {advPage.pageRows.map((m, i) => (
                <li key={i} className="flex items-start justify-between gap-3 px-4 py-3">
                  <div className="min-w-0">
                    <p className="text-sm font-medium">
                      {m.purchase_id
                        ? <button type="button" onClick={() => navigate(`/purchases/${m.purchase_id}`)} className="text-left hover:text-leaf">{m.label}</button>
                        : m.label}
                    </p>
                    <p className="text-xs text-muted">{[m.date ? formatDate(m.date) : null, m.reference].filter(Boolean).join(' · ') || '—'}</p>
                  </div>
                  <div className="shrink-0 text-right">
                    <p className={`tnum text-sm font-semibold ${m.in > 0 ? 'text-leaf-hover' : 'text-danger'}`}>
                      {m.in > 0 ? `+ ${formatCurrency(m.in)}` : `− ${formatCurrency(m.out)}`}
                    </p>
                    <p className="tnum text-xs text-muted">Left {formatCurrency(m.balance)}</p>
                  </div>
                </li>
              ))}
            </ul>
            <div className="border-t border-line px-3"><Pagination meta={advPage.meta} onPage={advPage.setPage} /></div>
            </>
          )}
        </Card>
      </Section>

      <Section title="Purchase history">
        <Card className="overflow-hidden">
          {histLoading ? (
            <div className="flex justify-center py-10"><Spinner className="size-5" /></div>
          ) : rows.length === 0 ? (
            <div className="px-4 py-10 text-center text-sm text-muted">No purchases from this supplier yet.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-line text-left text-faint">
                    <th className="microlabel px-4 py-2.5 font-semibold">Date</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Purchase #</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Invoice</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Items</th>
                    <th className="microlabel px-4 py-2.5 text-right font-semibold">Total</th>
                    <th className="microlabel px-4 py-2.5 font-semibold">Payment</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((p) => (
                    <tr key={p.id} className="border-b border-line/60 last:border-0 hover:bg-paper/60">
                      <td className="tnum px-4 py-2.5 text-xs text-muted">{p.purchase_date || '—'}</td>
                      <td className="px-4 py-2.5">
                        <button onClick={() => navigate(`/purchases/${p.id}`)} className="font-medium text-ink hover:text-leaf">{p.purchase_no}</button>
                      </td>
                      <td className="tnum px-4 py-2.5 text-xs text-muted">{p.invoice_no || '—'}</td>
                      <td className="tnum px-4 py-2.5 text-right text-muted">{p.items_count ?? '—'}</td>
                      <td className="tnum px-4 py-2.5 text-right">{formatCurrency(p.grand_total)}</td>
                      <td className="px-4 py-2.5"><Badge tone={payTone[p.payment_status] ?? 'default'}>{p.payment_status}</Badge></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <div className="border-t border-line px-3"><Pagination meta={meta} onPage={setHistPage} /></div>
        </Card>
      </Section>
    </div>
  );
}

function MoneyTile({ label, value, hint, tone }) {
  const color = tone === 'warn' ? 'text-amber' : tone === 'leaf' ? 'text-leaf-hover' : 'text-ink';
  return (
    <Card className="p-4">
      <p className="microlabel text-faint">{label}</p>
      <p className={`tnum mt-1 text-2xl font-semibold ${color}`}>{formatCurrency(value ?? 0)}</p>
      {hint && <p className="mt-0.5 text-xs text-muted">{hint}</p>}
    </Card>
  );
}
