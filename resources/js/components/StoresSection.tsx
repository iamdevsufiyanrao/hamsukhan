import { Link, router, useForm } from '@inertiajs/react';
import Icon from './Icon';
import { FieldError } from './ui';

export type Stores = { limit: number; current: number; list: { id: number; name: string; website: string | null; installed: boolean; active: boolean }[] };

/** Settings: the account's stores (one assistant each). Everyone can switch; the owner adds and removes. */
export default function StoresSection({ stores, isOwner }: { stores: Stores; isOwner: boolean }) {
    const form = useForm({ name: '' });

    const remove = (id: number, name: string) => {
        const typed = prompt(`Remove "${name}"? Its assistant, calls, bookings and connectors are deleted for good.\n\nType DELETE to confirm.`);
        if (typed !== null) router.delete(`/stores/${id}`, { data: { confirm: typed }, preserveScroll: true });
    };

    return (
        <div className="set-grid">
            <div>
                <h4>Stores</h4>
                <p>
                    Each store has its own assistant, voice, widget code, calls and bookings. Your plan includes {stores.limit} store{stores.limit > 1 ? 's' : ''}. Switch between them from the sidebar.
                </p>
            </div>
            <div className="stack" style={{ maxWidth: 560 }}>
                {stores.list.map((s) => (
                    <div key={s.id} style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', borderBottom: '1px solid var(--border)', paddingBottom: 8 }}>
                        <span style={{ flex: 1, minWidth: 180 }}>
                            <b>{s.name}</b>
                            {s.id === stores.current && <span className="chip approved" style={{ marginLeft: 8 }}>Open now</span>}
                            <span className="hint" style={{ display: 'block' }}>
                                {s.website ?? 'No website yet'} · {s.installed ? 'widget installed' : 'widget not installed'}
                                {!s.active && ' · switched off'}
                            </span>
                        </span>
                        {s.id !== stores.current && (
                            <button className="btn btn-ghost btn-sm" onClick={() => router.post(`/stores/${s.id}/switch`, {}, { preserveScroll: true })}>
                                Open
                            </button>
                        )}
                        {isOwner && stores.list.length > 1 && (
                            <button className="btn btn-ghost btn-sm" title="Remove store" onClick={() => remove(s.id, s.name)}>
                                <Icon name="trash" className="i i-sm" />
                            </button>
                        )}
                    </div>
                ))}
                {isOwner &&
                    (stores.list.length < stores.limit ? (
                        <form
                            className="stack"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post('/stores', { onSuccess: () => form.reset() });
                            }}
                        >
                            <div className="field">
                                <label htmlFor="store-name">New store name</label>
                                <input id="store-name" className={'input' + (form.errors.name ? ' bad' : '')} placeholder="e.g. Smart Geeks · DHA branch" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                <FieldError msg={form.errors.name} />
                            </div>
                            <button className="btn btn-primary btn-sm" style={{ justifySelf: 'start' }} disabled={form.processing || !form.data.name}>
                                <Icon name="plus" className="i i-sm" />
                                Add store
                            </button>
                        </form>
                    ) : (
                        <p className="hint">
                            {stores.limit === 1 ? (
                                <>
                                    Need more stores? The <Link href="/billing">Business plan</Link> includes 5 stores and 5 users.
                                </>
                            ) : (
                                `You are using all ${stores.limit} stores of your plan.`
                            )}
                        </p>
                    ))}
            </div>
        </div>
    );
}
