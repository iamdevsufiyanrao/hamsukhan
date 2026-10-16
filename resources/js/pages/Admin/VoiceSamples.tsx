import { Head, router, useForm } from '@inertiajs/react';
import { useRef } from 'react';
import Icon from '@/components/Icon';
import { Sample, SampleCard, useSamplePlayer } from '@/components/SamplePlayer';
import AppLayout from '@/layouts/AppLayout';

type Row = Sample & { isActive: boolean; sort: number };

/** Admin: sample voices visitors can listen to (never download) on the landing page. */
export default function VoiceSamples({ samples, languages }: { samples: Row[]; languages: string[] }) {
    const player = useSamplePlayer();
    const file = useRef<HTMLInputElement>(null);
    const form = useForm<{ name: string; label: string; language: string; audio: File | null }>({ name: '', label: '', language: languages[0] ?? 'English', audio: null });

    const add = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/admin/voice-samples', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                if (file.current) file.current.value = '';
            },
        });
    };
    const patch = (s: Row, data: { is_active?: boolean; sort?: number }) => router.patch(`/admin/voice-samples/${s.id}`, data, { preserveScroll: true });
    const remove = (s: Row) => confirm(`Delete "${s.name}"? It disappears from the home page.`) && router.delete(`/admin/voice-samples/${s.id}`, { preserveScroll: true });

    return (
        <>
            <Head title="Sample voices" />
            <div className="two-col">
                <div className="card">
                    <div className="card-h">
                        <div>
                            <h3>Sample voices on the home page</h3>
                            <p>Visitors can play these but there is no download button, the file is never public and the stream is scrambled.</p>
                        </div>
                    </div>
                    <div className="card-b" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                        {samples.length === 0 && <div className="empty">No sample voices yet. Add one and the "Sample voices" section appears on the home page.</div>}
                        {samples.map((s, i) => (
                            <div key={s.id} className={'sample-row' + (s.isActive ? '' : ' off')}>
                                <SampleCard sample={s} player={player} />
                                <div className="row-acts" style={{ justifyContent: 'flex-start', flexWrap: 'wrap' }}>
                                    <div className="check-row" style={{ marginRight: 8 }}>
                                        <label>
                                            <input type="checkbox" checked={s.isActive} onChange={(e) => patch(s, { is_active: e.target.checked })} />
                                            Show on home page
                                        </label>
                                    </div>
                                    <button className="btn btn-ghost btn-sm" disabled={i === 0} onClick={() => patch(s, { sort: Math.max(0, samples[i - 1].sort - 1) })}>
                                        Move up
                                    </button>
                                    <button className="btn btn-ghost btn-sm" onClick={() => remove(s)}>
                                        <Icon name="trash" className="i i-sm" />
                                        Delete
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <form className="card" onSubmit={add}>
                    <div className="card-h">
                        <div>
                            <h3>Add a sample voice</h3>
                            <p>MP3, WAV, M4A, OGG or WebM, up to 10 MB. 15 to 40 seconds works best.</p>
                        </div>
                    </div>
                    <div className="card-b" style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                        <div className="field">
                            <label htmlFor="vs-name">Name</label>
                            <input id="vs-name" className={'input' + (form.errors.name ? ' bad' : '')} placeholder="e.g. Sara" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            {form.errors.name && <span className="err">{form.errors.name}</span>}
                        </div>
                        <div className="field">
                            <label htmlFor="vs-label">
                                Short description <span className="hint">(optional)</span>
                            </label>
                            <input id="vs-label" className="input" placeholder="e.g. Warm and friendly, salon greeting" value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} />
                        </div>
                        <div className="field">
                            <label htmlFor="vs-lang">Language</label>
                            <select id="vs-lang" className="select" value={form.data.language} onChange={(e) => form.setData('language', e.target.value)}>
                                {languages.map((l) => (
                                    <option key={l}>{l}</option>
                                ))}
                            </select>
                        </div>
                        <div className="field">
                            <label htmlFor="vs-file">Audio file</label>
                            <input id="vs-file" ref={file} type="file" accept="audio/*" className="input" style={{ paddingTop: 8 }} onChange={(e) => form.setData('audio', e.target.files?.[0] ?? null)} />
                            {form.errors.audio && <span className="err">{form.errors.audio}</span>}
                        </div>
                        <button className="btn btn-primary" disabled={form.processing || !form.data.audio}>
                            <Icon name="upload" className="i i-sm" />
                            {form.processing ? 'Uploading…' : 'Add sample voice'}
                        </button>
                    </div>
                </form>
            </div>
        </>
    );
}

VoiceSamples.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
