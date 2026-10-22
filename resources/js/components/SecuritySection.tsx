import { router, useForm } from '@inertiajs/react';
import QRCode from 'qrcode';
import { useEffect, useState } from 'react';
import Icon from './Icon';
import { FieldError, PasswordInput } from './ui';
import { timeAgo } from '@/lib/format';

export type TwoFactor = { enabled: boolean; setup: { secret: string; uri: string } | null; codesLeft: number; recoveryCodes: string[] | null };
export type DeviceSession = { current: boolean; ip: string | null; device: string; lastActive: string };

/** Settings: two-step login (authenticator app) and signed-in devices. */
export default function SecuritySection({ twoFactor, sessions }: { twoFactor: TwoFactor; sessions: DeviceSession[] }) {
    const pw = useForm({ password: '' });
    const code = useForm({ code: '' });
    const [qr, setQr] = useState<string | null>(null);
    const [action, setAction] = useState<'enable' | 'disable' | 'codes' | 'logout' | null>(null);

    useEffect(() => {
        if (twoFactor.setup) QRCode.toDataURL(twoFactor.setup.uri, { margin: 1, width: 200 }).then(setQr, () => setQr(null));
        else setQr(null);
    }, [twoFactor.setup?.uri]);

    const withPassword = (kind: NonNullable<typeof action>) => {
        const opts = { preserveScroll: true, onSuccess: () => (pw.reset(), setAction(null)) };
        if (kind === 'enable') pw.post('/settings/two-factor', opts);
        if (kind === 'disable') pw.delete('/settings/two-factor', opts);
        if (kind === 'codes') pw.post('/settings/two-factor/recovery-codes', opts);
        if (kind === 'logout') pw.post('/settings/sessions/logout-others', opts);
    };

    const passwordBox = (kind: NonNullable<typeof action>, label: string) =>
        action === kind && (
            <div className="stack" style={{ maxWidth: 360 }}>
                <PasswordInput id={`pw-${kind}`} placeholder="Your password" value={pw.data.password} onChange={(v) => pw.setData('password', v)} invalid={!!pw.errors.password} autoComplete="current-password" />
                <FieldError msg={pw.errors.password} />
                <div style={{ display: 'flex', gap: 8 }}>
                    <button className="btn btn-primary btn-sm" disabled={!pw.data.password || pw.processing} onClick={() => withPassword(kind)}>
                        {label}
                    </button>
                    <button className="btn btn-ghost btn-sm" onClick={() => (setAction(null), pw.reset(), pw.clearErrors())}>
                        Cancel
                    </button>
                </div>
            </div>
        );

    return (
        <>
            <div className="set-grid">
                <div>
                    <h4>Two-step login</h4>
                    <p>Ask for a code from an authenticator app (Google Authenticator, Microsoft Authenticator) after your password. Strongly recommended for owners and admins.</p>
                </div>
                <div className="stack" style={{ maxWidth: 520 }}>
                    {twoFactor.enabled ? (
                        <>
                            <div className="notice" style={{ background: 'var(--info-soft)', color: 'var(--info)' }}>
                                <Icon name="shield" className="i i-sm" />
                                On · {twoFactor.codesLeft} recovery code{twoFactor.codesLeft === 1 ? '' : 's'} left
                            </div>
                            {!action && (
                                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                    <button className="btn btn-ghost btn-sm" onClick={() => setAction('codes')}>
                                        New recovery codes
                                    </button>
                                    <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => setAction('disable')}>
                                        Turn off
                                    </button>
                                </div>
                            )}
                            {passwordBox('codes', 'Make new codes')}
                            {passwordBox('disable', 'Turn off two-step login')}
                        </>
                    ) : twoFactor.setup ? (
                        <>
                            <p className="hint">1. Scan this with your authenticator app (or type the key). 2. Enter the 6-digit code it shows.</p>
                            {qr && <img src={qr} width={200} height={200} alt="QR code for your authenticator app" style={{ borderRadius: 8, background: '#fff' }} />}
                            <code className="mono" style={{ wordBreak: 'break-all' }}>{twoFactor.setup.secret}</code>
                            <div style={{ display: 'flex', gap: 8, maxWidth: 320 }}>
                                <input className={'input mono' + (code.errors.code ? ' bad' : '')} inputMode="numeric" autoComplete="one-time-code" placeholder="123456" maxLength={7} value={code.data.code} onChange={(e) => code.setData('code', e.target.value)} />
                                <button className="btn btn-primary" disabled={code.processing || code.data.code.length < 6} onClick={() => code.post('/settings/two-factor/confirm', { preserveScroll: true, onSuccess: () => code.reset() })}>
                                    Confirm
                                </button>
                            </div>
                            <FieldError msg={code.errors.code} />
                        </>
                    ) : (
                        <>
                            {!action && (
                                <button className="btn btn-ghost btn-sm" style={{ justifySelf: 'start' }} onClick={() => setAction('enable')}>
                                    <Icon name="shield" className="i i-sm" />
                                    Turn on two-step login
                                </button>
                            )}
                            {passwordBox('enable', 'Continue')}
                        </>
                    )}
                    {twoFactor.recoveryCodes && (
                        <div className="notice" style={{ display: 'block' }}>
                            <b>Save these recovery codes now.</b> Each one works once if you lose your phone. They will not be shown again.
                            <div className="mono" style={{ display: 'grid', gridTemplateColumns: 'repeat(2, max-content)', gap: '4px 20px', marginTop: 8 }}>
                                {twoFactor.recoveryCodes.map((c) => (
                                    <span key={c}>{c}</span>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <div className="set-grid">
                <div>
                    <h4>Signed-in devices</h4>
                    <p>If you see a device you don't know, log it out and change your password.</p>
                </div>
                <div className="stack" style={{ maxWidth: 520 }}>
                    {sessions.length === 0 && <span className="hint">Not available on this server.</span>}
                    {sessions.map((s, i) => (
                        <div key={i} style={{ display: 'flex', justifyContent: 'space-between', gap: 10, borderBottom: '1px solid var(--border)', paddingBottom: 6 }}>
                            <span>
                                <b>{s.device}</b>
                                {s.current && <span className="chip ok" style={{ marginLeft: 6 }}>this device</span>}
                                <span className="hint" style={{ display: 'block' }}>{s.ip ?? 'unknown IP'}</span>
                            </span>
                            <span className="hint">{s.current ? 'now' : timeAgo(s.lastActive)}</span>
                        </div>
                    ))}
                    {!action && sessions.length > 1 && (
                        <button className="btn btn-ghost btn-sm" style={{ justifySelf: 'start' }} onClick={() => setAction('logout')}>
                            <Icon name="logout" className="i i-sm" />
                            Log out other devices
                        </button>
                    )}
                    {passwordBox('logout', 'Log them out')}
                </div>
            </div>
        </>
    );
}

export const reloadSecurity = () => router.reload({ only: ['twoFactor', 'sessions'] });
