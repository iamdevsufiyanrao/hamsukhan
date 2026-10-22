import { Head, router, useForm } from '@inertiajs/react';
import Icon from '@/components/Icon';
import { FieldError } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { fmtDateLong } from '@/lib/format';

type Counts = { pending: number; calling: number; done: number; failed: number; skipped: number };
type Campaign = { id: number; name: string; purpose: string; firstMessage: string; status: 'draft' | 'running' | 'paused' | 'done'; perRun: number; createdAt: string; counts: Counts; failures: { name: string | null; phone: string; error: string | null }[] };
type Props = { available: boolean; rate: number; campaigns: Campaign[] };

const STATUS_CHIP: Record<Campaign['status'], string> = { draft: '', running: 'ok', paused: 'warn', done: '' };

/** The bot calls a list of customers: appointment reminders, follow-ups, offers. */
export default function Campaigns({ available, rate, campaigns }: Props) {
    const form = useForm({
        name: '',
        purpose: '',
        firstMessage: 'Assalam o Alaikum {name}! Main aap ki salon ki AI assistant bol rahi hoon.',
        contacts: '',
        perRun: 3,
        consent: false,
    });
    const lines = form.data.contacts.split('\n').filter((l) => l.trim()).length;

    return (
        <>
            <Head title="Campaigns" />
            {!available && (
                <div className="notice" style={{ marginBottom: 16 }}>
                    <Icon name="phone" className="i i-sm" />
                    Campaigns need a phone number for outbound calls. Ask support to add one to your account.
                </div>
            )}
            <div className="two-col">
                <div className="card">
                    <div className="card-h">
                        <div>
                            <h3>Your campaigns</h3>
                            <p>Calls go out only during your opening hours (10 AM to 8 PM if none are set), a few at a time. Each call is billed per minute (about Rs {rate}).</p>
                        </div>
                    </div>
                    <div className="card-b" style={{ display: 'grid', gap: 12 }}>
                        {campaigns.length === 0 && <div className="empty">No campaigns yet.</div>}
                        {campaigns.map((c) => {
                            const total = Object.values(c.counts).reduce((a, b) => a + b, 0);
                            const finished = c.counts.done + c.counts.failed + c.counts.skipped;
                            return (
                                <div key={c.id} style={{ borderBottom: '1px solid var(--border)', paddingBottom: 12, display: 'grid', gap: 6 }}>
                                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                                        <b>{c.name}</b>
                                        <span className={'chip ' + STATUS_CHIP[c.status]}>{c.status}</span>
                                        <span className="hint">{fmtDateLong(c.createdAt)}</span>
                                        <span style={{ marginLeft: 'auto', display: 'flex', gap: 6 }}>
                                            {(c.status === 'draft' || c.status === 'paused') && (
                                                <button className="btn btn-primary btn-sm" disabled={!available} onClick={() => confirm(`Start calling ${c.counts.pending} people?`) && router.patch(`/campaigns/${c.id}`, { status: 'running' }, { preserveScroll: true })}>
                                                    <Icon name="play" className="i i-sm" />
                                                    {c.status === 'paused' ? 'Resume' : 'Start'}
                                                </button>
                                            )}
                                            {c.status === 'running' && (
                                                <button className="btn btn-ghost btn-sm" onClick={() => router.patch(`/campaigns/${c.id}`, { status: 'paused' }, { preserveScroll: true })}>
                                                    <Icon name="pause" className="i i-sm" />
                                                    Pause
                                                </button>
                                            )}
                                            <button className="btn btn-ghost btn-sm" title="Delete" onClick={() => confirm(`Delete "${c.name}"?`) && router.delete(`/campaigns/${c.id}`, { preserveScroll: true })}>
                                                <Icon name="trash" className="i i-sm" />
                                            </button>
                                        </span>
                                    </div>
                                    <div style={{ height: 8, borderRadius: 99, background: 'var(--sunken)', overflow: 'hidden' }}>
                                        <div style={{ height: '100%', width: `${total ? (finished / total) * 100 : 0}%`, background: 'var(--accent)' }} />
                                    </div>
                                    <span className="hint">
                                        {c.counts.done} called · {c.counts.calling} on a call now · {c.counts.pending} waiting{c.counts.failed ? ` · ${c.counts.failed} failed` : ''} · {c.perRun} at a time
                                    </span>
                                    {c.failures.length > 0 && (
                                        <span className="hint" style={{ color: 'var(--danger)' }}>
                                            {c.failures.map((f) => `${f.name ?? f.phone}: ${f.error ?? 'failed'}`).join(' · ')}
                                        </span>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="card">
                    <div className="card-h">
                        <div>
                            <h3>New campaign</h3>
                            <p>Only call people who agreed to hear from you, for example your own customers.</p>
                        </div>
                    </div>
                    <form
                        className="card-b stack"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/campaigns', { preserveScroll: true, onSuccess: () => form.reset() });
                        }}
                    >
                        <div className="field">
                            <label htmlFor="c-name">Name</label>
                            <input id="c-name" className={'input' + (form.errors.name ? ' bad' : '')} placeholder="e.g. Eid offer follow-up" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            <FieldError msg={form.errors.name} />
                        </div>
                        <div className="field">
                            <label htmlFor="c-purpose">What should the bot do on each call?</label>
                            <textarea id="c-purpose" className={'textarea' + (form.errors.purpose ? ' bad' : '')} style={{ minHeight: 80 }} placeholder="Tell them about 20% off facials this week and offer to book a slot." value={form.data.purpose} onChange={(e) => form.setData('purpose', e.target.value)} />
                            <FieldError msg={form.errors.purpose} />
                        </div>
                        <div className="field">
                            <label htmlFor="c-first">First sentence</label>
                            <input id="c-first" className="input" dir="auto" value={form.data.firstMessage} onChange={(e) => form.setData('firstMessage', e.target.value)} />
                            <span className="hint">{'{name}'} is replaced with the contact's name.</span>
                            <FieldError msg={form.errors.firstMessage} />
                        </div>
                        <div className="field">
                            <label htmlFor="c-contacts">Contacts, one per line ({lines})</label>
                            <textarea id="c-contacts" className={'textarea mono' + (form.errors.contacts ? ' bad' : '')} style={{ minHeight: 120 }} placeholder={'Ayesha, 0300 1234567\nBilal, 0321 7654321\n03331112222'} value={form.data.contacts} onChange={(e) => form.setData('contacts', e.target.value)} />
                            <span className="hint">Paste from Excel or WhatsApp. Duplicates and invalid numbers are skipped. Up to 500.</span>
                            <FieldError msg={form.errors.contacts} />
                        </div>
                        <div className="field" style={{ maxWidth: 200 }}>
                            <label htmlFor="c-run">Calls at the same time</label>
                            <select id="c-run" className="select" value={form.data.perRun} onChange={(e) => form.setData('perRun', +e.target.value)}>
                                {[1, 2, 3, 5, 10].map((n) => (
                                    <option key={n} value={n}>
                                        {n}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <label className="check">
                            <input type="checkbox" checked={form.data.consent} onChange={(e) => form.setData('consent', e.target.checked)} />
                            <span>Everyone on this list agreed to be contacted by my business. The bot says it is an AI.</span>
                        </label>
                        <FieldError msg={form.errors.consent} />
                        <button className="btn btn-primary" disabled={form.processing || !form.data.consent}>
                            Save campaign
                        </button>
                    </form>
                </div>
            </div>
        </>
    );
}

Campaigns.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
