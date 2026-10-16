import { Link, useForm, usePage } from '@inertiajs/react';
import Icon from '@/components/Icon';
import type { SharedProps } from '@/types';
import { FieldError, PasswordInput } from '@/components/ui';
import AuthLayout from '@/layouts/AuthLayout';

export default function Login({ status }: { status?: string }) {
    const form = useForm({ email: '', password: '', remember: false });
    // Errors from a redirect (e.g. "account suspended") arrive as page props, not form errors.
    const pageErrors = usePage<{ errors: Record<string, string> }>().props.errors;
    const emailError = form.errors.email ?? (form.isDirty ? undefined : pageErrors.email);

    return (
        <AuthLayout title="Welcome back" subtitle="Log in to manage your AI receptionist.">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/login', { onFinish: () => form.reset('password') });
                }}
            >
                {status && (
                    <div className="notice">
                        <Icon name="check" className="i i-sm" />
                        {status}
                    </div>
                )}
                <div className="field">
                    <label htmlFor="email">Email</label>
                    <input id="email" className={'input' + (emailError ? ' bad' : '')} type="email" placeholder="you@business.com" autoComplete="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoFocus />
                    <FieldError msg={emailError} />
                </div>
                <div className="field">
                    <label htmlFor="password">Password</label>
                    <PasswordInput id="password" placeholder="Enter your password" value={form.data.password} onChange={(v) => form.setData('password', v)} invalid={!!form.errors.password} autoComplete="current-password" />
                    <FieldError msg={form.errors.password} />
                </div>
                <div className="auth-row">
                    <label>
                        <input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} /> Remember me
                    </label>
                    <Link href="/forgot-password" className="link">
                        Forgot password?
                    </Link>
                </div>
                <button className="btn btn-primary" type="submit" disabled={form.processing}>
                    {form.processing ? 'Logging in…' : 'Log in'}
                </button>
            </form>
            {usePage<SharedProps>().props.site.allowSignups && (
                <p className="auth-alt">
                    Don't have an account? <Link href="/register">Create one</Link>
                </p>
            )}
        </AuthLayout>
    );
}
