import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Icon, { IconName } from '@/components/Icon';
import { FieldError, Modal, Seg, useUI } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { fileSize, fmtDate, fmtDateLong } from '@/lib/format';

type Tab = 'configure' | 'voice' | 'knowledge' | 'widget' | 'embed';
type WState = 'idle' | 'loading' | 'active' | 'error';
type WStyle = { title: string; sub: string; color: string; iconUrl: string };
type Position = 'bottom-right' | 'bottom-left' | 'top-right' | 'top-left';
type Widget = { position: Position; offset: number; width: number; height: number; states: Record<WState, WStyle> };
type CaptureField = { key?: string; label: string; type: string; required: boolean; hint?: string | null };

type Props = {
    tab: Tab;
    bot: {
        greeting: string;
        endMessage: string | null;
        language: string;
        prompt: string;
        quickFacts: string | null;
        captureFields: CaptureField[];
        maxCallMinutes: number;
        voiceId: number | null;
        presetVoice: string | null;
        widget: Widget;
        publicKey: string;
        installedAt: string | null;
    };
    voices: { id: number; name: string; createdAt: string; seconds: number; status: string; hasSample: boolean }[];
    files: { id: number; name: string; size: number; status: string; createdAt: string }[];
    options: {
        languages: string[];
        presetVoices: { id: string; name: string }[];
        maxVoices: number;
        minCloneSeconds: number;
        fieldTypes: string[];
        fieldPresets: Record<string, CaptureField[]>;
    };
    embedSrc: string;
};

const TABS: { id: Tab; label: string; icon: IconName }[] = [
    { id: 'configure', label: 'Configure', icon: 'wrench' },
    { id: 'voice', label: 'Voice', icon: 'wave' },
    { id: 'knowledge', label: 'Knowledge', icon: 'book' },
    { id: 'widget', label: 'Widget', icon: 'layout' },
    { id: 'embed', label: 'Embed code', icon: 'code' },
];
const STATE_ICON: Record<WState, IconName> = { idle: 'mic', loading: 'loader', active: 'hang', error: 'alert' };

const PROMPT_TEMPLATES: Record<string, { label: string; greeting: string; prompt: string }> = {
    reception: {
        label: 'Receptionist',
        greeting: 'Hi, thanks for calling! How can I help you today?',
        prompt: "You are a friendly receptionist for [Business name].\n\n- Greet callers and ask how you can help.\n- Answer questions using the knowledge files.\n- For appointments, collect name, phone number and preferred time.\n- If you don't know something, say a team member will call back.\n- Keep answers short and natural.",
    },
    restaurant: {
        label: 'Restaurant orders',
        greeting: 'Hi, thanks for calling [Restaurant]! Would you like to place an order?',
        prompt: 'You take phone orders for [Restaurant].\n\n- Ask if the order is for delivery or pickup.\n- Take each item with size and quantity. Only offer items from the menu file.\n- Read the full order back and confirm the total.\n- For delivery, collect name, phone number and address.\n- Give the estimated time: 30–40 minutes.',
    },
    clinic: {
        label: 'Clinic appointments',
        greeting: "Hello, you've reached [Clinic]. How can I help you today?",
        prompt: 'You are the front desk assistant for [Clinic].\n\n- Book, move or cancel appointments.\n- Collect patient name, phone number, doctor and preferred time.\n- Never give medical advice. For emergencies, tell the caller to call emergency services.\n- Clinic hours: [hours].',
    },
    salon: {
        label: 'Salon booking',
        greeting: 'Hi, welcome to [Salon]! Would you like to book an appointment?',
        prompt: 'You book appointments for [Salon].\n\n- Ask which service they want and offer prices from the price list.\n- Collect name, phone number and preferred day and time.\n- Mention the cancellation policy: 24 hours notice.',
    },
};

export default function BotStudio(props: Props) {
    const { tab: initialTab, bot } = props;
    const [tab, setTab] = useState<Tab>(initialTab);
    const { openModal } = useUI();
    const widget = useForm<Widget>(bot.widget);
    const [previewState, setPreviewState] = useState<WState>('idle');

    const changeTab = (t: Tab) => {
        setTab(t);
        window.history.replaceState(window.history.state, '', `/bot-studio?tab=${t}`);
    };

    return (
        <>
            <Head title="Bot Studio" />
            <div className="tabs" role="tablist">
                {TABS.map((t) => (
                    <button key={t.id} role="tab" aria-selected={tab === t.id} className={tab === t.id ? 'on' : ''} onClick={() => changeTab(t.id)}>
                        <Icon name={t.icon} />
                        {t.label}
                    </button>
                ))}
            </div>

            <div className="studio">
                <div>
                    {tab === 'configure' && (
                        <div className="stack">
                            <ConfigurePane bot={bot} languages={props.options.languages} />
                            <CaptureFieldsCard fields={bot.captureFields} types={props.options.fieldTypes} presets={props.options.fieldPresets} />
                        </div>
                    )}
                    {tab === 'voice' && <VoicePane bot={bot} voices={props.voices} options={props.options} />}
                    {tab === 'knowledge' && <KnowledgePane files={props.files} quickFacts={bot.quickFacts} />}
                    {tab === 'widget' && <WidgetPane form={widget} wState={previewState} setWState={setPreviewState} />}
                    {tab === 'embed' && <EmbedPane src={props.embedSrc} publicKey={bot.publicKey} installedAt={bot.installedAt} />}
                </div>

                <div className="card preview-card">
                    <div className="card-h">
                        <div>
                            <h3>Live preview</h3>
                            <p>How visitors will see it</p>
                        </div>
                    </div>
                    <div className="card-b">
                        <WidgetPreview widget={widget.data} state={previewState} />
                        <button className="btn btn-ghost" style={{ width: '100%', marginTop: 12 }} onClick={() => openModal('test')}>
                            <Icon name="mic" />
                            Try a test call
                        </button>
                    </div>
                </div>
            </div>
        </>
    );
}

BotStudio.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

/* ---------- Configure ---------- */
function ConfigurePane({ bot, languages }: { bot: Props['bot']; languages: string[] }) {
    const { toast } = useUI();
    const form = useForm({ greeting: bot.greeting, endMessage: bot.endMessage ?? '', language: bot.language, prompt: bot.prompt, maxCallMinutes: bot.maxCallMinutes });
    const save = () => form.patch('/bot-studio/config', { preserveScroll: true, onSuccess: () => form.setDefaults() });

    return (
        <div className="card">
            <div className="card-h">
                <div>
                    <h3>Bot behaviour</h3>
                    <p>What your bot says first and how it should handle callers.</p>
                </div>
            </div>
            <div className="card-b stack">
                <div className="grid-2" style={{ gridTemplateColumns: '1fr 200px' }}>
                    <div className="field">
                        <label htmlFor="greet">Greeting message</label>
                        <input id="greet" className={'input' + (form.errors.greeting ? ' bad' : '')} value={form.data.greeting} onChange={(e) => form.setData('greeting', e.target.value)} />
                        <FieldError msg={form.errors.greeting} />
                    </div>
                    <div className="field">
                        <label htmlFor="lang">Language</label>
                        <select id="lang" className="select" value={form.data.language} onChange={(e) => form.setData('language', e.target.value)}>
                            {languages.map((l) => (
                                <option key={l}>{l}</option>
                            ))}
                        </select>
                    </div>
                </div>
                <div className="templates">
                    <span>Start from a template:</span>
                    {Object.entries(PROMPT_TEMPLATES).map(([k, t]) => (
                        <button
                            key={k}
                            type="button"
                            onClick={() => {
                                form.setData({ ...form.data, greeting: t.greeting, prompt: t.prompt });
                                toast(`${t.label} template loaded, edit the [brackets]`);
                            }}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>
                <div className="field">
                    <label htmlFor="prompt">
                        Instructions <span className="hint">(the prompt your bot follows on every call)</span>
                    </label>
                    <textarea id="prompt" className={'textarea' + (form.errors.prompt ? ' bad' : '')} value={form.data.prompt} onChange={(e) => form.setData('prompt', e.target.value)} maxLength={4000} />
                    <div className="counter">{form.data.prompt.length} / 4000</div>
                    <FieldError msg={form.errors.prompt} />
                </div>
                <div className="grid-2">
                    <div className="field">
                        <label htmlFor="maxlen">Max call length</label>
                        <select id="maxlen" className="select" value={form.data.maxCallMinutes} onChange={(e) => form.setData('maxCallMinutes', +e.target.value)}>
                            <option value={5}>5 minutes</option>
                            <option value={10}>10 minutes</option>
                            <option value={15}>15 minutes</option>
                        </select>
                    </div>
                    <div className="field">
                        <label htmlFor="endmsg">End-of-call message</label>
                        <input id="endmsg" className="input" value={form.data.endMessage} onChange={(e) => form.setData('endMessage', e.target.value)} />
                    </div>
                </div>
                <div className="form-foot">
                    <span>{form.processing ? 'Saving…' : form.isDirty ? 'Unsaved changes' : 'All changes saved'}</span>
                    <button className="btn btn-primary" disabled={!form.isDirty || form.processing} onClick={save}>
                        Save changes
                    </button>
                </div>
            </div>
        </div>
    );
}

/* ---------- Capture fields ---------- */
const PRESET_LABELS: Record<string, string> = { reception: 'Receptionist (none)', restaurant: 'Restaurant order', clinic: 'Clinic appointment', salon: 'Salon booking' };

/** What the bot must ask for and write down. These become the fields your connectors send to your software. */
function CaptureFieldsCard({ fields, types, presets }: { fields: CaptureField[]; types: string[]; presets: Record<string, CaptureField[]> }) {
    const form = useForm<{ fields: CaptureField[] }>({ fields });
    const rows = form.data.fields;
    const set = (i: number, p: Partial<CaptureField>) => form.setData('fields', rows.map((r, j) => (j === i ? { ...r, ...p } : r)));
    const err = (i: number, k: string) => (form.errors as Record<string, string>)[`fields.${i}.${k}`];

    return (
        <div className="card">
            <div className="card-h">
                <div>
                    <h3>What should the bot collect?</h3>
                    <p>On every booking or order the bot asks for these and writes them down, then your connectors send them to your software.</p>
                </div>
            </div>
            <div className="card-b stack">
                <div className="field">
                    <label>Always collected</label>
                    <div className="std-fields">
                        {['Customer name', 'Phone', 'Request', 'Preferred time'].map((s) => (
                            <span key={s} className="tag">
                                {s}
                            </span>
                        ))}
                    </div>
                </div>
                <div className="templates">
                    <span>Load a preset:</span>
                    {Object.keys(presets).map((k) => (
                        <button key={k} type="button" onClick={() => form.setData('fields', presets[k])}>
                            {PRESET_LABELS[k] ?? k}
                        </button>
                    ))}
                </div>
                <div className="stack" style={{ gap: 8 }}>
                    {rows.length > 0 && (
                        <div className="field-row map-head">
                            <span>Field</span>
                            <span>Type</span>
                            <span>Hint for the bot</span>
                            <span />
                            <span />
                        </div>
                    )}
                    {rows.map((f, i) => (
                        <div key={i}>
                            <div className="field-row">
                                <input className={'input' + (err(i, 'label') ? ' bad' : '')} placeholder="e.g. Delivery address" value={f.label} onChange={(e) => set(i, { label: e.target.value })} />
                                <select className="select" value={f.type} onChange={(e) => set(i, { type: e.target.value })}>
                                    {types.map((t) => (
                                        <option key={t} value={t}>
                                            {t}
                                        </option>
                                    ))}
                                </select>
                                <input className="input" placeholder="optional" value={f.hint ?? ''} onChange={(e) => set(i, { hint: e.target.value })} />
                                <label>
                                    <input type="checkbox" checked={f.required} onChange={(e) => set(i, { required: e.target.checked })} />
                                    Must ask
                                </label>
                                <button className="icon-btn" style={{ width: 32, height: 32 }} title="Remove" onClick={() => form.setData('fields', rows.filter((_, j) => j !== i))}>
                                    <Icon name="trash" className="i i-sm" />
                                </button>
                            </div>
                            <FieldError msg={err(i, 'label')} />
                        </div>
                    ))}
                    {rows.length < 12 && (
                        <button className="clone-cta" style={{ height: 42 }} onClick={() => form.setData('fields', [...rows, { label: '', type: 'text', required: false, hint: '' }])}>
                            <Icon name="plus" />
                            Add a field
                        </button>
                    )}
                </div>
                <div className="form-foot">
                    <span>{form.isDirty ? 'Unsaved changes' : `${rows.length} custom field${rows.length === 1 ? '' : 's'}`}</span>
                    <button className="btn btn-primary" disabled={!form.isDirty || form.processing} onClick={() => form.patch('/bot-studio/capture', { preserveScroll: true, onSuccess: () => form.setDefaults() })}>
                        Save fields
                    </button>
                </div>
            </div>
        </div>
    );
}

/* ---------- Voice ---------- */
function VoicePane({ bot, voices, options }: { bot: Props['bot']; voices: Props['voices']; options: Props['options'] }) {
    const { toast } = useUI();
    const [voiceId, setVoiceId] = useState<number | null>(bot.voiceId);
    const [preset, setPreset] = useState<string>(bot.voiceId ? '' : (bot.presetVoice ?? ''));
    const [cloneOpen, setCloneOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    useEffect(() => {
        setVoiceId(bot.voiceId);
        setPreset(bot.voiceId ? '' : (bot.presetVoice ?? ''));
    }, [bot.voiceId, bot.presetVoice]);

    const dirty = voiceId !== bot.voiceId || (!voiceId && preset !== (bot.presetVoice ?? ''));
    const save = () =>
        router.patch('/bot-studio/voice', { voiceId, presetVoice: voiceId ? null : preset }, { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) });

    return (
        <div className="card">
            <div className="card-h">
                <div>
                    <h3>Call voice</h3>
                    <p>Pick the voice your bot speaks with. Clone your own to sound like you.</p>
                </div>
            </div>
            <div className="card-b stack">
                <div className="field">
                    <label htmlFor="preset">Library voices</label>
                    <select
                        id="preset"
                        className="select"
                        value={voiceId ? '' : preset}
                        onChange={(e) => {
                            setPreset(e.target.value);
                            if (e.target.value) setVoiceId(null);
                        }}
                    >
                        <option value="">Use a cloned voice below</option>
                        {options.presetVoices.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <div className="section-t">
                        Your cloned voices · {voices.length} / {options.maxVoices}
                    </div>
                    <div className="voice-list">
                        {voices.length === 0 && (
                            <div className="empty" style={{ padding: 20 }}>
                                No cloned voices yet.
                            </div>
                        )}
                        {voices.map((v) => (
                            <div
                                key={v.id}
                                className={'voice' + (v.id === voiceId ? ' on' : '')}
                                onClick={() => {
                                    setVoiceId(v.id);
                                    setPreset('');
                                }}
                            >
                                <span className="v-av">
                                    <Icon name="user" />
                                </span>
                                <span className="v-tx">
                                    <b>{v.name}</b>
                                    <span>
                                        Cloned {fmtDateLong(v.createdAt)} · {v.seconds}s sample{v.id === bot.voiceId ? ' · In use' : ''}
                                    </span>
                                </span>
                                <span className="v-act">
                                    <button
                                        title="Preview sample"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            if (!v.hasSample) return toast('No sample recording stored for this voice');
                                            new Audio(`/voices/${v.id}/sample`).play().catch(() => toast('Could not play the sample', 'error'));
                                        }}
                                    >
                                        <Icon name="play" className="i i-sm" />
                                    </button>
                                    <button
                                        className="del"
                                        title="Delete"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            if (confirm(`Delete the voice "${v.name}"? This can't be undone.`)) router.delete(`/voices/${v.id}`, { preserveScroll: true });
                                        }}
                                    >
                                        <Icon name="trash" className="i i-sm" />
                                    </button>
                                </span>
                            </div>
                        ))}
                    </div>
                    <button className="clone-cta" onClick={() => (voices.length >= options.maxVoices ? toast(`You can keep up to ${options.maxVoices} voices, delete one first`, 'error') : setCloneOpen(true))}>
                        <Icon name="mic" />
                        Clone a new voice
                    </button>
                </div>
                <div className="form-foot">
                    <span>Voices are cloned with ElevenLabs.</span>
                    <button className="btn btn-primary" disabled={!dirty || saving || (!voiceId && !preset)} onClick={save}>
                        {saving ? 'Saving…' : 'Save voice'}
                    </button>
                </div>
            </div>
            <CloneModal open={cloneOpen} onClose={() => setCloneOpen(false)} minSeconds={options.minCloneSeconds} />
        </div>
    );
}

/** Records the owner's voice in the browser and uploads it for cloning. */
function CloneModal({ open, onClose, minSeconds }: { open: boolean; onClose: () => void; minSeconds: number }) {
    const { toast } = useUI();
    const [recording, setRecording] = useState(false);
    const [secs, setSecs] = useState(0);
    const [levels, setLevels] = useState<number[]>(Array(18).fill(4));
    const [blob, setBlob] = useState<Blob | null>(null);
    const form = useForm<{ name: string; consent: boolean; seconds: number; sample: File | null }>({ name: '', consent: false, seconds: 0, sample: null });
    const rec = useRef<{ mr: MediaRecorder; stream: MediaStream; ctx: AudioContext; raf: number; timer: number; started: number } | null>(null);

    const stop = () => {
        const r = rec.current;
        if (!r) return;
        rec.current = null;
        window.clearInterval(r.timer);
        cancelAnimationFrame(r.raf);
        if (r.mr.state !== 'inactive') r.mr.stop();
        r.stream.getTracks().forEach((t) => t.stop());
        r.ctx.close();
        setRecording(false);
        setLevels(Array(18).fill(4));
    };

    useEffect(() => {
        if (!open) {
            stop();
            setSecs(0);
            setBlob(null);
            form.reset();
            form.clearErrors();
        }
    }, [open]);
    useEffect(() => () => stop(), []);

    const startRec = async () => {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const mr = new MediaRecorder(stream);
            const chunks: BlobPart[] = [];
            mr.ondataavailable = (e) => e.data.size && chunks.push(e.data);
            mr.onstop = () => setBlob(new Blob(chunks, { type: mr.mimeType || 'audio/webm' }));

            const ctx = new AudioContext();
            const analyser = ctx.createAnalyser();
            analyser.fftSize = 64;
            ctx.createMediaStreamSource(stream).connect(analyser);
            const buf = new Uint8Array(analyser.frequencyBinCount);
            const draw = () => {
                analyser.getByteFrequencyData(buf);
                setLevels(Array.from({ length: 18 }, (_, i) => 4 + (buf[i + 2] / 255) * 24));
                if (rec.current) rec.current.raf = requestAnimationFrame(draw);
            };

            const started = Date.now();
            const timer = window.setInterval(() => {
                const s = Math.floor((Date.now() - started) / 1000);
                setSecs(s);
                if (s >= 120) stop();
            }, 250);
            rec.current = { mr, stream, ctx, raf: 0, timer, started };
            mr.start(1000);
            setBlob(null);
            setSecs(0);
            setRecording(true);
            draw();
        } catch {
            toast('Microphone access was blocked. Allow it in your browser and try again.', 'error');
        }
    };

    const submit = () => {
        if (!blob) return;
        const ext = blob.type.includes('ogg') ? 'ogg' : blob.type.includes('mp4') ? 'm4a' : 'webm';
        form.transform((d) => ({ ...d, seconds: secs, sample: new File([blob], `sample.${ext}`, { type: blob.type }) }));
        form.post('/voices', { forceFormData: true, preserveScroll: true, onSuccess: () => onClose() });
    };

    const hint = recording ? 'Recording… tap to stop' : secs === 0 ? 'Tap to start recording' : secs >= minSeconds ? 'Recording ready, tap to re-record' : `Too short (${secs}s), at least ${minSeconds} seconds needed`;
    const valid = !recording && !!blob && secs >= minSeconds && form.data.name.trim() !== '' && form.data.consent && !form.processing;

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Clone a new voice"
            desc={`Read naturally for at least ${minSeconds} seconds in a quiet room, talk about your business, your hours, anything.`}
            footer={
                <>
                    <button className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button className="btn btn-primary" disabled={!valid} onClick={submit}>
                        {form.processing ? 'Cloning…' : 'Create voice'}
                    </button>
                </>
            }
        >
            <div className="rec">
                <button className={'rec-btn' + (recording ? ' on' : '')} onClick={() => (recording ? stop() : startRec())} aria-label={recording ? 'Stop recording' : 'Start recording'}>
                    <Icon name={recording ? 'stop' : 'mic'} />
                </button>
                <div className="rec-time num">
                    {String(Math.floor(secs / 60)).padStart(2, '0')}:{String(secs % 60).padStart(2, '0')}
                </div>
                <div className="rec-bars">
                    {levels.map((h, i) => (
                        <span key={i} style={{ height: h }} />
                    ))}
                </div>
                <div className="rec-hint">{hint}</div>
                <FieldError msg={form.errors.sample || form.errors.seconds} />
            </div>
            <div className="field">
                <label htmlFor="vname">Voice name</label>
                <input id="vname" className={'input' + (form.errors.name ? ' bad' : '')} placeholder="e.g. My voice, warm" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                <FieldError msg={form.errors.name} />
            </div>
            <label className="check">
                <input type="checkbox" checked={form.data.consent} onChange={(e) => form.setData('consent', e.target.checked)} />I confirm this is my own voice, or I have the speaker's permission to clone it.
            </label>
            <FieldError msg={form.errors.consent} />
        </Modal>
    );
}

/* ---------- Knowledge ---------- */
function KnowledgePane({ files, quickFacts }: { files: Props['files']; quickFacts: string | null }) {
    const { toast } = useUI();
    const [over, setOver] = useState(false);
    const [uploading, setUploading] = useState(false);
    const facts = useForm({ quickFacts: quickFacts ?? '' });

    const upload = (list: FileList | null) => {
        if (!list?.length) return;
        router.post(
            '/knowledge',
            { files: [...list] },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setUploading(true),
                onFinish: () => setUploading(false),
                onError: (e) => toast(Object.values(e)[0] as string, 'error'),
            },
        );
    };

    return (
        <div className="card">
            <div className="card-h">
                <div>
                    <h3>Knowledge</h3>
                    <p>Files your bot can read to answer questions, menus, price lists, FAQs, policies.</p>
                </div>
            </div>
            <div className="card-b stack">
                <label
                    className={'drop' + (over ? ' over' : '')}
                    onDragOver={(e) => {
                        e.preventDefault();
                        setOver(true);
                    }}
                    onDragLeave={() => setOver(false)}
                    onDrop={(e) => {
                        e.preventDefault();
                        setOver(false);
                        upload(e.dataTransfer.files);
                    }}
                >
                    <input type="file" multiple accept=".pdf,.txt,.docx,.csv,.md" hidden onChange={(e) => upload(e.target.files)} />
                    <Icon name={uploading ? 'loader' : 'upload'} className={uploading ? 'i spin' : 'i'} />
                    <b>{uploading ? 'Uploading…' : 'Drop files here or click to upload'}</b>
                    PDF, DOCX, TXT, CSV, MD · up to 10MB each
                </label>
                <div className="file-list">
                    {files.map((f) => (
                        <div className="file" key={f.id}>
                            <span className="f-ic">{f.name.split('.').pop()?.toUpperCase()}</span>
                            <span className="f-tx">
                                <b>{f.name}</b>
                                <span>
                                    {fileSize(f.size)} · added {fmtDate(f.createdAt)} · <span style={{ color: f.status === 'indexed' ? 'var(--info)' : 'var(--warn)' }}>{f.status[0].toUpperCase() + f.status.slice(1)}</span>
                                </span>
                            </span>
                            <button title="Remove" onClick={() => confirm(`Remove ${f.name}?`) && router.delete(`/knowledge/${f.id}`, { preserveScroll: true })}>
                                <Icon name="trash" className="i i-sm" />
                            </button>
                        </div>
                    ))}
                </div>
                <div className="field">
                    <label htmlFor="facts">
                        Quick facts <span className="hint">(short answers the bot should always know)</span>
                    </label>
                    <textarea id="facts" className="textarea" style={{ minHeight: 120 }} placeholder="e.g. Free parking behind the shop. We accept cash and card." value={facts.data.quickFacts} onChange={(e) => facts.setData('quickFacts', e.target.value)} />
                    <FieldError msg={facts.errors.quickFacts} />
                </div>
                <div className="form-foot">
                    <span>Files are indexed for your bot within a minute.</span>
                    <button className="btn btn-primary" disabled={!facts.isDirty || facts.processing} onClick={() => facts.patch('/bot-studio/knowledge', { preserveScroll: true, onSuccess: () => facts.setDefaults() })}>
                        Save quick facts
                    </button>
                </div>
            </div>
        </div>
    );
}

/* ---------- Widget ---------- */
function WidgetPane({ form, wState, setWState }: { form: ReturnType<typeof useForm<Widget>>; wState: WState; setWState: (s: WState) => void }) {
    const cur = form.data.states[wState];
    const [colorText, setColorText] = useState(cur.color.toUpperCase());
    useEffect(() => setColorText(cur.color.toUpperCase()), [wState, cur.color]);
    const patch = (p: Partial<WStyle>) => form.setData('states', { ...form.data.states, [wState]: { ...cur, ...p } });
    const err = (k: string) => (form.errors as Record<string, string>)[`states.${wState}.${k}`];

    return (
        <div className="card">
            <div className="card-h">
                <div>
                    <h3>Widget appearance</h3>
                    <p>How the call button looks on your website.</p>
                </div>
            </div>
            <div className="card-b stack">
                <div className="field">
                    <label>Position</label>
                    <div className="pos-grid">
                        {(['bottom-right', 'bottom-left', 'top-right', 'top-left'] as Position[]).map((p) => (
                            <button key={p} type="button" className={p === form.data.position ? 'on' : ''} onClick={() => form.setData('position', p)}>
                                {p.replace('-', ' ').replace(/^\w/, (c) => c.toUpperCase())}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="grid-2" style={{ gridTemplateColumns: 'repeat(3,1fr)' }}>
                    {(['offset', 'width', 'height'] as const).map((k) => (
                        <div className="field" key={k}>
                            <label htmlFor={'w-' + k}>
                                {k[0].toUpperCase() + k.slice(1)} <span className="hint">px</span>
                            </label>
                            <input id={'w-' + k} className={'input num' + (form.errors[k] ? ' bad' : '')} type="number" value={form.data[k]} onChange={(e) => form.setData(k, +e.target.value)} />
                            <FieldError msg={form.errors[k]} />
                        </div>
                    ))}
                </div>
                <div className="field">
                    <label>Button state</label>
                    <Seg
                        style={{ alignSelf: 'flex-start' }}
                        value={wState}
                        onChange={setWState}
                        options={[
                            { value: 'idle', label: 'Idle' },
                            { value: 'loading', label: 'Connecting' },
                            { value: 'active', label: 'On call' },
                            { value: 'error', label: 'Error' },
                        ]}
                    />
                </div>
                <div className="grid-2">
                    <div className="field">
                        <label htmlFor="w-title">Title</label>
                        <input id="w-title" className="input" value={cur.title} onChange={(e) => patch({ title: e.target.value })} />
                        <FieldError msg={err('title')} />
                    </div>
                    <div className="field">
                        <label htmlFor="w-sub">Subtitle</label>
                        <input id="w-sub" className="input" value={cur.sub ?? ''} onChange={(e) => patch({ sub: e.target.value })} />
                    </div>
                    <div className="field">
                        <label htmlFor="w-icon">Icon URL</label>
                        <input id="w-icon" className="input" placeholder="https://…/icon.svg" value={cur.iconUrl ?? ''} onChange={(e) => patch({ iconUrl: e.target.value })} />
                        <FieldError msg={err('iconUrl')} />
                    </div>
                    <div className="field">
                        <label htmlFor="w-color">Color</label>
                        <div className="color-in">
                            <input
                                id="w-color"
                                className="input mono"
                                value={colorText}
                                onChange={(e) => {
                                    const v = e.target.value.trim();
                                    setColorText(v);
                                    if (/^#?[0-9a-f]{6}$/i.test(v)) patch({ color: v.startsWith('#') ? v : '#' + v });
                                }}
                            />
                            <input type="color" aria-label="Pick color" value={cur.color} onChange={(e) => patch({ color: e.target.value })} />
                        </div>
                        <FieldError msg={err('color')} />
                    </div>
                </div>
                <div className="form-foot">
                    <span>{form.isDirty ? 'Unsaved changes, the preview updates as you type.' : 'The preview updates as you type.'}</span>
                    <button className="btn btn-primary" disabled={!form.isDirty || form.processing} onClick={() => form.patch('/bot-studio/widget', { preserveScroll: true, onSuccess: () => form.setDefaults() })}>
                        Save widget
                    </button>
                </div>
            </div>
        </div>
    );
}

function WidgetPreview({ widget, state }: { widget: Widget; state: WState }) {
    const style = widget.states[state];
    const scale = 0.78;
    const [v, h] = widget.position.split('-') as ['top' | 'bottom', 'left' | 'right'];
    const off = Math.max(0, Math.min(60, widget.offset || 0)) * scale;
    const icon = STATE_ICON[state];
    return (
        <div className="browser">
            <div className="browser-bar">
                <i />
                <i />
                <i />
                <span>your-website.com</span>
            </div>
            <div className="browser-body">
                <div className="ph" style={{ width: '45%', height: 14 }} />
                <div className="ph" style={{ width: '80%' }} />
                <div className="ph" style={{ width: '70%' }} />
                <div className="ph" style={{ width: '76%' }} />
                <div className="ph" style={{ width: '38%', height: 60, marginTop: 16, borderRadius: 8 }} />
                <div
                    className="wdg"
                    style={{
                        [v]: off,
                        [h]: off,
                        width: Math.min(300, Math.max(54, widget.width || 230)) * scale,
                        height: Math.min(90, Math.max(40, widget.height || 54)) * scale,
                    }}
                >
                    <span className="w-ic" style={{ background: style.color }}>
                        {style.iconUrl ? <img src={style.iconUrl} alt="" width={18} height={18} /> : <Icon name={icon} className={icon === 'loader' ? 'i spin' : 'i'} />}
                    </span>
                    <span className="w-tx">
                        <b>{style.title}</b>
                        <span>{style.sub}</span>
                    </span>
                </div>
            </div>
        </div>
    );
}

/* ---------- Embed ---------- */
function EmbedPane({ src, publicKey, installedAt }: { src: string; publicKey: string; installedAt: string | null }) {
    const { toast } = useUI();
    const snippet = `<script src="${src}" data-bot="${publicKey}" defer></script>`;

    return (
        <div className="card">
            <div className="card-h">
                <div>
                    <h3>Add to your website</h3>
                    <p>Paste this one line before the closing &lt;/body&gt; tag.</p>
                </div>
                <button
                    className="btn btn-ink btn-sm"
                    onClick={() =>
                        navigator.clipboard?.writeText(snippet).then(
                            () => toast('Snippet copied'),
                            () => toast('Select the code and copy it manually', 'error'),
                        )
                    }
                >
                    <Icon name="copy" className="i i-sm" />
                    Copy code
                </button>
            </div>
            <div className="card-b">
                <pre className="code mono" style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-all' }}>
                    {snippet}
                </pre>
                <div className="notice" style={{ marginTop: 14, background: installedAt ? 'var(--info-soft)' : undefined, color: installedAt ? 'var(--info)' : undefined }}>
                    <Icon name={installedAt ? 'check' : 'clock'} className="i i-sm" />
                    {installedAt ? `Installed, first seen on your site ${fmtDateLong(installedAt)}.` : "Not detected yet, we'll mark it installed the first time the widget loads on your site."}
                </div>
                <div className="steps">
                    <div>
                        <b>1</b>Copy the snippet above.
                    </div>
                    <div>
                        <b>2</b>Paste it into your site's HTML, just before &lt;/body&gt; (WordPress: Appearance → Theme File Editor → footer.php).
                    </div>
                    <div>
                        <b>3</b>Reload your site. The call button appears in the position you set.
                    </div>
                </div>
            </div>
        </div>
    );
}
