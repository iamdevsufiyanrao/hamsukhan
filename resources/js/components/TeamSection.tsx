import { router, useForm } from '@inertiajs/react';
import Icon from './Icon';
import { FieldError } from './ui';

export type Team = { max: number; members: { id: number; name: string; email: string; role: string }[] };

const ROLES: [string, string][] = [
    ['admin', 'Admin: everything except billing'],
    ['operator', 'Operator: leads, live calls, test calls'],
    ['viewer', 'Viewer: can only look'],
];

/** Settings: the account owner adds staff with a role. */
export default function TeamSection({ team }: { team: Team }) {
    const form = useForm({ name: '', email: '', role: 'operator' });

    return (
        <div className="set-grid">
            <div>
                <h4>Team</h4>
                <p>Give staff their own login to your account. They get an email to set a password. Billing and the team stay with you.</p>
            </div>
            <div className="stack" style={{ maxWidth: 560 }}>
                {team.members.map((m) => (
                    <div key={m.id} style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', borderBottom: '1px solid var(--border)', paddingBottom: 8 }}>
                        <span style={{ flex: 1, minWidth: 180 }}>
                            <b>{m.name}</b>
                            <span className="hint" style={{ display: 'block' }}>{m.email}</span>
                        </span>
                        <select className="select sm" value={m.role} aria-label={`Role of ${m.name}`} onChange={(e) => router.patch(`/settings/team/${m.id}`, { role: e.target.value }, { preserveScroll: true })}>
                            {ROLES.map(([v]) => (
                                <option key={v} value={v}>
                                    {v[0].toUpperCase() + v.slice(1)}
                                </option>
                            ))}
                        </select>
                        <button className="btn btn-ghost btn-sm" title="Remove" onClick={() => confirm(`Remove ${m.name} from your team? They are logged out at once.`) && router.delete(`/settings/team/${m.id}`, { preserveScroll: true })}>
                            <Icon name="trash" className="i i-sm" />
                        </button>
                    </div>
                ))}
                {team.members.length < team.max && (
                    <form
                        className="stack"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/settings/team', { preserveScroll: true, onSuccess: () => form.reset() });
                        }}
                    >
                        <div className="grid-2">
                            <div className="field">
                                <label htmlFor="tm-name">Name</label>
                                <input id="tm-name" className={'input' + (form.errors.name ? ' bad' : '')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                <FieldError msg={form.errors.name} />
                            </div>
                            <div className="field">
                                <label htmlFor="tm-email">Email</label>
                                <input id="tm-email" type="email" className={'input' + (form.errors.email ? ' bad' : '')} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                                <FieldError msg={form.errors.email} />
                            </div>
                        </div>
                        <div className="field">
                            <label htmlFor="tm-role">Role</label>
                            <select id="tm-role" className="select" value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                                {ROLES.map(([v, l]) => (
                                    <option key={v} value={v}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <button className="btn btn-primary btn-sm" style={{ justifySelf: 'start' }} disabled={form.processing || !form.data.name || !form.data.email}>
                            <Icon name="plus" className="i i-sm" />
                            Add team member
                        </button>
                    </form>
                )}
            </div>
        </div>
    );
}
