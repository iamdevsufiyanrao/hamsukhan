import { router, useForm } from '@inertiajs/react';
import Icon from '@/components/Icon';
import AuthLayout from '@/layouts/AuthLayout';

export default function VerifyEmail({ email, status }: { email: string; status?: string }) {
    const form = useForm({});

    return (
        <AuthLayout title="Verify your email" subtitle={`We sent a link to ${email}. Click it to activate your account.`}>
            {status && (
                <div className="notice">
                    <Icon name="mail" className="i i-sm" />
                    {status}
                </div>
            )}
            <p className="auth-alt" style={{ marginTop: 0, textAlign: 'left' }}>
                Can't find it? Check your spam folder, or send a new link.
            </p>
            <button className="btn btn-primary" disabled={form.processing} onClick={() => form.post('/email/verification-notification')}>
                {form.processing ? 'Sending…' : 'Send a new link'}
            </button>
            <button className="btn btn-ghost" style={{ marginTop: 10 }} onClick={() => router.post('/logout')}>
                Log out
            </button>
        </AuthLayout>
    );
}
