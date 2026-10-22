import { Head, Link, router } from '@inertiajs/react';
import Icon, { IconName } from '@/components/Icon';
import { Seg } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { timeAgo } from '@/lib/format';

type Props = {
    days: number;
    funnel: { label: string; value: number }[];
    stats: { leads: number; widgetLeads: number; leadRate: number; afterHoursShare: number; afterHoursLeads: number; costPkr: number; costPerConversationPkr: number; costPerLeadPkr: number | null; hoursSet: boolean };
    byStatus: { status: string; count: number }[];
    byType: { type: string; count: number }[];
    gaps: { question: string; count: number; callId: number; lastAt: string }[];
};

const rs = (n: number) => 'Rs ' + n.toLocaleString('en-PK');
const STATUS_LABEL: Record<string, string> = { new: 'New', contacted: 'Contacted', confirmed: 'Booked', done: 'Won', lost: 'Lost', spam: 'Spam' };

function Tile({ label, icon, value, foot }: { label: string; icon: IconName; value: React.ReactNode; foot: string }) {
    return (
        <div className="card stat">
            <div className="row">
                <span className="lbl">{label}</span>
                <span className="ico">
                    <Icon name={icon} />
                </span>
            </div>
            <div className="val num">{value}</div>
            <span className="delta" style={{ marginTop: 'auto' }}>
                {foot}
            </span>
        </div>
    );
}

/** How website conversations turn into leads, what it costs, and what the bot could not answer. */
export default function Insights({ days, funnel, stats, byStatus, byType, gaps }: Props) {
    const top = Math.max(1, funnel[0]?.value ?? 1);

    return (
        <>
            <Head title="Insights" />
            <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
                <Seg
                    value={String(days)}
                    options={[
                        { value: '7', label: '7 days' },
                        { value: '30', label: '30 days' },
                        { value: '90', label: '90 days' },
                    ]}
                    onChange={(v) => router.get('/insights', { days: v }, { preserveScroll: true, preserveState: true })}
                />
            </div>

            <div className="stats">
                <Tile label="Leads" icon="calendar" value={stats.leads} foot={`${stats.widgetLeads} from the callback form`} />
                <Tile label="Lead rate" icon="trend" value={`${stats.leadRate}%`} foot="Conversations that left a lead" />
                <Tile label="After hours" icon="clock" value={stats.hoursSet ? `${stats.afterHoursShare}%` : '-'} foot={stats.hoursSet ? `${stats.afterHoursLeads} leads while you were closed` : 'Set opening hours in Bot Studio'} />
                <Tile label="Cost per lead" icon="wallet" value={stats.costPerLeadPkr === null ? '-' : rs(stats.costPerLeadPkr)} foot={`${rs(stats.costPkr)} total · ${rs(stats.costPerConversationPkr)} per conversation`} />
            </div>

            <div className="two-col">
                <div className="card">
                    <div className="card-h">
                        <div>
                            <h3>Conversion funnel</h3>
                            <p>Website conversations in the last {days} days (test calls excluded).</p>
                        </div>
                    </div>
                    <div className="card-b" style={{ display: 'grid', gap: 12 }}>
                        {funnel.map((f) => (
                            <div key={f.label}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 4 }}>
                                    <span>{f.label}</span>
                                    <b className="num">{f.value}</b>
                                </div>
                                <div style={{ height: 10, borderRadius: 99, background: 'var(--sunken)', overflow: 'hidden' }}>
                                    <div style={{ height: '100%', width: `${(f.value / top) * 100}%`, background: 'var(--accent)', borderRadius: 99 }} />
                                </div>
                            </div>
                        ))}
                        <div className="hint" style={{ display: 'flex', gap: 14, flexWrap: 'wrap', marginTop: 4 }}>
                            {byStatus.map((s) => (
                                <span key={s.status}>
                                    {STATUS_LABEL[s.status] ?? s.status}: <b>{s.count}</b>
                                </span>
                            ))}
                            <span>·</span>
                            {byType.map((t) => (
                                <span key={t.type}>
                                    {t.type[0].toUpperCase() + t.type.slice(1)}s: <b>{t.count}</b>
                                </span>
                            ))}
                        </div>
                    </div>
                </div>

                <div className="card">
                    <div className="card-h">
                        <div>
                            <h3>Questions your bot could not answer</h3>
                            <p>Add the answers to Quick facts or a knowledge file, and the bot will know them on the next call.</p>
                        </div>
                        <Link href="/bot-studio?tab=knowledge" className="btn btn-ghost btn-sm">
                            <Icon name="book" className="i i-sm" />
                            Add answers
                        </Link>
                    </div>
                    <div className="card-b" style={{ display: 'grid', gap: 8 }}>
                        {gaps.length === 0 && <div className="empty">Nothing yet. Questions your bot was unsure about will show up here.</div>}
                        {gaps.map((g) => (
                            <div key={g.question} style={{ display: 'flex', gap: 10, alignItems: 'baseline', justifyContent: 'space-between', borderBottom: '1px solid var(--border)', paddingBottom: 8 }}>
                                <span dir="auto" style={{ minWidth: 0 }}>
                                    {g.question}
                                </span>
                                <span className="hint" style={{ whiteSpace: 'nowrap' }}>
                                    {g.count > 1 ? `${g.count}× · ` : ''}
                                    <Link href={`/calls?call=${g.callId}`} className="link">
                                        {timeAgo(g.lastAt)}
                                    </Link>
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </>
    );
}

Insights.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
