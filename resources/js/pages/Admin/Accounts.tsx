import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Icon from '@/components/Icon';
import { Seg } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { fmtDateLong, usd } from '@/lib/format';

type Props = {
    accounts: { id: number; name: string; email: string; business: string | null; balance: number; calls30: number; pendingTopUps: number; suspended: boolean; createdAt: string }[];
    pagination: { page: number; lastPage: number; total: number };
    filters: { q: string; status: '' | 'active' | 'suspended' };
};

export default function Accounts({ accounts, pagination, filters }: Props) {
    const [q, setQ] = useState(filters.q);
    const first = useRef(true);
    const visit = (p: Record<string, string | number>) => {
        const params: Record<string, string | number> = { ...filters, q, ...p };
        router.get('/admin/accounts', Object.fromEntries(Object.entries(params).filter(([k, v]) => v !== '' && !(k === 'page' && v === 1))), { preserveState: true, replace: true });
    };

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = window.setTimeout(() => visit({ q, page: 1 }), 350);
        return () => window.clearTimeout(t);
    }, [q]);

    return (
        <>
            <Head title="Accounts" />
            <div className="card">
                <div className="toolbar">
                    <div className="search">
                        <Icon name="search" />
                        <input className="input" placeholder="Search name, email or business…" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search accounts" />
                    </div>
                    <Seg
                        value={filters.status}
                        onChange={(s) => visit({ status: s, page: 1 })}
                        options={[
                            { value: '', label: 'All' },
                            { value: 'active', label: 'Active' },
                            { value: 'suspended', label: 'Suspended' },
                        ]}
                    />
                    <div style={{ flex: 1 }} />
                    <span style={{ fontSize: 12.5, color: 'var(--muted)', fontWeight: 600 }}>{pagination.total} accounts</span>
                </div>
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Business</th>
                                <th>Calls (30 days)</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th className="r">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            {accounts.length === 0 && (
                                <tr style={{ cursor: 'default' }}>
                                    <td colSpan={6}>
                                        <div className="empty">No accounts match.</div>
                                    </td>
                                </tr>
                            )}
                            {accounts.map((a) => (
                                <tr key={a.id} onClick={() => router.visit(`/admin/accounts/${a.id}`)}>
                                    <td className="bk-who">
                                        <b>{a.name}</b>
                                        <span>{a.email}</span>
                                    </td>
                                    <td>{a.business ?? <span style={{ color: 'var(--muted)' }}>-</span>}</td>
                                    <td className="num">{a.calls30}</td>
                                    <td>
                                        <span className={'chip ' + (a.suspended ? 'bad' : 'ok')}>{a.suspended ? 'Suspended' : 'Active'}</span>
                                        {a.pendingTopUps > 0 && (
                                            <span className="chip pending" style={{ marginLeft: 6 }}>
                                                {a.pendingTopUps} payment pending
                                            </span>
                                        )}
                                    </td>
                                    <td style={{ color: 'var(--muted)', fontWeight: 600 }}>{fmtDateLong(a.createdAt)}</td>
                                    <td className="r num">
                                        <b>{usd(a.balance)}</b>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {pagination.lastPage > 1 && (
                    <div className="pager">
                        <span>
                            Page {pagination.page} of {pagination.lastPage}
                        </span>
                        <div className="pg">
                            <button disabled={pagination.page === 1} onClick={() => visit({ page: pagination.page - 1 })}>
                                Previous
                            </button>
                            <button disabled={pagination.page === pagination.lastPage} onClick={() => visit({ page: pagination.page + 1 })}>
                                Next
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

Accounts.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
