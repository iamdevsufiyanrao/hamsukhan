import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CallChart from '@/components/CallChart';
import CallDrawer from '@/components/CallDrawer';
import LiveCallsPanel from '@/components/LiveCallsPanel';
import CallRow from '@/components/CallRow';
import Icon, { IconName } from '@/components/Icon';
import { Seg, useUI } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { fmtDur, hourRange, timeAgo } from '@/lib/format';
import type { CallRowData, SharedProps } from '@/types';

type Props = {
    stats: { today: number; yesterday: number; month: number; lastMonth: number; avgSeconds: number; completedPct: number; bookingsThisMonth: number; busiestHour: number | null };
    series: { date: string; count: number }[];
    intents: { tag: string; count: number }[];
    recentCalls: CallRowData[];
    bot: { greeting: string; language: string; voice: string; maxMinutes: number; isActive: boolean; website: string | null; updatedAt: string };
    setup: Record<'configure' | 'voice' | 'widget' | 'knowledge' | 'embed', boolean>;
};

const SETUP: { key: keyof Props['setup']; title: string; sub: string }[] = [
    { key: 'configure', title: 'Write instructions', sub: 'Tell the bot about your business' },
    { key: 'voice', title: 'Clone your voice', sub: '30 seconds of speaking' },
    { key: 'widget', title: 'Style the widget', sub: 'Match your brand colors' },
    { key: 'knowledge', title: 'Add knowledge', sub: 'Upload menu or price list' },
    { key: 'embed', title: 'Install on website', sub: 'Paste one snippet' },
];

function readHidden() {
    try {
        return localStorage.getItem('hs-setup-hidden') === '1';
    } catch {
        return false;
    }
}

function greeting() {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
}

function delta(now: number, before: number, pct = false) {
    if (!before) return now ? <><b>New</b> this period</> : 'No calls yet';
    const d = now - before;
    const v = pct ? `${d >= 0 ? '+' : ''}${Math.round((d / before) * 100)}%` : `${d >= 0 ? '+' : ''}${d}`;
    return <><b>{v}</b> vs {pct ? 'last month' : 'yesterday'}</>;
}

function Stat({ label, icon, children, foot }: { label: string; icon: IconName; children: React.ReactNode; foot: React.ReactNode }) {
    return (
        <div className="card stat">
            <div className="row">
                <span className="lbl">{label}</span>
                <span className="ico">
                    <Icon name={icon} />
                </span>
            </div>
            <div className="val num">{children}</div>
            <div className="delta">{foot}</div>
        </div>
    );
}

export default function Overview({ stats, series, intents, recentCalls, bot, setup }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const { openModal } = useUI();
    const [range, setRange] = useState<'7' | '14' | '30'>('14');
    const [openCall, setOpenCall] = useState<number | null>(null);
    const [setupHidden, setSetupHidden] = useState(readHidden);

    const done = SETUP.filter((s) => setup[s.key]).length;
    const intentTotal = intents.reduce((a, i) => a + i.count, 0);
    const [whole, cents] = (auth?.wallet.balance ?? 0).toFixed(2).split('.');
    const avg = fmtDur(stats.avgSeconds).split(' ');

    return (
        <>
            <Head title="Overview" />
            <LiveCallsPanel />
            <div className="hello">
                <div>
                    <h2>
                        {greeting()}, {auth?.user.firstName}
                    </h2>
                    <p>Here's what your receptionist handled for you.</p>
                </div>
                <Link href="/bot-studio" className="btn btn-ink">
                    <Icon name="sliders" />
                    Open Bot Studio
                </Link>
            </div>

            {!setupHidden && done < 5 && (
                <div className="card setup">
                    <div>
                        <h3>Finish setting up</h3>
                        <p>{done} of 5 steps done, your bot gets better with each one.</p>
                        <div className="bar-track">
                            <span style={{ width: (done / 5) * 100 + '%' }} />
                        </div>
                        <button
                            className="hide-btn"
                            onClick={() => {
                                setSetupHidden(true);
                                try {
                                    localStorage.setItem('hs-setup-hidden', '1');
                                } catch {}
                            }}
                        >
                            Hide for now
                        </button>
                    </div>
                    <div className="setup-steps">
                        {SETUP.map((s, i) => (
                            <Link key={s.key} href={`/bot-studio?tab=${s.key}`} className={'step' + (setup[s.key] ? ' done' : '')}>
                                <span className="dot">{setup[s.key] ? <Icon name="check" /> : i + 1}</span>
                                {s.title}
                                <small>{s.sub}</small>
                            </Link>
                        ))}
                    </div>
                </div>
            )}

            <div className="stats">
                <Stat label="Calls today" icon="phone" foot={delta(stats.today, stats.yesterday)}>
                    {stats.today}
                </Stat>
                <Stat label="Calls this month" icon="trend" foot={delta(stats.month, stats.lastMonth, true)}>
                    {stats.month}
                </Stat>
                <Stat label="Avg. call length" icon="clock" foot={`Across ${stats.month} calls this month`}>
                    {stats.avgSeconds ? (
                        <>
                            {avg[0].replace('m', '')}
                            <small>m</small> {avg[1].replace('s', '')}
                            <small>s</small>
                        </>
                    ) : (
                        '-'
                    )}
                </Stat>
                <div className="card stat balance">
                    <div className="row">
                        <span className="lbl">Current balance</span>
                        <span className="ico">
                            <Icon name="wallet" />
                        </span>
                    </div>
                    <div className="val num">
                        ${whole}
                        <small>.{cents}</small>
                    </div>
                    <div className="row" style={{ marginTop: 'auto' }}>
                        <span className="delta" style={{ margin: 0 }}>
                            ≈ {auth?.wallet.minutesLeft} min left
                        </span>
                        <button className="btn" onClick={() => openModal('topup')}>
                            Top up
                        </button>
                    </div>
                </div>
            </div>

            <div className="dash-grid">
                <div className="card">
                    <div className="card-h">
                        <div>
                            <h3>Call volume</h3>
                            <p>Calls answered per day</p>
                        </div>
                        <Seg value={range} onChange={setRange} options={[{ value: '7', label: '7D' }, { value: '14', label: '14D' }, { value: '30', label: '30D' }]} />
                    </div>
                    <CallChart series={series} range={+range} />
                    <div className="mini-stats">
                        <div>
                            <small>Busiest time</small>
                            <strong>{hourRange(stats.busiestHour)}</strong>
                        </div>
                        <div>
                            <small>Bookings this month</small>
                            <strong className="num">{stats.bookingsThisMonth}</strong>
                        </div>
                        <div>
                            <small>Completion rate</small>
                            <strong className="num">{stats.completedPct}%</strong>
                        </div>
                    </div>
                </div>

                <div className="card bot-card">
                    <div className="card-h">
                        <div>
                            <h3>Your receptionist</h3>
                            <p>{bot.website ? `Live on ${bot.website}` : 'Not installed on a website yet'}</p>
                        </div>
                    </div>
                    <div className="card-b">
                        <div className="live-row">
                            <span className={'chip ' + (bot.isActive ? 'live' : 'bad')}>{bot.isActive ? 'Live' : 'Paused'}</span>
                            <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>Updated {timeAgo(bot.updatedAt)}</span>
                        </div>
                        <dl className="kv">
                            <dt>Voice</dt>
                            <dd>{bot.voice}</dd>
                            <dt>Language</dt>
                            <dd>{bot.language}</dd>
                            <dt>Engine</dt>
                            <dd>Vapi · GPT-4o</dd>
                            <dt>Max call length</dt>
                            <dd>{bot.maxMinutes} min</dd>
                        </dl>
                        <div className="quote">
                            <b>Greeting</b>“{bot.greeting}”
                        </div>
                        <div className="acts">
                            <Link href="/bot-studio" className="btn btn-ghost">
                                Edit bot
                            </Link>
                            <button className="btn btn-primary" onClick={() => openModal('test')}>
                                <Icon name="mic" />
                                Test call
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div className="dash-grid" style={{ marginBottom: 0 }}>
                <div className="card">
                    <div className="card-h">
                        <div>
                            <h3>Recent calls</h3>
                            <p>Latest conversations your bot handled</p>
                        </div>
                        <Link href="/calls" className="link">
                            View all
                            <Icon name="chev" className="i i-sm" />
                        </Link>
                    </div>
                    <div className="tbl-wrap" style={{ marginTop: 14 }}>
                        <table className="tbl">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Conversation</th>
                                    <th>Outcome</th>
                                    <th className="r">Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                {recentCalls.length === 0 && (
                                    <tr style={{ cursor: 'default' }}>
                                        <td colSpan={4}>
                                            <div className="empty">No calls yet, try a test call.</div>
                                        </td>
                                    </tr>
                                )}
                                {recentCalls.map((c) => (
                                    <CallRow key={c.id} call={c} onOpen={setOpenCall} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
                <div className="card">
                    <div className="card-h">
                        <div>
                            <h3>Why people call</h3>
                            <p>Detected from this month's calls</p>
                        </div>
                    </div>
                    <div className="card-b intents">
                        {intents.length === 0 && <div className="empty" style={{ padding: 20 }}>No calls this month yet.</div>}
                        {intents.map(({ tag, count }) => {
                            const p = Math.round((count / intentTotal) * 100);
                            return (
                                <div className="intent" key={tag}>
                                    <div className="row">
                                        {tag}
                                        <span>
                                            {count} calls · {p}%
                                        </span>
                                    </div>
                                    <div className="bar-track">
                                        <span style={{ width: p + '%', background: tag === 'No outcome' ? 'var(--border-strong)' : undefined }} />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>

            <CallDrawer callId={openCall} onClose={() => setOpenCall(null)} />
        </>
    );
}

Overview.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
