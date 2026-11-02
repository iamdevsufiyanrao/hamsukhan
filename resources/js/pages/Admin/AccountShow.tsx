import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import CallRow from '@/components/CallRow';
import Icon from '@/components/Icon';
import { FieldError, Modal } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { fmtDate, fmtDateLong, fmtTime, pkr, timeAgo, usd } from '@/lib/format';
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
        case 'set_plan':
            return m.plan === 'none' ? (
                <>ended the plan ({m.reason}) for</>
            ) : (
                <>
                    set the <b>{planLabel(m.plan)}</b> plan for {m.months} month{m.months > 1 ? 's' : ''} ({m.reason}) for
                </>
            );
        case 'approve_payment':
            return (
                <>
                    approved a {planLabel(m.plan)} payment · {m.months} month{m.months > 1 ? 's' : ''} · {pkr(m.amountPkr ?? 0)} ({m.reference}) for
                </>
            );
        case 'reject_payment':
            return <>rejected a payment for</>;
        default:
            return <>{a.action} ·</>;
    }
}

const planLabel = (p: string | null | undefined) => (p ? p.charAt(0).toUpperCase() + p.slice(1) : '-');

type Props = {
    account: { id: number; name: string; email: string; timezone: string; plan: string | null; lastPlan: string | null; planExpiresAt: string | null; suspended: boolean; suspendedReason: string | null; suspendedAt: string | null; createdAt: string };
    bot: { business: string | null; website: string | null; language: string; voice: string; installed: boolean; calls: number; bookings: number; integrations: number; vapiAssistantId: string | null } | null;
    stores: { id: number; name: string; website: string | null; calls: number; active: boolean }[];
    usage: { minutesUsed: number; fairUseMinutes: number | null; stores: number; users: number };
    plans: { id: string; name: string }[];
    payments: { id: number; date: string; plan: string; months: number; amountPkr: number; status: string }[];
    calls: CallRowData[];
    actions: AdminActionRow[];
};

export default function AccountShow({ account, bot, stores, usage, plans, payments, calls, actions }: Props) {
    const [suspendOpen, setSuspendOpen] = useState(false);
    const [planOpen, setPlanOpen] = useState(false);
    const suspend = useForm({ reason: '' });
    const planForm = useForm({ plan: account.plan ?? plans[0]?.id ?? 'basic', months: '1', reason: '' });

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
                <button className="btn btn-ghost" onClick={() => setPlanOpen(true)}>
                    <Icon name="wallet" />
                    Set plan
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
                    <small>Plan</small>
                    <strong>
                        {account.plan ? planLabel(account.plan) : account.lastPlan ? `${planLabel(account.lastPlan)} (expired)` : 'No plan'}
                        {account.planExpiresAt && <span style={{ display: 'block', fontSize: 12, fontWeight: 600, color: 'var(--muted)' }}>{account.plan ? 'until' : 'ended'} {fmtDateLong(account.planExpiresAt)}</span>}
                    </strong>
                </div>
                <div>
                    <small>Minutes this month</small>
                    <strong className="num">
                        {usage.minutesUsed}
                        {usage.fairUseMinutes ? ` / ${usage.fairUseMinutes} fair use` : ' · no limit'}
                    </strong>
                </div>
                <div>
                    <small>Stores · users allowed</small>
                    <strong className="num">
                        {stores.length} of {usage.stores} · {usage.users}
                    </strong>
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
                                <h3>Stores</h3>
                                <p>The first {usage.stores} take calls on the current plan</p>
                            </div>
                        </div>
                        <div className="tbl-wrap" style={{ marginTop: 14 }}>
                            <table className="tbl">
                                <tbody>
                                    {stores.map((st, i) => (
                                        <tr key={st.id} style={{ cursor: 'default' }}>
                                            <td className="bk-who">
                                                <b>{st.name}</b>
                                                <span>{st.website ?? 'no website yet'}</span>
                                            </td>
                                            <td className="num">{st.calls} calls</td>
                                            <td className="r">
                                                <span className={'chip ' + (st.active && i < usage.stores ? 'ok' : 'bad')}>{!st.active ? 'Off' : i < usage.stores ? 'On' : 'Over plan limit'}</span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div className="card">
                        <div className="card-h">
                            <div>
                                <h3>Payments</h3>
                                <p>Latest 15 plan payments</p>
                            </div>
                        </div>
                        <div className="tbl-wrap" style={{ marginTop: 14 }}>
                            <table className="tbl">
                                <tbody>
                                    {payments.length === 0 && (
                                        <tr style={{ cursor: 'default' }}>
                                            <td>
                                                <div className="empty">No payments yet.</div>
                                            </td>
                                        </tr>
                                    )}
                                    {payments.map((t) => (
                                        <tr key={t.id} style={{ cursor: 'default' }}>
                                            <td className="t-time">
                                                {fmtDate(t.date)}
                                                <span>{fmtTime(t.date)}</span>
                                            </td>
                                            <td className="t-script">
                                                {t.plan} · {t.months} month{t.months > 1 ? 's' : ''}
                                            </td>
                                            <td className="r num">{pkr(t.amountPkr)}</td>
                                            <td className="r">
                                                <span className={'chip ' + (t.status === 'approved' ? 'ok' : t.status === 'rejected' ? 'bad' : 'pending')}>{t.status}</span>
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
                open={planOpen}
                onClose={() => setPlanOpen(false)}
                title="Set plan"
                desc={`For cash payments, a free month or a correction. The same plan is extended from its end date; another plan starts today. Recorded in the audit log.`}
                footer={
                    <>
                        <button className="btn btn-ghost" onClick={() => setPlanOpen(false)}>
                            Cancel
                        </button>
                        <button
                            className={'btn ' + (planForm.data.plan === 'none' ? 'btn-danger' : 'btn-primary')}
                            disabled={!planForm.data.reason.trim() || planForm.processing}
                            onClick={() => {
                                planForm.transform((d) => (d.plan === 'none' ? { plan: d.plan, reason: d.reason } : { ...d, months: Number(d.months) }));
                                planForm.post(`/admin/accounts/${account.id}/plan`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setPlanOpen(false);
                                        planForm.reset('reason');
                                    },
                                });
                            }}
                        >
                            {planForm.data.plan === 'none' ? 'End plan now' : 'Apply'}
                        </button>
                    </>
                }
            >
                <div className="stack">
                    <div className="field">
                        <label htmlFor="plan-pick">Plan</label>
                        <select id="plan-pick" className="select" value={planForm.data.plan} onChange={(e) => planForm.setData('plan', e.target.value)}>
                            {plans.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                            <option value="none">No plan (end it now)</option>
                        </select>
                        <FieldError msg={planForm.errors.plan} />
                    </div>
                    {planForm.data.plan !== 'none' && (
                        <div className="field">
                            <label htmlFor="plan-months">Months</label>
                            <select id="plan-months" className="select" value={planForm.data.months} onChange={(e) => planForm.setData('months', e.target.value)}>
                                {[1, 2, 3, 6, 12].map((n) => (
                                    <option key={n} value={String(n)}>
                                        {n} month{n > 1 ? 's' : ''}
                                    </option>
                                ))}
                            </select>
                            <FieldError msg={planForm.errors.months} />
                        </div>
                    )}
                    <div className="field">
                        <label htmlFor="plan-reason">Reason</label>
                        <input id="plan-reason" className="input" placeholder="e.g. Paid cash at the office" value={planForm.data.reason} onChange={(e) => planForm.setData('reason', e.target.value)} />
                        <FieldError msg={planForm.errors.reason} />
                    </div>
                </div>
            </Modal>
        </>
    );
}

AccountShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
