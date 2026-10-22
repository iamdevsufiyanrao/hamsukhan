import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/Icon';
import AppLayout from '@/layouts/AppLayout';

type PVoice = { id: string; name: string; category: string; previewUrl: string | null; labels: Record<string, string>; usedBy: { id: number; name: string; email: string }[] };
type Props = { enabled: boolean; error: string | null; voices: PVoice[]; accounts: { id: number; label: string }[] };

/** Admin: voices already in the ElevenLabs account (e.g. cloned in the ElevenLabs dashboard) and giving one to a customer. */
export default function ProviderVoices({ enabled, error, voices, accounts }: Props) {
    const [filter, setFilter] = useState<'all' | 'cloned' | 'premade'>('cloned');
    const [pick, setPick] = useState<Record<string, { userId: string; activate: boolean }>>({});
    const shown = voices.filter((v) => filter === 'all' || (filter === 'premade' ? v.category === 'premade' : v.category !== 'premade'));

    const assign = (v: PVoice) => {
        const p = pick[v.id];
        if (!p?.userId) return;
        router.post('/admin/provider-voices/assign', { voiceId: v.id, name: v.name, userId: +p.userId, activate: p.activate }, { preserveScroll: true });
    };

    return (
        <>
            <Head title="ElevenLabs voices" />
            <div className="card">
                <div className="card-h">
                    <div>
                        <h3>Voices in the ElevenLabs account</h3>
                        <p>Voices cloned on elevenlabs.io (or premade ones) can be given to a customer here. They then show in that customer's Bot Studio.</p>
                    </div>
                    <div className="seg" role="tablist">
                        {(['cloned', 'premade', 'all'] as const).map((f) => (
                            <button key={f} className={filter === f ? 'on' : ''} onClick={() => setFilter(f)}>
                                {f === 'cloned' ? 'Cloned' : f === 'premade' ? 'Premade' : 'All'}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="card-b stack">
                    {!enabled && (
                        <div className="notice">
                            <Icon name="key" className="i i-sm" />
                            Add the ElevenLabs API key in <Link href="/admin/settings?tab=providers" className="link">Platform settings → API keys</Link> first.
                        </div>
                    )}
                    {error && (
                        <div className="notice" style={{ borderColor: 'var(--danger)', color: 'var(--danger)' }}>
                            <Icon name="alert" className="i i-sm" />
                            {error}
                        </div>
                    )}
                    {enabled && !error && shown.length === 0 && <div className="empty">No voices in this list.</div>}
                    {shown.map((v) => (
                        <div key={v.id} className="voice" style={{ cursor: 'default', flexWrap: 'wrap', gap: 10 }}>
                            <span className="v-av">
                                <Icon name="wave" />
                            </span>
                            <span className="v-tx" style={{ minWidth: 180 }}>
                                <b>{v.name}</b>
                                <span className="mono">
                                    {v.category} · {v.id}
                                    {v.usedBy.length > 0 && ` · used by ${v.usedBy.map((u) => u.name).join(', ')}`}
                                </span>
                            </span>
                            {v.previewUrl && <audio controls preload="none" src={v.previewUrl} style={{ height: 32, maxWidth: 220 }} />}
                            <span style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', marginLeft: 'auto' }}>
                                <select className="select" style={{ width: 220 }} value={pick[v.id]?.userId ?? ''} onChange={(e) => setPick({ ...pick, [v.id]: { activate: pick[v.id]?.activate ?? true, userId: e.target.value } })}>
                                    <option value="">Give to account…</option>
                                    {accounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.label}
                                        </option>
                                    ))}
                                </select>
                                <label className="check" style={{ margin: 0 }}>
                                    <input type="checkbox" checked={pick[v.id]?.activate ?? true} onChange={(e) => setPick({ ...pick, [v.id]: { userId: pick[v.id]?.userId ?? '', activate: e.target.checked } })} />
                                    <span>Use on their bot now</span>
                                </label>
                                <button className="btn btn-primary btn-sm" disabled={!pick[v.id]?.userId} onClick={() => assign(v)}>
                                    Assign
                                </button>
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

ProviderVoices.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
