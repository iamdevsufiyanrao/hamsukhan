import { Link, useForm } from '@inertiajs/react';
import Icon from '@/components/Icon';
import { FieldError } from '@/components/ui';
import AuthLayout from '@/layouts/AuthLayout';

export default function ForgotPassword({ status }: { status?: string }) {
    const form = useForm({ email: '' });

    return (
        <AuthLayout title="Forgot password?" subtitle="No worries, we'll email you a link to reset it.">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/forgot-password');
                }}
            >
                {status && (
                    <div className="notice">
                        <Icon name="mail" className="i i-sm" />
                        {status}
                    </div>
                )}
                <div className="field">
                    <label htmlFor="email">Email</label>
                    <input id="email" className={'input' + (form.errors.email ? ' bad' : '')} type="email" placeholder="you@business.com" autoComplete="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoFocus />
                    <FieldError msg={form.errors.email} />
                </div>
                <button className="btn btn-primary" type="submit" disabled={form.processing}>
                    {form.processing ? 'Sending…' : 'Send reset link'}
                </button>
            </form>
            <Link href="/login" className="back-link">
                <Icon name="chev" className="i i-sm" />
                Back to log in
            </Link>
        </AuthLayout>
    );
}
