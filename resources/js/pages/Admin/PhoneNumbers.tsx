import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/Icon';
import AppLayout from '@/layouts/AppLayout';

type PNumber = { id: string; number: string | null; name: string | null; provider: string | null; assistantId: string | null; account: { id: number; name: string; email: string } | null };
type Props = { enabled: boolean; error: string | null; defaultOutboundId: string | null; numbers: PNumber[]; accounts: { id: number; label: string }[] };

/** Admin: Vapi phone numbers and which customer's bot answers each (inbound) and calls out from it. */
export default function PhoneNumbers({ enabled, error, defaultOutboundId, numbers, accounts }: Props) {
    const [pick, setPick] = useState<Record<string, string>>({});

    return (
        <>
            <Head title="Phone numbers" />
            <div className="card">
                <div className="card-h">
                    <div>
                        <h3>Phone numbers</h3>
                        <p>Buy or import numbers in the Vapi dashboard (Phone Numbers → Import: Twilio, Telnyx, Vonage or your own SIP trunk), then give each one to a customer. Calls to that number ring their assistant, and their AI callbacks and reminders come from it.</p>
                    </div>
                </div>
                <div className="card-b stack">
                    {!enabled && (
                        <div className="notice">
                            <Icon name="key" className="i i-sm" />
                            Add the Vapi private key in <Link href="/admin/settings?tab=providers" className="link">Platform settings → API keys</Link> first.
                        </div>
                    )}
                    {error && (
                        <div className="notice" style={{ color: 'var(--danger)' }}>
                            <Icon name="alert" className="i i-sm" />
                            {error}
                        </div>
                    )}
                    <span className="hint">
                        Pakistan (+92): Vapi does not sell Pakistani numbers. Use a provider that does (Twilio or a local SIP provider) and import it. Customers without a number can still get AI callbacks from the default outbound number set in API keys{defaultOutboundId ? '' : ' (not set yet)'}.
                    </span>
                    {enabled && !error && numbers.length === 0 && <div className="empty">No numbers in the Vapi account yet.</div>}
                    {numbers.map((n) => (
                        <div key={n.id} className="voice" style={{ cursor: 'default', flexWrap: 'wrap', gap: 10 }}>
                            <span className="v-av">
                                <Icon name="phone" />
                            </span>
                            <span className="v-tx" style={{ minWidth: 200 }}>
                                <b className="mono">{n.number ?? n.name ?? n.id}</b>
                                <span>
                                    {n.provider ?? 'vapi'}
                                    {n.id === defaultOutboundId && ' · default outbound'}
                                    {n.account ? ` · answers for ${n.account.name}` : ' · not assigned'}
                                </span>
                            </span>
                            <span style={{ display: 'flex', gap: 8, marginLeft: 'auto', flexWrap: 'wrap' }}>
                                <select className="select" style={{ width: 240 }} value={pick[n.id] ?? String(n.account?.id ?? '')} onChange={(e) => setPick({ ...pick, [n.id]: e.target.value })}>
                                    <option value="">Not assigned</option>
                                    {accounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.label}
                                        </option>
                                    ))}
                                </select>
                                <button
                                    className="btn btn-primary btn-sm"
                                    disabled={pick[n.id] === undefined || pick[n.id] === String(n.account?.id ?? '')}
                                    onClick={() => router.post('/admin/phone-numbers/assign', { phoneNumberId: n.id, userId: pick[n.id] ? +pick[n.id] : null }, { preserveScroll: true })}
                                >
                                    Save
                                </button>
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

PhoneNumbers.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
