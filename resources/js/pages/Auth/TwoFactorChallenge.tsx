import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/Icon';
import { FieldError } from '@/components/ui';
import AuthLayout from '@/layouts/AuthLayout';

export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const form = useForm({ code: '', recovery_code: '' });

    return (
        <AuthLayout title="Two-step login" subtitle={recovery ? 'Enter one of your recovery codes.' : 'Open your authenticator app and enter the 6-digit code.'}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((d) => (recovery ? { recovery_code: d.recovery_code } : { code: d.code }));
                    form.post('/two-factor-challenge');
                }}
            >
                {recovery ? (
                    <div className="field">
                        <label htmlFor="rc">Recovery code</label>
                        <input id="rc" className={'input mono' + (form.errors.code ? ' bad' : '')} placeholder="abcde-12345" autoComplete="off" value={form.data.recovery_code} onChange={(e) => form.setData('recovery_code', e.target.value)} autoFocus />
                    </div>
                ) : (
                    <div className="field">
                        <label htmlFor="code">Code</label>
                        <input id="code" className={'input mono' + (form.errors.code ? ' bad' : '')} inputMode="numeric" autoComplete="one-time-code" maxLength={7} placeholder="123456" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} autoFocus />
                    </div>
                )}
                <FieldError msg={form.errors.code} />
                <button className="btn btn-primary" type="submit" disabled={form.processing}>
                    {form.processing ? 'Checking…' : 'Log in'}
                </button>
            </form>
            <button type="button" className="link" style={{ marginTop: 14 }} onClick={() => setRecovery(!recovery)}>
                {recovery ? 'Use the authenticator code instead' : 'Lost your phone? Use a recovery code'}
            </button>
            <Link href="/login" className="back-link">
                <Icon name="chev" className="i i-sm" />
                Back to log in
            </Link>
        </AuthLayout>
    );
}
