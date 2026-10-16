import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import CallDrawer from '@/components/CallDrawer';
import Icon from '@/components/Icon';
import { Seg } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { fmtDate } from '@/lib/format';

type Status = 'new' | 'confirmed' | 'done';
type Props = {
    bookings: {
        id: number;
        type: 'booking' | 'order' | 'callback';
        details: { label: string; value: string }[];
        sync: { status: 'pending' | 'delivered' | 'failed'; reference: string | null } | null;
        name: string;
        phone: string | null;
        request: string;
        when: string | null;
        status: Status;
        callId: number | null;
        createdAt: string;
    }[];
    filter: '' | Status;
    counts: { new: number; confirmed: number; done: number; month: number };
};

export default function Bookings({ bookings, filter, counts }: Props) {
    const [openCall, setOpenCall] = useState<number | null>(null);
    const setFilter = (status: '' | Status) => router.get('/bookings', status ? { status } : {}, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <>
            <Head title="Bookings" />
            <div className="summary-row">
                <div className="card">
                    <small>New</small>
                    <strong className="num">{counts.new}</strong>
                </div>
                <div className="card">
                    <small>Confirmed</small>
                    <strong className="num">{counts.confirmed}</strong>
                </div>
                <div className="card">
                    <small>Done</small>
                    <strong className="num">{counts.done}</strong>
                </div>
                <div className="card">
                    <small>This month</small>
                    <strong className="num">{counts.month}</strong>
                </div>
            </div>

            <div className="card">
                <div className="toolbar">
                    <Seg
                        value={filter}
                        onChange={setFilter}
                        options={[
                            { value: '', label: 'All' },
                            { value: 'new', label: `New (${counts.new})` },
                            { value: 'confirmed', label: 'Confirmed' },
                            { value: 'done', label: 'Done' },
                        ]}
                    />
                    <div style={{ flex: 1 }} />
                    <span style={{ fontSize: 12.5, color: 'var(--muted)', fontWeight: 600 }}>Captured automatically by your bot during calls</span>
                </div>
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Request</th>
                                <th>Preferred time</th>
                                <th>Received</th>
                                <th>Status</th>
                                <th className="r" />
                            </tr>
                        </thead>
                        <tbody>
                            {bookings.length === 0 && (
                                <tr style={{ cursor: 'default' }}>
                                    <td colSpan={6}>
                                        <div className="empty">Nothing here yet.</div>
                                    </td>
                                </tr>
                            )}
                            {bookings.map((b) => (
                                <tr key={b.id} style={{ cursor: 'default' }}>
                                    <td className="bk-who">
                                        <b>{b.name}</b>
                                        {b.phone && (
                                            <a href={`tel:${b.phone.replace(/\s/g, '')}`} className="num" style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>
                                                {b.phone}
                                            </a>
                                        )}
                                    </td>
                                    <td style={{ color: 'var(--text)', maxWidth: 420 }}>
                                        <span className={'chip type-' + b.type} style={{ marginRight: 6 }}>
                                            {b.type[0].toUpperCase() + b.type.slice(1)}
                                        </span>
                                        {b.request}
                                        {b.details.length > 0 && (
                                            <div className="details">
                                                {b.details.map((d) => (
                                                    <span key={d.label}>
                                                        <b>{d.label}:</b> {d.value}
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                        {b.sync && (
                                            <div className={'sync ' + b.sync.status}>
                                                <Icon name={b.sync.status === 'delivered' ? 'check' : b.sync.status === 'failed' ? 'alert' : 'clock'} className="i i-sm" />
                                                {b.sync.status === 'delivered' ? `Sent to your software${b.sync.reference ? ` · ${b.sync.reference}` : ''}` : b.sync.status === 'failed' ? 'Could not reach your software' : 'Sending to your software…'}
                                            </div>
                                        )}
                                    </td>
                                    <td style={{ whiteSpace: 'nowrap' }}>{b.when ?? '-'}</td>
                                    <td style={{ whiteSpace: 'nowrap', color: 'var(--muted)', fontWeight: 600 }}>{fmtDate(b.createdAt)}</td>
                                    <td>
                                        <select
                                            className="select sm"
                                            value={b.status}
                                            aria-label={`Status for ${b.name}`}
                                            onChange={(e) => router.patch(`/bookings/${b.id}`, { status: e.target.value }, { preserveScroll: true, preserveState: true })}
                                        >
                                            <option value="new">New</option>
                                            <option value="confirmed">Confirmed</option>
                                            <option value="done">Done</option>
                                        </select>
                                    </td>
                                    <td className="r">
                                        {b.callId && (
                                            <button className="link" style={{ fontSize: 12.5 }} onClick={() => setOpenCall(b.callId)}>
                                                View call
                                                <Icon name="chev" className="i i-sm" />
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <CallDrawer callId={openCall} onClose={() => setOpenCall(null)} />
        </>
    );
}

Bookings.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
