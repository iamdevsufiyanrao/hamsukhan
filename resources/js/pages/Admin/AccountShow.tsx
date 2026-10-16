import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CallRow from '@/components/CallRow';
import Icon from '@/components/Icon';
import { FieldError, Modal } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { fmtDate, fmtDateLong, fmtTime, timeAgo, usd } from '@/lib/format';
import type { CallRowData } from '@/types';

export type AdminActionRow = { id: number; action: string; admin: string | null; target: string | null; targetId: number | null; meta: Record<string, any> | null; at: string };

export function ActionLabel({ a }: { a: AdminActionRow }) {
    const m = a.meta ?? {};
    switch (a.action) {
        case 'impersonate':
            return <>logged in as</>;
        case 'suspend':
            return <>suspended{m.reason ? ` (“${m.reason}”)` : ''}</>;
        case 'activate':
            return <>re-activated</>;
        case 'adjust_balance':
            return (
                <>
                    adjusted balance by <b>{(m.amount > 0 ? '+' : '') + usd(m.amount)}</b> ({m.reason}) for
                </>
            );
        case 'approve_topup':
            return <>approved a {usd(m.amount ?? 0)} top-up ({m.reference}) for</>;
        case 'reject_topup':
            return <>rejected a top-up for</>;
        default:
            return <>{a.action} ·</>;
    }
}

type Props = {
    account: { id: number; name: string; email: string; timezone: string; balance: number; suspended: boolean; suspendedReason: string | null; suspendedAt: string | null; createdAt: string };
    bot: { business: string | null; website: string | null; language: string; voice: string; installed: boolean; calls: number; bookings: number; integrations: number; vapiAssistantId: string | null } | null;
    transactions: { id: number; date: string; text: string; type: string; amount: number; balanceAfter: number }[];
    calls: CallRowData[];
    actions: AdminActionRow[];
};

export default function AccountShow({ account, bot, transactions, calls, actions }: Props) {
    const [suspendOpen, setSuspendOpen] = useState(false);
    const [adjustOpen, setAdjustOpen] = useState(false);
    const suspend = useForm({ reason: '' });
    const adjust = useForm({ amount: '', reason: '' });

    return (
        <>
            <Head title={account.name} />
            <Link href="/admin/accounts" className="back-link" style={{ marginTop: 0, marginBottom: 14 }}>
                <Icon name="chev" className="i i-sm" />
                All accounts
            </Link>

            <div className="acct-head">
                <div>
                    <h2>
                        {account.name} <span className={'chip ' + (account.suspended ? 'bad' : 'ok')}>{account.suspended ? 'Suspended' : 'Active'}</span>
                    </h2>
                    <p>
                        {account.email} · joined {fmtDateLong(account.createdAt)} · {account.timezone}
                    </p>
                </div>
                <div className="grow" />
                <button
                    className="btn btn-ink"
                    onClick={() => confirm(`Log in as ${account.name}? Everything you do will happen on their account until you return.`) && router.post(`/admin/accounts/${account.id}/impersonate`)}
                >
                    <Icon name="login" />
                    Login as {account.name.split(' ')[0]}
                </button>
                <button className="btn btn-ghost" onClick={() => setAdjustOpen(true)}>
                    <Icon name="wallet" />
                    Adjust balance
                </button>
                {account.suspended ? (
                    <button className="btn btn-primary" onClick={() => router.post(`/admin/accounts/${account.id}/activate`, {}, { preserveScroll: true })}>
                        Re-activate
                    </button>
                ) : (
                    <button className="btn btn-danger" onClick={() => setSuspendOpen(true)}>
                        <Icon name="ban" />
                        Suspend
                    </button>
                )}
            </div>

            {account.suspended && (
                <div className="alert warn">
                    <Icon name="ban" />
                    <span className="grow">
                        Suspended {account.suspendedAt ? timeAgo(account.suspendedAt) : ''}: “{account.suspendedReason}”. They can't log in and their bot is offline.
                    </span>
                </div>
            )}

            <div className="kv-grid" style={{ marginBottom: 16 }}>
                <div>
                    <small>Balance</small>
                    <strong className="num">{usd(account.balance)}</strong>
                </div>
                <div>
                    <small>Business</small>
                    <strong>{bot?.business ?? '-'}</strong>
                </div>
                <div>
                    <small>Website</small>
                    <strong>{bot?.website ?? '-'}</strong>
                </div>
                <div>
                    <small>Widget installed</small>
                    <strong>{bot?.installed ? 'Yes' : 'No'}</strong>
                </div>
                <div>
                    <small>Voice · language</small>
                    <strong>
                        {bot?.voice ?? '-'} · {bot?.language ?? '-'}
                    </strong>
                </div>
                <div>
                    <small>Calls · bookings</small>
                    <strong className="num">
                        {bot?.calls ?? 0} · {bot?.bookings ?? 0}
                    </strong>
                </div>
                <div>
                    <small>Connectors</small>
                    <strong className="num">{bot?.integrations ?? 0}</strong>
                </div>
                <div>
                    <small>Vapi assistant</small>
                    <strong className="mono" style={{ fontSize: 12 }}>
                        {bot?.vapiAssistantId ?? '-'}
                    </strong>
                </div>
            </div>

            <div className="two-col">
                <div className="stack">
                    <div className="card">
                        <div className="card-h">
                            <div>
                                <h3>Recent calls</h3>
                            </div>
                        </div>
                        <div className="tbl-wrap" style={{ marginTop: 14 }}>
                            <table className="tbl">
                                <tbody>
                                    {calls.length === 0 && (
                                        <tr style={{ cursor: 'default' }}>
                                            <td>
                                                <div className="empty">No calls.</div>
                                            </td>
                                        </tr>
                                    )}
                                    {calls.map((c) => (
                                        <CallRow key={c.id} call={c} onOpen={() => {}} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div className="card">
                        <div className="card-h">
                            <div>
                                <h3>Wallet ledger</h3>
                                <p>Latest 15 balance changes</p>
                            </div>
                        </div>
                        <div className="tbl-wrap" style={{ marginTop: 14 }}>
                            <table className="tbl">
                                <tbody>
                                    {transactions.map((t) => (
                                        <tr key={t.id} style={{ cursor: 'default' }}>
                                            <td className="t-time">
                                                {fmtDate(t.date)}
                                                <span>{fmtTime(t.date)}</span>
                                            </td>
                                            <td className="t-script">{t.text}</td>
                                            <td className={'r num ' + (t.amount > 0 ? 'amt-pos' : 'amt-neg')}>
                                                {t.amount > 0 ? '+' : '−'}${Math.abs(t.amount).toFixed(t.amount > 0 ? 2 : 4)}
                                            </td>
                                            <td className="r num" style={{ color: 'var(--muted)' }}>
                                                {usd(t.balanceAfter)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div className="card">
                    <div className="card-h">
                        <div>
                            <h3>Admin activity on this account</h3>
                        </div>
                    </div>
                    <div className="card-b timeline">
                        {actions.length === 0 && <div className="empty">Nothing yet.</div>}
                        {actions.map((a) => (
                            <div key={a.id}>
                                <b>{a.admin ?? 'Admin'}</b> <ActionLabel a={a} /> this account
                                <span>
                                    {fmtDateLong(a.at)}, {fmtTime(a.at)}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            <Modal
                open={suspendOpen}
                onClose={() => setSuspendOpen(false)}
                title={`Suspend ${account.name}?`}
                desc="They're signed out, can't log in, and their bot stops taking calls. You can re-activate any time."
                footer={
                    <>
                        <button className="btn btn-ghost" onClick={() => setSuspendOpen(false)}>
                            Cancel
                        </button>
                        <button className="btn btn-danger" disabled={!suspend.data.reason.trim() || suspend.processing} onClick={() => suspend.post(`/admin/accounts/${account.id}/suspend`, { preserveScroll: true, onSuccess: () => setSuspendOpen(false) })}>
                            Suspend account
                        </button>
                    </>
                }
            >
                <div className="field">
                    <label htmlFor="reason">Reason (kept in the audit log)</label>
                    <input id="reason" className="input" placeholder="e.g. Chargeback / abuse report" value={suspend.data.reason} onChange={(e) => suspend.setData('reason', e.target.value)} />
                    <FieldError msg={suspend.errors.reason} />
                </div>
            </Modal>

            <Modal
                open={adjustOpen}
                onClose={() => setAdjustOpen(false)}
                title="Adjust balance"
                desc={`Current balance ${usd(account.balance)}. Use a negative amount to deduct. Recorded in their ledger and the audit log.`}
                footer={
                    <>
                        <button className="btn btn-ghost" onClick={() => setAdjustOpen(false)}>
                            Cancel
                        </button>
                        <button
                            className="btn btn-primary"
                            disabled={!adjust.data.amount || !adjust.data.reason.trim() || adjust.processing}
                            onClick={() =>
                                adjust.post(`/admin/accounts/${account.id}/balance`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setAdjustOpen(false);
                                        adjust.reset();
                                    },
                                })
                            }
                        >
                            Apply
                        </button>
                    </>
                }
            >
                <div className="stack">
                    <div className="field">
                        <label htmlFor="adj-amt">Amount (USD)</label>
                        <input id="adj-amt" className="input num" type="number" step="0.01" placeholder="e.g. 5 or -2.50" value={adjust.data.amount} onChange={(e) => adjust.setData('amount', e.target.value)} />
                        <FieldError msg={adjust.errors.amount} />
                    </div>
                    <div className="field">
                        <label htmlFor="adj-reason">Reason</label>
                        <input id="adj-reason" className="input" placeholder="e.g. Refund for failed calls on 12 Oct" value={adjust.data.reason} onChange={(e) => adjust.setData('reason', e.target.value)} />
                        <FieldError msg={adjust.errors.reason} />
                    </div>
                </div>
            </Modal>
        </>
    );
}

AccountShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
