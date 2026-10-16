import { Head, Link } from '@inertiajs/react';
import Icon from '@/components/Icon';
import AppLayout from '@/layouts/AppLayout';
import { timeAgo, usd } from '@/lib/format';
import { ActionLabel, type AdminActionRow } from './AccountShow';

type Props = {
    stats: {
        accounts: number;
        newThisMonth: number;
        suspended: number;
        callsToday: number;
        minutesThisMonth: number;
        revenueThisMonth: number;
        usageThisMonth: number;
        walletTotal: number;
        pendingTopUps: number;
    };
    recentActions: AdminActionRow[];
};

export default function AdminOverview({ stats, recentActions }: Props) {
    return (
        <>
            <Head title="Super admin" />
            {stats.pendingTopUps > 0 && (
                <div className="alert warn">
                    <Icon name="wallet" />
                    <span className="grow">
                        {stats.pendingTopUps} payment{stats.pendingTopUps > 1 ? 's are' : ' is'} waiting for your approval.
                    </span>
                    <Link href="/admin/top-ups" className="btn btn-sm btn-primary">
                        Review
                    </Link>
                </div>
            )}
            <div className="stats">
                <div className="card stat">
                    <div className="row">
                        <span className="lbl">Customer accounts</span>
                        <span className="ico">
                            <Icon name="users" />
                        </span>
                    </div>
                    <div className="val num">{stats.accounts}</div>
                    <div className="delta">
                        <b>+{stats.newThisMonth}</b> this month · {stats.suspended} suspended
                    </div>
                </div>
                <div className="card stat">
                    <div className="row">
                        <span className="lbl">Calls today</span>
                        <span className="ico">
                            <Icon name="phone" />
                        </span>
                    </div>
                    <div className="val num">{stats.callsToday}</div>
                    <div className="delta">{stats.minutesThisMonth} minutes this month</div>
                </div>
                <div className="card stat">
                    <div className="row">
                        <span className="lbl">Top-ups this month</span>
                        <span className="ico">
                            <Icon name="trend" />
                        </span>
                    </div>
                    <div className="val num">{usd(stats.revenueThisMonth)}</div>
                    <div className="delta">Usage charged: {usd(stats.usageThisMonth)}</div>
                </div>
                <div className="card stat balance">
                    <div className="row">
                        <span className="lbl">Money held in wallets</span>
                        <span className="ico">
                            <Icon name="wallet" />
                        </span>
                    </div>
                    <div className="val num">{usd(stats.walletTotal)}</div>
                    <div className="delta">Owed to customers as call minutes</div>
                </div>
            </div>

            <div className="card">
                <div className="card-h">
                    <div>
                        <h3>Recent admin activity</h3>
                        <p>Every super admin action is recorded</p>
                    </div>
                    <Link href="/admin/accounts" className="link">
                        All accounts
                        <Icon name="chev" className="i i-sm" />
                    </Link>
                </div>
                <div className="card-b timeline">
                    {recentActions.length === 0 && <div className="empty">Nothing yet.</div>}
                    {recentActions.map((a) => (
                        <div key={a.id}>
                            <b>{a.admin ?? 'Admin'}</b> <ActionLabel a={a} />{' '}
                            {a.targetId ? (
                                <Link href={`/admin/accounts/${a.targetId}`} className="link">
                                    {a.target}
                                </Link>
                            ) : null}
                            <span>{timeAgo(a.at)}</span>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

AdminOverview.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
