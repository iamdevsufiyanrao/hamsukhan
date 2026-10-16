import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/Icon';
import { Avatar, FieldError, Modal, PasswordInput } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import type { SharedProps } from '@/types';

type Props = { profile: { name: string; email: string; timezone: string }; timezones: string[] };

export default function Settings({ profile, timezones }: Props) {
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
