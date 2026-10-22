import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/Icon';
import { Avatar, FieldError, Modal, PasswordInput } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { timeAgo } from '@/lib/format';
import type { SharedProps } from '@/types';

type Notifications = {
    email: boolean;
    whatsapp: boolean;
    whatsappNumber: string | null;
    dailySummary: boolean;
    monthlyCapPkr: number | null;
    spentThisMonthPkr: number;
    whatsappAvailable: boolean;
    recent: { kind: string; channel: string; status: string; error: string | null; at: string }[];
};
type Props = { profile: { name: string; email: string; timezone: string }; timezones: string[]; notifications: Notifications };

const KIND_LABEL: Record<string, string> = { lead: 'New lead', low_balance: 'Low balance', daily_summary: 'Daily summary', integration_failed: 'Connector failed', cap_reached: 'Spending limit' };

/** Where lead alerts go, and the monthly spending limit. */
function NotificationsSection({ n }: { n: Notifications }) {
    const form = useForm({ email: n.email, whatsapp: n.whatsapp, whatsappNumber: n.whatsappNumber ?? '', dailySummary: n.dailySummary, monthlyCapPkr: n.monthlyCapPkr === null ? '' : String(n.monthlyCapPkr) });
    const save = () => {
        form.transform((d) => ({ ...d, monthlyCapPkr: d.monthlyCapPkr === '' ? null : Number(d.monthlyCapPkr) }));
        form.patch('/settings/notifications', { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    return (
        <div className="set-grid">
            <div>
                <h4>Alerts and spending limit</h4>
                <p>Get every new booking, callback request and low-balance warning on email or WhatsApp. The limit pauses your assistant once a month's calls cost that much.</p>
            </div>
            <div className="stack" style={{ maxWidth: 520 }}>
                <label className="check" style={{ marginTop: 0 }}>
                    <input type="checkbox" checked={form.data.email} onChange={(e) => form.setData('email', e.target.checked)} />
                    <span>Email me new leads and alerts</span>
                </label>
                <label className="check" style={{ marginTop: 0 }}>
                    <input type="checkbox" checked={form.data.whatsapp} disabled={!n.whatsappAvailable && !form.data.whatsapp} onChange={(e) => form.setData('whatsapp', e.target.checked)} />
                    <span>WhatsApp me new leads and alerts{!n.whatsappAvailable && ' (not switched on for the platform yet)'}</span>
                </label>
                {form.data.whatsapp && (
                    <div className="field">
                        <label htmlFor="wa">WhatsApp number</label>
                        <input id="wa" className={'input' + (form.errors.whatsappNumber ? ' bad' : '')} type="tel" placeholder="0300 1234567" value={form.data.whatsappNumber} onChange={(e) => form.setData('whatsappNumber', e.target.value)} />
                        <FieldError msg={form.errors.whatsappNumber} />
                    </div>
                )}
                <label className="check" style={{ marginTop: 0 }}>
                    <input type="checkbox" checked={form.data.dailySummary} onChange={(e) => form.setData('dailySummary', e.target.checked)} />
                    <span>Daily summary at 9 PM (only on days with calls or leads)</span>
                </label>
                <div className="field">
                    <label htmlFor="cap">Monthly spending limit (Rs)</label>
                    <input id="cap" className={'input' + (form.errors.monthlyCapPkr ? ' bad' : '')} type="number" min={500} step={100} placeholder="No limit" value={form.data.monthlyCapPkr} onChange={(e) => form.setData('monthlyCapPkr', e.target.value)} />
                    <span className="hint">Spent this month: Rs {n.spentThisMonthPkr.toLocaleString('en-PK')}. Leave empty for no limit.</span>
                    <FieldError msg={form.errors.monthlyCapPkr} />
                </div>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <button className="btn btn-primary" disabled={!form.isDirty || form.processing} onClick={save}>
                        Save alerts
                    </button>
                    <button className="btn btn-ghost" disabled={form.isDirty} onClick={() => router.post('/settings/notifications/test', {}, { preserveScroll: true })}>
                        <Icon name="send" className="i i-sm" />
                        Send a test alert
                    </button>
                </div>
                {n.recent.length > 0 && (
                    <div className="hint" style={{ display: 'grid', gap: 2 }}>
                        <b>Recent alerts</b>
                        {n.recent.map((r, i) => (
                            <span key={i} style={r.status === 'failed' ? { color: 'var(--danger)' } : undefined}>
                                {KIND_LABEL[r.kind] ?? r.kind} · {r.channel} · {r.status}
                                {r.error ? `: ${r.error}` : ''} · {timeAgo(r.at)}
                            </span>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

export default function Settings({ profile, timezones, notifications }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const user = auth!.user;
    const info = useForm(profile);
    const pw = useForm({ current_password: '', password: '', password_confirmation: '' });
    const del = useForm({ confirm: '', password: '' });
    const [delOpen, setDelOpen] = useState(false);

    const closeDelete = () => {
        setDelOpen(false);
        del.reset();
        del.clearErrors();
    };

    return (
        <>
            <Head title="Settings" />
            <div className="card">
                <div className="card-b" style={{ paddingTop: 4, paddingBottom: 4 }}>
                    <div className="set-grid">
                        <div>
                            <h4>Profile photo</h4>
                            <p>PNG or JPG, under 2MB.</p>
                        </div>
                        <div className="av-row">
                            <Avatar url={user.avatarUrl} initials={user.initials} size={64} />
                            <label className="btn btn-ghost btn-sm" style={{ cursor: 'pointer' }}>
                                <input
                                    type="file"
                                    accept="image/png,image/jpeg"
                                    hidden
                                    onChange={(e) => {
                                        const file = e.target.files?.[0];
                                        if (file) router.post('/settings/avatar', { avatar: file }, { forceFormData: true, preserveScroll: true });
                                        e.target.value = '';
                                    }}
                                />
                                <Icon name="upload" className="i i-sm" />
                                Upload new
                            </label>
                            {user.avatarUrl && (
                                <button className="btn btn-sm" style={{ color: 'var(--muted)' }} onClick={() => router.delete('/settings/avatar', { preserveScroll: true })}>
                                    Remove
                                </button>
                            )}
                        </div>
                    </div>

                    <div className="set-grid">
                        <div>
                            <h4>Personal info</h4>
                            <p>Your name shows up on invoices. The timezone sets when "today" starts on your dashboard.</p>
                        </div>
                        <form
                            className="stack"
                            style={{ maxWidth: 520 }}
                            onSubmit={(e) => {
                                e.preventDefault();
                                info.patch('/settings/profile', { preserveScroll: true, onSuccess: () => info.setDefaults() });
                            }}
                        >
                            <div className="field">
                                <label htmlFor="name">Full name</label>
                                <input id="name" className={'input' + (info.errors.name ? ' bad' : '')} value={info.data.name} onChange={(e) => info.setData('name', e.target.value)} />
                                <FieldError msg={info.errors.name} />
                            </div>
                            <div className="field">
                                <label htmlFor="email">Email</label>
                                <input id="email" type="email" className={'input' + (info.errors.email ? ' bad' : '')} value={info.data.email} onChange={(e) => info.setData('email', e.target.value)} />
                                <FieldError msg={info.errors.email} />
                            </div>
                            <div className="field">
                                <label htmlFor="tz">Timezone</label>
                                <select id="tz" className="select" value={info.data.timezone} onChange={(e) => info.setData('timezone', e.target.value)}>
                                    {timezones.map((t) => (
                                        <option key={t}>{t}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <button className="btn btn-primary" disabled={!info.isDirty || info.processing}>
                                    Save profile
                                </button>
                            </div>
                        </form>
                    </div>

                    {auth?.bot && <NotificationsSection n={notifications} />}

                    <div className="set-grid">
                        <div>
                            <h4>Password</h4>
                            <p>Use at least 8 characters.</p>
                        </div>
                        <form
                            className="stack"
                            style={{ maxWidth: 520 }}
                            onSubmit={(e) => {
                                e.preventDefault();
                                pw.put('/settings/password', { preserveScroll: true, onSuccess: () => pw.reset() });
                            }}
                        >
                            <div className="field">
                                <label htmlFor="cur">Current password</label>
                                <PasswordInput id="cur" value={pw.data.current_password} onChange={(v) => pw.setData('current_password', v)} invalid={!!pw.errors.current_password} autoComplete="current-password" />
                                <FieldError msg={pw.errors.current_password} />
                            </div>
                            <div className="grid-2">
                                <div className="field">
                                    <label htmlFor="new">New password</label>
                                    <PasswordInput id="new" value={pw.data.password} onChange={(v) => pw.setData('password', v)} invalid={!!pw.errors.password} autoComplete="new-password" />
                                    <FieldError msg={pw.errors.password} />
                                </div>
                                <div className="field">
                                    <label htmlFor="new2">Confirm</label>
                                    <PasswordInput id="new2" value={pw.data.password_confirmation} onChange={(v) => pw.setData('password_confirmation', v)} autoComplete="new-password" />
                                </div>
                            </div>
                            <div>
                                <button className="btn btn-ghost" disabled={pw.processing || !pw.data.password}>
                                    Change password
                                </button>
                            </div>
                        </form>
                    </div>

                    <div className="set-grid">
                        <div>
                            <h4>Danger zone</h4>
                            <p>Irreversible actions.</p>
                        </div>
                        <div className="danger-box">
                            <div>
                                <b>Delete account</b>
                                <p>Removes your bot, cloned voices, call recordings and remaining balance.</p>
                            </div>
                            <button className="btn btn-danger" onClick={() => setDelOpen(true)}>
                                Delete account
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <Modal
                open={delOpen}
                onClose={closeDelete}
                title="Delete your account?"
                desc="Your bot goes offline right away. Cloned voices, call recordings and any remaining balance are permanently removed."
                footer={
                    <>
                        <button className="btn btn-ghost" onClick={closeDelete}>
                            Cancel
                        </button>
                        <button className="btn btn-danger" disabled={del.data.confirm !== 'DELETE' || !del.data.password || del.processing} onClick={() => del.delete('/settings/account')}>
                            Delete account
                        </button>
                    </>
                }
            >
                <div className="stack">
                    <div className="field">
                        <label htmlFor="del">
                            Type <span className="mono">DELETE</span> to confirm
                        </label>
                        <input id="del" className="input" placeholder="DELETE" value={del.data.confirm} onChange={(e) => del.setData('confirm', e.target.value)} />
                    </div>
                    <div className="field">
                        <label htmlFor="delpw">Your password</label>
                        <PasswordInput id="delpw" value={del.data.password} onChange={(v) => del.setData('password', v)} invalid={!!del.errors.password} />
                        <FieldError msg={del.errors.password} />
                    </div>
                </div>
            </Modal>
        </>
    );
}

Settings.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
