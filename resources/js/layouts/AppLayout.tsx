import { Link, router, usePage } from '@inertiajs/react';
import { ReactNode, useEffect, useState } from 'react';
import Icon, { IconName, Logo } from '@/components/Icon';
import { Avatar, UIProvider, useUI } from '@/components/ui';
import { TestCallModal } from '@/components/GlobalModals';
import type { SharedProps } from '@/types';

const TITLES: Record<string, [string, string]> = {
    Overview: ['Workspace', 'Overview'],
    BotStudio: ['Workspace', 'Bot Studio'],
    CallLogs: ['Workspace', 'Call Logs'],
    Bookings: ['Workspace', 'Bookings'],
    Insights: ['Workspace', 'Insights'],
    Campaigns: ['Workspace', 'Campaigns'],
    Billing: ['Account', 'Billing'],
    Settings: ['Account', 'Settings'],
    Integrations: ['Workspace', 'Integrations'],
    'Admin/Overview': ['Super admin', 'Platform overview'],
    'Admin/Accounts': ['Super admin', 'Accounts'],
    'Admin/AccountShow': ['Super admin', 'Account'],
    'Admin/TopUps': ['Super admin', 'Payment approvals'],
    'Admin/Messages': ['Super admin', 'Contact messages'],
    'Admin/VoiceSamples': ['Super admin', 'Sample voices'],
    'Admin/ProviderVoices': ['Super admin', 'ElevenLabs voices'],
    'Admin/PhoneNumbers': ['Super admin', 'Phone numbers'],
    'Admin/Settings': ['Super admin', 'Platform settings'],
};

const ADMIN_NAV: { href: string; label: string; icon: IconName; component: string }[] = [
    { href: '/admin', label: 'Overview', icon: 'trend', component: 'Admin/Overview' },
    { href: '/admin/accounts', label: 'Accounts', icon: 'users', component: 'Admin/Accounts' },
    { href: '/admin/top-ups', label: 'Payments', icon: 'shield', component: 'Admin/TopUps' },
    { href: '/admin/voice-samples', label: 'Sample voices', icon: 'wave', component: 'Admin/VoiceSamples' },
    { href: '/admin/provider-voices', label: 'ElevenLabs voices', icon: 'mic', component: 'Admin/ProviderVoices' },
    { href: '/admin/phone-numbers', label: 'Phone numbers', icon: 'phone', component: 'Admin/PhoneNumbers' },
    { href: '/admin/messages', label: 'Messages', icon: 'mail', component: 'Admin/Messages' },
    { href: '/admin/settings', label: 'Platform settings', icon: 'sliders', component: 'Admin/Settings' },
];

export function useTheme() {
    const [dark, setDark] = useState(() => {
        const t = document.documentElement.dataset.theme;
        return t ? t === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
    });
    const toggle = () => {
        const next = dark ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        try {
            localStorage.setItem('hs-theme', next);
        } catch {}
        setDark(!dark);
    };
    return { dark, toggle };
}

function Shell({ children }: { children: ReactNode }) {
    const page = usePage<SharedProps>();
    const { component } = page;
    const { auth, flash } = page.props;
    const { openModal, toast } = useUI();
    const { dark, toggle } = useTheme();
    const [menuOpen, setMenuOpen] = useState(false);
    const [crumb, title] = TITLES[component] ?? ['Workspace', ''];

    useEffect(() => router.on('navigate', () => setMenuOpen(false)), []);
    useEffect(() => {
        if (flash?.success) toast(flash.success);
        if (flash?.error) toast(flash.error, 'error');
    }, [flash, toast]); // flash is a new object on every visit, so repeated messages still show

    if (!auth) return <>{children}</>;
    const { user, plan, badges, stores } = auth;
    const isCustomer = !!auth.bot; // admin-only accounts have no bot

    const nav: { href: string; label: string; icon: IconName; component: string; badge?: number }[] = [
        { href: '/dashboard', label: 'Overview', icon: 'home', component: 'Overview' },
        { href: '/bot-studio', label: 'Bot Studio', icon: 'sliders', component: 'BotStudio' },
        { href: '/calls', label: 'Call Logs', icon: 'phone', component: 'CallLogs' },
        { href: '/bookings', label: 'Bookings', icon: 'calendar', component: 'Bookings', badge: badges.newBookings },
        { href: '/insights', label: 'Insights', icon: 'trend', component: 'Insights' },
        { href: '/campaigns', label: 'Campaigns', icon: 'send', component: 'Campaigns' },
        { href: '/integrations', label: 'Integrations', icon: 'plug', component: 'Integrations' },
        ...(user.teamRole ? [] : [{ href: '/billing', label: 'Billing', icon: 'wallet' as IconName, component: 'Billing' }]),
    ];

    return (
        <div className="app">
            <aside className={'side' + (menuOpen ? ' open' : '')}>
                <Link href="/dashboard" aria-label="Hamsukhan home">
                    <Logo />
                </Link>
                {isCustomer && stores.length > 1 && (
                    <div className="field" style={{ margin: '14px 0 0' }}>
                        <label htmlFor="store-switch">Store</label>
                        <select id="store-switch" className="input" value={auth.bot!.id} onChange={(e) => router.post(`/stores/${e.target.value}/switch`, {}, { preserveScroll: true })}>
                            {stores.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
                {isCustomer && <div className="nav-label">Workspace</div>}
                <nav className="nav">
                    {isCustomer && nav.map((n) => (
                        <Link key={n.href} href={n.href} className={component === n.component ? 'on' : ''}>
                            <Icon name={n.icon} />
                            {n.label}
                            {!!n.badge && <span className="badge">{n.badge}</span>}
                        </Link>
                    ))}
                </nav>
                {user.isAdmin && (
                    <>
                        <div className="nav-label">Super admin</div>
                        <nav className="nav">
                            {ADMIN_NAV.map((n) => (
                                <Link key={n.href} href={n.href} className={component === n.component || (n.component === 'Admin/Accounts' && component === 'Admin/AccountShow') ? 'on' : ''}>
                                    <Icon name={n.icon} />
                                    {n.label}
                                    {n.component === 'Admin/TopUps' && !!badges.pendingTopUps && <span className="badge">{badges.pendingTopUps}</span>}
                                    {n.component === 'Admin/Messages' && !!badges.newMessages && <span className="badge">{badges.newMessages}</span>}
                                </Link>
                            ))}
                        </nav>
                    </>
                )}
                <div className="side-foot">
                    {isCustomer && (<div className="mini-bal">
                        <small>Plan</small>
                        <div>
                            <strong style={plan.active ? undefined : { color: 'var(--danger)' }}>{plan.active ? plan.name : 'Not active'}</strong>
                            {!user.teamRole && (
                                <Link href="/billing" className="link" style={{ fontSize: 12.5 }}>
                                    {plan.active ? 'Renew' : 'Choose plan'}
                                </Link>
                            )}
                        </div>
                        <small style={{ display: 'block', marginTop: 6, fontWeight: 600 }}>
                            {plan.active ? `Unlimited calls · ${plan.daysLeft} day${plan.daysLeft === 1 ? '' : 's'} left` : 'Your assistant is not taking calls'}
                        </small>
                    </div>)}
                    <nav className="nav">
                        <Link href="/settings" className={component === 'Settings' ? 'on' : ''}>
                            <Icon name="settings" />
                            Settings
                        </Link>
                        <Link href="/logout" method="post" as="button">
                            <Icon name="logout" />
                            Log out
                        </Link>
                    </nav>
                    <div className="user">
                        <Avatar url={user.avatarUrl} initials={user.initials} />
                        <div style={{ minWidth: 0 }}>
                            <p>{user.name}</p>
                            <span style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis' }}>{user.email}</span>
                        </div>
                    </div>
                </div>
            </aside>
            {menuOpen && <div className="scrim on" style={{ zIndex: 45 }} onClick={() => setMenuOpen(false)} />}

            <div className="main">
                <header className="top">
                    <button className="icon-btn menu-btn" onClick={() => setMenuOpen(true)} aria-label="Open menu">
                        <Icon name="menu" />
                    </button>
                    <div>
                        <div className="crumb">{crumb}</div>
                        <h1>{title}</h1>
                    </div>
                    <div className="grow" />
                    {isCustomer && <button className="btn btn-ghost hide-sm" onClick={() => openModal('test')}>
                        <Icon name="mic" />
                        Test your bot
                    </button>}
                    <button className="icon-btn" onClick={toggle} aria-label="Toggle theme">
                        <Icon name={dark ? 'sun' : 'moon'} />
                    </button>
                </header>
                <main className="content">
                    {auth.impersonator && (
                        <div className="alert imp">
                            <Icon name="eye" />
                            <span className="grow">
                                You're viewing <b>{user.name}</b>'s account as super admin ({auth.impersonator.name}). Everything you do here happens on their account.
                            </span>
                            <Link href="/impersonation/stop" method="post" as="button" className="btn btn-sm btn-ink">
                                Return to admin
                            </Link>
                        </div>
                    )}
                    {isCustomer && page.props.site?.announcement && (
                        <div className="alert imp">
                            <Icon name="alert" />
                            <span className="grow">{page.props.site.announcement}</span>
                        </div>
                    )}
                    {isCustomer && !user.verified && !auth.impersonator && (
                        <div className="alert warn">
                            <Icon name="mail" />
                            <span className="grow">Please verify your email ({user.email}) so lead alerts reach you.</span>
                            <Link href="/email/verification-notification" method="post" as="button" className="btn btn-sm btn-ghost">
                                Send link again
                            </Link>
                        </div>
                    )}
                    {isCustomer && component !== 'Billing' && (!plan.active || plan.daysLeft <= 3) && (
                        <div className={'alert ' + (plan.active ? 'warn' : 'danger')}>
                            <Icon name="alert" />
                            <span className="grow">
                                {plan.active
                                    ? `Your ${plan.name} plan ends in ${plan.daysLeft} day${plan.daysLeft === 1 ? '' : 's'}. Renew it so your assistant keeps taking calls.`
                                    : 'Your monthly plan is not active, so your assistant is not taking calls.'}
                                {user.teamRole && ' Ask the account owner to renew it.'}
                            </span>
                            {!user.teamRole && (
                                <Link href="/billing" className="btn btn-sm btn-primary">
                                    {plan.active ? 'Renew' : 'Choose a plan'}
                                </Link>
                            )}
                        </div>
                    )}
                    <section className="page" key={component}>
                        {children}
                    </section>
                </main>
            </div>
        </div>
    );
}

export default function AppLayout({ children }: { children: ReactNode }) {
    return (
        <UIProvider
            renderModals={(open, close) => (
                <>
                    <TestCallModal open={open === 'test'} onClose={close} />
                </>
            )}
        >
            <Shell>{children}</Shell>
        </UIProvider>
    );
}
