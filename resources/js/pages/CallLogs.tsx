import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import CallDrawer from '@/components/CallDrawer';
import CallRow from '@/components/CallRow';
import Icon from '@/components/Icon';
import AppLayout from '@/layouts/AppLayout';
import type { CallRowData } from '@/types';

type Filters = { q: string; status: string; days: number; per: number };
type Props = {
    calls: { data: CallRowData[]; page: number; lastPage: number; total: number; from: number | null; to: number | null };
    filters: Filters;
    summary: { total: number; talkMinutes: number; spend: number; completedPct: number };
};

/** Server-side filtering + pagination; the URL holds the filters so links and refresh keep them. */
export default function CallLogs({ calls, filters, summary }: Props) {
    const [q, setQ] = useState(filters.q);
    const [openCall, setOpenCall] = useState<number | null>(null);
    const first = useRef(true);

    const visit = (next: Partial<Filters> & { page?: number }) => {
        const params = { ...filters, q, ...next };
        const clean = Object.fromEntries(Object.entries(params).filter(([k, v]) => v !== '' && v !== 0 && !(k === 'per' && v === 10) && !(k === 'page' && v === 1)));
        router.get('/calls', clean, { preserveState: true, preserveScroll: true, replace: true });
    };

    // Debounced search.
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = window.setTimeout(() => visit({ q, page: 1 }), 350);
        return () => window.clearTimeout(t);
    }, [q]);

    const exportUrl = '/calls/export?' + new URLSearchParams(Object.entries({ q: filters.q, status: filters.status, days: String(filters.days || '') }).filter(([, v]) => v)).toString();
    const pages = Array.from({ length: calls.lastPage }, (_, i) => i + 1).filter((p) => p === 1 || p === calls.lastPage || Math.abs(p - calls.page) <= 2);

    return (
        <>
            <Head title="Call Logs" />
            <div className="summary-row">
                <div className="card">
                    <small>Total calls</small>
                    <strong className="num">{summary.total}</strong>
                </div>
                <div className="card">
                    <small>Talk time</small>
                    <strong className="num">{summary.talkMinutes} min</strong>
                </div>
                <div className="card">
                    <small>Total spend</small>
                    <strong className="num">${summary.spend.toFixed(2)}</strong>
                </div>
                <div className="card">
                    <small>Completed</small>
                    <strong className="num">{summary.completedPct}%</strong>
                </div>
            </div>

            <div className="card">
                <div className="toolbar">
                    <div className="search">
                        <Icon name="search" />
                        <input className="input" placeholder="Search transcripts…" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search transcripts" />
                    </div>
                    <select className="select" value={filters.days} onChange={(e) => visit({ days: +e.target.value, page: 1 })} aria-label="Date range">
                        <option value={0}>All time</option>
                        <option value={1}>Today</option>
                        <option value={7}>Last 7 days</option>
                        <option value={30}>Last 30 days</option>
                    </select>
                    <select className="select" value={filters.status} onChange={(e) => visit({ status: e.target.value, page: 1 })} aria-label="Status">
                        <option value="">All statuses</option>
                        <option value="completed">Completed</option>
                        <option value="dropped">Dropped</option>
                        <option value="failed">Failed</option>
                    </select>
                    <select className="select" value={filters.per} onChange={(e) => visit({ per: +e.target.value, page: 1 })} aria-label="Rows per page">
                        <option value={10}>10</option>
                        <option value={25}>25</option>
                        <option value={50}>50</option>
                    </select>
                    <div style={{ flex: 1 }} />
                    <a className="btn btn-ghost btn-sm" href={exportUrl}>
                        <Icon name="download" className="i i-sm" />
                        Export CSV
                    </a>
                </div>
                <div className="tbl-wrap">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Conversation</th>
                                <th>Outcome</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th className="r">Cost</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {calls.data.length ? (
                                calls.data.map((c) => <CallRow key={c.id} call={c} full onOpen={setOpenCall} />)
                            ) : (
                                <tr style={{ cursor: 'default' }}>
                                    <td colSpan={7}>
                                        <div className="empty">No calls match your filters.</div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <div className="pager">
                    <span>{calls.total ? `Showing ${calls.from}–${calls.to} of ${calls.total} calls` : '0 calls'}</span>
                    <div className="pg">
                        <button disabled={calls.page === 1} onClick={() => visit({ page: calls.page - 1 })}>
                            Previous
                        </button>
                        {pages.map((p, i) => (
                            <span key={p} style={{ display: 'contents' }}>
                                {i > 0 && p - pages[i - 1] > 1 && <button disabled>…</button>}
                                <button className={p === calls.page ? 'on' : ''} onClick={() => visit({ page: p })}>
                                    {p}
                                </button>
                            </span>
                        ))}
                        <button disabled={calls.page === calls.lastPage} onClick={() => visit({ page: calls.page + 1 })}>
                            Next
                        </button>
                    </div>
                </div>
            </div>

            <CallDrawer callId={openCall} onClose={() => setOpenCall(null)} />
        </>
    );
}

CallLogs.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
