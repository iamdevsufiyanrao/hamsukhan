import { useForm } from '@inertiajs/react';
import { FieldError, PasswordInput } from '@/components/ui';
import AuthLayout from '@/layouts/AuthLayout';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    return (
        <AuthLayout title="Set a new password" subtitle="Choose a password with at least 8 characters.">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
                }}
            >
                <div className="field">
                    <label htmlFor="email">Email</label>
                    <input id="email" className={'input' + (form.errors.email ? ' bad' : '')} type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                    <FieldError msg={form.errors.email} />
                </div>
                <div className="field">
                    <label htmlFor="password">New password</label>
                    <PasswordInput id="password" value={form.data.password} onChange={(v) => form.setData('password', v)} invalid={!!form.errors.password} autoComplete="new-password" />
                    <FieldError msg={form.errors.password} />
                </div>
                <div className="field">
                    <label htmlFor="password2">Confirm new password</label>
                    <PasswordInput id="password2" value={form.data.password_confirmation} onChange={(v) => form.setData('password_confirmation', v)} autoComplete="new-password" />
                </div>
                <button className="btn btn-primary" type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Update password'}
                </button>
            </form>
        </AuthLayout>
    );
}
