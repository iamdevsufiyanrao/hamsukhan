import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { FieldError, Modal, Seg } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { fmtDate, fmtTime, pkr, usd } from '@/lib/format';

type Status = 'pending' | 'approved' | 'rejected';
type TopUp = {
    id: number;
    user: { name: string; email: string };
    amountUsd: number;
    amountPkr: number;
    method: string;
    reference: string;
    hasProof: boolean;
    status: Status;
    note: string | null;
    reviewer: string | null;
    createdAt: string;
};
type Props = { filter: Status; topUps: TopUp[]; counts: Partial<Record<Status, number>> };

/** Admin: check the payment in the JazzCash/Easypaisa/bank app, then approve to credit the wallet. */
export default function TopUps({ filter, topUps, counts }: Props) {
    const [rejecting, setRejecting] = useState<TopUp | null>(null);
    const [note, setNote] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState<number | null>(null);

    const approve = (t: TopUp) => {
        if (!confirm(`Approve ${usd(t.amountUsd)} (${pkr(t.amountPkr)}) for ${t.user.name}?\nOnly approve after you see the payment in your ${t.method} account.`)) return;
        router.post(`/admin/top-ups/${t.id}/approve`, {}, { preserveScroll: true, onStart: () => setBusy(t.id), onFinish: () => setBusy(null) });
    };
    const reject = () => {
        if (!rejecting) return;
        router.post(
            `/admin/top-ups/${rejecting.id}/reject`,
            { note },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setRejecting(null);
                    setNote('');
                },
                onError: (e) => setError(e.note ?? 'Could not reject'),
            },
        );
    };

    return (
        <>
            <Head title="Payment approvals" />
            <div className="card">
                <div className="toolbar">
                    <Seg
                        value={filter}
                        onChange={(s) => router.get('/admin/top-ups', { status: s }, { preserveState: true, replace: true })}
                        options={[
                            { value: 'pending', label: `Pending (${counts.pending ?? 0})` },
                            { value: 'approved', label: `Approved (${counts.approved ?? 0})` },
                            { value: 'rejected', label: `Rejected (${counts.rejected ?? 0})` },
                        ]}
                    />
                    <div style={{ flex: 1 }} />
                    <span style={{ fontSize: 12.5, color: 'var(--muted)', fontWeight: 600 }}>Verify the transaction ID in your payment app before approving</span>
                </div>
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Submitted</th>
                                <th>Customer</th>
                                <th>Method · Transaction ID</th>
                                <th>Proof</th>
                                <th className="r">Amount</th>
                                <th className="r">{filter === 'pending' ? 'Action' : 'Reviewed by'}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {topUps.length === 0 && (
                                <tr style={{ cursor: 'default' }}>
                                    <td colSpan={6}>
                                        <div className="empty">{filter === 'pending' ? 'No payments waiting, all caught up.' : 'Nothing here.'}</div>
                                    </td>
                                </tr>
                            )}
                            {topUps.map((t) => (
                                <tr key={t.id} style={{ cursor: 'default' }}>
                                    <td className="t-time">
                                        {fmtDate(t.createdAt)}
                                        <span>{fmtTime(t.createdAt)}</span>
                                    </td>
                                    <td className="bk-who">
                                        <b>{t.user.name}</b>
                                        <span>{t.user.email}</span>
                                    </td>
                                    <td>
                                        {t.method}
                                        <div className="mono" style={{ fontSize: 12.5, color: 'var(--text-2)' }}>
                                            {t.reference}
                                        </div>
                                    </td>
                                    <td>
                                        {t.hasProof ? (
                                            <a href={`/admin/top-ups/${t.id}/proof`} target="_blank" rel="noreferrer">
                                                <img className="proof-thumb" src={`/admin/top-ups/${t.id}/proof`} alt="Payment screenshot" />
                                            </a>
                                        ) : (
                                            '-'
                                        )}
                                    </td>
                                    <td className="r num">
                                        <b>{usd(t.amountUsd)}</b>
                                        <div style={{ fontSize: 12, color: 'var(--muted)' }}>{pkr(t.amountPkr)}</div>
                                    </td>
                                    <td className="r">
                                        {t.status === 'pending' ? (
                                            <div className="row-acts">
                                                <button className="btn btn-ghost btn-sm" onClick={() => setRejecting(t)}>
                                                    Reject
                                                </button>
                                                <button className="btn btn-primary btn-sm" disabled={busy === t.id} onClick={() => approve(t)}>
                                                    Approve
                                                </button>
                                            </div>
                                        ) : (
                                            <>
                                                <span style={{ fontWeight: 700 }}>{t.reviewer ?? '-'}</span>
                                                {t.note && <div style={{ fontSize: 12, color: 'var(--muted)' }}>{t.note}</div>}
                                            </>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Modal
                open={!!rejecting}
                onClose={() => setRejecting(null)}
                title="Reject this payment?"
                desc={rejecting ? `${rejecting.user.name} · ${usd(rejecting.amountUsd)} · ${rejecting.method} ${rejecting.reference}. The customer sees your reason.` : ''}
                footer={
                    <>
                        <button className="btn btn-ghost" onClick={() => setRejecting(null)}>
                            Cancel
                        </button>
                        <button className="btn btn-danger" disabled={!note.trim()} onClick={reject}>
                            Reject payment
                        </button>
                    </>
                }
            >
                <div className="field">
                    <label htmlFor="note">Reason</label>
                    <input id="note" className="input" placeholder="e.g. Amount not received" value={note} onChange={(e) => setNote(e.target.value)} />
                    <FieldError msg={error} />
                </div>
            </Modal>
        </>
    );
}

TopUps.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
