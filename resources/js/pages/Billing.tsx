import { Head } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/Icon';
import { PaymentModal, type Gateway } from '@/components/GlobalModals';
import AppLayout from '@/layouts/AppLayout';
import { fmtDate, fmtTime, pkr } from '@/lib/format';
import type { PaymentAccount, Plan } from '@/types';

type Props = {
    subscription: {
        plan: Plan['id'] | null;
        planName: string | null;
        expiresAt: string | null;
        active: boolean;
        daysLeft: number;
        stores: number;
        users: number;
        minutesUsed: number;
        fairUseMinutes: number | null;
        concurrentCalls: number | null;
        storesUsed: number;
        usersUsed: number;
    };
    plans: Plan[];
    payments: { id: number; plan: string; months: number; amountPkr: number; method: string; reference: string; status: 'pending' | 'approved' | 'rejected'; note: string | null; createdAt: string }[];
    accounts: PaymentAccount[];
    gateways: Gateway[];
    manualPayments: boolean;
    checkoutResult: { status: 'pending' | 'approved' | 'rejected'; admin_note: string | null } | null;
};

const FEATURES = ['Unlimited calls (fair use)', 'Your own cloned voice', 'Bookings, orders and callbacks', 'Call recordings and transcripts', 'Works on any website and Shopify'];

export default function Billing({ subscription: sub, plans, payments, accounts, gateways, manualPayments, checkoutResult }: Props) {
    const [paying, setPaying] = useState<Plan['id'] | null>(null);
    const nearLimit = sub.fairUseMinutes !== null && sub.minutesUsed >= sub.fairUseMinutes * 0.8;

    return (
        <>
            <Head title="Billing" />
            {checkoutResult && (
                <div className={'alert ' + (checkoutResult.status === 'approved' ? 'imp' : 'warn')}>
                    <Icon name={checkoutResult.status === 'approved' ? 'check' : 'alert'} />
                    <span className="grow">
                        {checkoutResult.status === 'approved'
                            ? 'Payment received, your plan is active. Thank you!'
                            : checkoutResult.status === 'rejected'
                              ? `The payment did not go through. ${checkoutResult.admin_note ?? ''}`
                              : 'We are still waiting for the payment to be confirmed. Refresh this page in a minute.'}
                    </span>
                </div>
            )}
            <div className="bill-grid" style={{ marginBottom: 16 }}>
                <div className="card big-bal">
                    <small>Your plan</small>
                    <div className="v">{sub.active ? sub.planName : 'Not active'}</div>
                    <p>
                        {sub.active && sub.expiresAt
                            ? `Paid until ${fmtDate(sub.expiresAt)} (${sub.daysLeft} day${sub.daysLeft === 1 ? '' : 's'} left). Calls are unlimited.`
                            : 'Choose a plan to switch your assistant on. Calls are unlimited on every plan.'}
                    </p>
                    <button className="btn" onClick={() => setPaying(sub.plan ?? 'basic')}>
                        <Icon name="plus" />
                        {sub.active ? 'Renew or upgrade' : 'Choose a plan'}
                    </button>
                </div>
                <div className="card">
                    <div className="card-h">
                        <div>
                            <h3>This month</h3>
                            <p>What your plan covers and what you use</p>
                        </div>
                    </div>
                    <div className="card-b">
                        <div className="rate">
                            <span>Stores</span>
                            <b className="num">
                                {sub.storesUsed} of {sub.stores}
                            </b>
                        </div>
                        <div className="rate">
                            <span>Users</span>
                            <b className="num">
                                {sub.usersUsed} of {sub.users}
                            </b>
                        </div>
                        <div className="rate">
                            <span>Call minutes this month</span>
                            <b className="num" style={nearLimit ? { color: 'var(--warn)' } : undefined}>
                                {sub.minutesUsed} · unlimited
                            </b>
                        </div>
                        {sub.concurrentCalls !== null && (
                            <div className="rate">
                                <span>Calls at the same time</span>
                                <b className="num">up to {sub.concurrentCalls}</b>
                            </div>
                        )}
                        <div className="rate">
                            <span>Payment methods</span>
                            <b>{[...gateways.map((g) => (g === 'jazzcash' ? 'JazzCash' : 'Easypaisa') + ' online'), ...(manualPayments ? ['JazzCash', 'Easypaisa', 'Bank transfer'] : [])].join(' · ')}</b>
                        </div>
                    </div>
                    {nearLimit && (
                        <p className="hint" style={{ marginTop: 10 }}>
                            You are close to this month's fair-use limit ({sub.fairUseMinutes} minutes). Contact support if you need more.
                        </p>
                    )}
                </div>
            </div>

            <div className="grid-2" style={{ marginBottom: 16 }}>
                {plans.map((p) => (
                    <div key={p.id} className="card">
                        <div className="card-h">
                            <div>
                                <h3>
                                    {p.name}
                                    {sub.active && sub.plan === p.id && <span className="chip approved" style={{ marginLeft: 8 }}>Current</span>}
                                </h3>
                                <p>
                                    <b className="num" style={{ fontSize: 22, color: 'var(--ink)' }}>{pkr(p.pricePkr)}</b> / month
                                </p>
                            </div>
                        </div>
                        <div className="card-b">
                            <div className="rate">
                                <span>Stores (websites)</span>
                                <b className="num">{p.stores}</b>
                            </div>
                            <div className="rate">
                                <span>Users who can log in</span>
                                <b className="num">{p.users}</b>
                            </div>
                            {FEATURES.map((f) => (
                                <div key={f} className="rate">
                                    <span>{f}</span>
                                    <b>
                                        <Icon name="check" />
                                    </b>
                                </div>
                            ))}
                            <button className={'btn ' + (sub.plan === p.id || (!sub.plan && p.id === 'basic') ? 'btn-primary' : 'btn-ghost')} style={{ marginTop: 14, width: '100%' }} onClick={() => setPaying(p.id)}>
                                {sub.active && sub.plan === p.id ? 'Renew' : `Get ${p.name}`}
                            </button>
                        </div>
                    </div>
                ))}
            </div>

            <div className="card">
                <div className="card-h">
                    <div>
                        <h3>Your payments</h3>
                        <p>Payments you submitted and their review status</p>
                    </div>
                </div>
                <div className="tbl-wrap" style={{ marginTop: 14 }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Submitted</th>
                                <th>Plan</th>
                                <th>Method</th>
                                <th>Transaction ID</th>
                                <th>Status</th>
                                <th className="r">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {payments.length === 0 && (
                                <tr style={{ cursor: 'default' }}>
                                    <td colSpan={6}>
                                        <div className="empty">No payments yet.</div>
                                    </td>
                                </tr>
                            )}
                            {payments.map((t) => (
                                <tr key={t.id} style={{ cursor: 'default' }}>
                                    <td className="t-time">
                                        {fmtDate(t.createdAt)}
                                        <span>{fmtTime(t.createdAt)}</span>
                                    </td>
                                    <td>
                                        {t.plan} · {t.months} month{t.months > 1 ? 's' : ''}
                                    </td>
                                    <td>{t.method}</td>
                                    <td className="mono">{t.reference}</td>
                                    <td>
                                        <span className={'chip ' + t.status}>{t.status[0].toUpperCase() + t.status.slice(1)}</span>
                                        {t.note && <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4 }}>{t.note}</div>}
                                    </td>
                                    <td className="r num">
                                        <b>{pkr(t.amountPkr)}</b>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <PaymentModal open={paying !== null} onClose={() => setPaying(null)} plans={plans} accounts={accounts} initialPlan={paying ?? 'basic'} gateways={gateways} manual={manualPayments} />
        </>
    );
}

Billing.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
