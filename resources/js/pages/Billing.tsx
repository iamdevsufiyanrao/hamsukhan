import { Head, usePage } from '@inertiajs/react';
import Icon from '@/components/Icon';
import { useUI } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { fmtDate, fmtTime, pkr, usd } from '@/lib/format';
import type { SharedProps } from '@/types';

type Props = {
    transactions: { id: number; date: string; text: string; type: string; amount: number }[];
    topUps: { id: number; amountUsd: number; amountPkr: number; method: string; reference: string; status: 'pending' | 'approved' | 'rejected'; note: string | null; createdAt: string }[];
    pricing: { ratePerMinute: number; minTopUp: number; pkrPerUsd: number; lowBalance: number; maxVoices: number };
};

const TYPE_LABEL: Record<string, string> = { topup: 'Top-up', call_charge: 'Call charge', refund: 'Refund', adjustment: 'Adjustment' };

export default function Billing({ transactions, topUps, pricing }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const { openModal } = useUI();
    const [whole, cents] = (auth?.wallet.balance ?? 0).toFixed(2).split('.');

    return (
        <>
            <Head title="Billing" />
            <div className="bill-grid" style={{ marginBottom: 16 }}>
                <div className="card big-bal">
                    <small>Available balance</small>
                    <div className="v num">
                        ${whole}
                        <span>.{cents}</span>
                    </div>
                    <p>About {auth?.wallet.minutesLeft} minutes of calls at your current rate.</p>
                    <button className="btn" onClick={() => openModal('topup')}>
                        <Icon name="plus" />
                        Add funds
                    </button>
                </div>
                <div className="card">
                    <div className="card-h">
                        <div>
                            <h3>Pricing</h3>
                            <p>Pay only for the minutes your bot talks.</p>
                        </div>
                    </div>
                    <div className="card-b">
                        <div className="rate">
                            <span>Per minute</span>
                            <b className="num">
                                {usd(pricing.ratePerMinute)} · {pkr(pricing.ratePerMinute * pricing.pkrPerUsd)}
                            </b>
                        </div>
                        <div className="rate">
                            <span>Billing increment</span>
                            <b>Per second</b>
                        </div>
                        <div className="rate">
                            <span>Payment methods</span>
                            <b>JazzCash · Easypaisa · Bank transfer</b>
                        </div>
                        <div className="rate">
                            <span>Voice cloning</span>
                            <b>Free · up to {pricing.maxVoices} voices</b>
                        </div>
                        <div className="rate">
                            <span>Low balance alert</span>
                            <b>Below {usd(pricing.lowBalance)}</b>
                        </div>
                    </div>
                </div>
            </div>

            {topUps.length > 0 && (
                <div className="card" style={{ marginBottom: 16 }}>
                    <div className="card-h">
                        <div>
                            <h3>Your payments</h3>
                            <p>Top-ups you submitted and their review status</p>
                        </div>
                    </div>
                    <div className="tbl-wrap" style={{ marginTop: 14 }}>
                        <table className="tbl">
                            <thead>
                                <tr>
                                    <th>Submitted</th>
                                    <th>Method</th>
                                    <th>Transaction ID</th>
                                    <th>Status</th>
                                    <th className="r">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {topUps.map((t) => (
                                    <tr key={t.id} style={{ cursor: 'default' }}>
                                        <td className="t-time">
                                            {fmtDate(t.createdAt)}
                                            <span>{fmtTime(t.createdAt)}</span>
                                        </td>
                                        <td>{t.method}</td>
                                        <td className="mono">{t.reference}</td>
                                        <td>
                                            <span className={'chip ' + t.status}>{t.status[0].toUpperCase() + t.status.slice(1)}</span>
                                            {t.note && <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 4 }}>{t.note}</div>}
                                        </td>
                                        <td className="r num">
                                            <b>{usd(t.amountUsd)}</b>
                                            <div style={{ fontSize: 12, color: 'var(--muted)' }}>{pkr(t.amountPkr)}</div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <div className="card">
                <div className="card-h">
                    <div>
                        <h3>Transactions</h3>
                        <p>Every change to your balance, latest 50</p>
                    </div>
                </div>
                <div className="tbl-wrap" style={{ marginTop: 14 }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Type</th>
                                <th className="r">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {transactions.length === 0 && (
                                <tr style={{ cursor: 'default' }}>
                                    <td colSpan={4}>
                                        <div className="empty">No transactions yet.</div>
                                    </td>
                                </tr>
                            )}
                            {transactions.map((t) => (
                                <tr key={t.id} style={{ cursor: 'default' }}>
                                    <td className="t-time">
                                        {fmtDate(t.date)}
                                        <span>{fmtTime(t.date)}</span>
                                    </td>
                                    <td className="t-script">{t.text}</td>
                                    <td>{TYPE_LABEL[t.type] ?? t.type}</td>
                                    <td className={'r num ' + (t.amount > 0 ? 'amt-pos' : 'amt-neg')}>
                                        {t.amount > 0 ? '+' : '−'}${Math.abs(t.amount).toFixed(t.amount > 0 ? 2 : 4)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

Billing.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
