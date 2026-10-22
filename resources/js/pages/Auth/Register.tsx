import { Link, useForm, usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';
import { FieldError, PasswordInput } from '@/components/ui';
import AuthLayout from '@/layouts/AuthLayout';

export default function Register() {
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '', terms: false });
    const { site } = usePage<SharedProps>().props;

    if (!site.allowSignups) {
        return (
            <AuthLayout title="Sign-ups are closed" subtitle="We're not taking new accounts right now. Please check back soon.">
                <p className="auth-alt" style={{ marginTop: 0 }}>
                    Already have an account? <Link href="/login">Log in</Link>
                </p>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout title="Create your account" subtitle="Set up your AI receptionist in a few minutes.">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
                }}
            >
                <div className="field">
                    <label htmlFor="name">Full name</label>
                    <input id="name" className={'input' + (form.errors.name ? ' bad' : '')} placeholder="Your name" autoComplete="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus />
                    <FieldError msg={form.errors.name} />
                </div>
                <div className="field">
                    <label htmlFor="email">Email</label>
                    <input id="email" className={'input' + (form.errors.email ? ' bad' : '')} type="email" placeholder="you@business.com" autoComplete="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                    <FieldError msg={form.errors.email} />
                </div>
                <div className="grid-2">
                    <div className="field">
                        <label htmlFor="password">Password</label>
                        <PasswordInput id="password" placeholder="8+ characters" value={form.data.password} onChange={(v) => form.setData('password', v)} invalid={!!form.errors.password} autoComplete="new-password" />
                    </div>
                    <div className="field">
                        <label htmlFor="password2">Confirm</label>
                        <PasswordInput id="password2" placeholder="Re-enter" value={form.data.password_confirmation} onChange={(v) => form.setData('password_confirmation', v)} autoComplete="new-password" />
                    </div>
                </div>
                <FieldError msg={form.errors.password} />
                <label className="check" style={{ marginTop: 0 }}>
                    <input type="checkbox" checked={form.data.terms} onChange={(e) => form.setData('terms', e.target.checked)} />
                    <span>
                        I agree to the{' '}
                        <a href={site.termsUrl ?? '/terms'} target="_blank" rel="noreferrer" className="link">
                            Terms of Service
                        </a>{' '}
                        and{' '}
                        <a href={site.privacyUrl ?? '/privacy'} target="_blank" rel="noreferrer" className="link">
                            Privacy Policy
                        </a>
                    </span>
                </label>
                <FieldError msg={form.errors.terms} />
                <button className="btn btn-primary" type="submit" disabled={!form.data.terms || form.processing}>
                    {form.processing ? 'Creating…' : 'Create account'}
                </button>
            </form>
            <p className="auth-alt">
                Already have an account? <Link href="/login">Log in</Link>
            </p>
        </AuthLayout>
    );
}
