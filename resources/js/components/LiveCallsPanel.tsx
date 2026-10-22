import { useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { useUI } from './ui';
import { claimAudio, releaseAudio } from '@/lib/audioFocus';
import { api } from '@/lib/format';

type LiveCall = { id: number; direction: 'web' | 'inbound' | 'outbound'; customer: string | null; startedAt: string | null; listenUrl: string | null; canControl: boolean };
type Audio = { sampleRate: number; channels: number };

/** Plays Vapi's live-listen WebSocket (raw 16-bit PCM) through Web Audio, one chunk after another. */
function usePcmListener() {
    const [listening, setListening] = useState<number | null>(null);
    const ws = useRef<WebSocket | null>(null);
    const ctx = useRef<AudioContext | null>(null);
    const at = useRef(0);
    const owner = useRef({ stop: () => {} });

    const stop = () => {
        ws.current?.close();
        ws.current = null;
        ctx.current?.close();
        ctx.current = null;
        setListening(null);
        releaseAudio(owner.current);
    };
    owner.current.stop = stop;
    useEffect(() => () => stop(), []);

    const start = (call: LiveCall, fmt: Audio) => {
        stop();
        if (!call.listenUrl) return;
        claimAudio(owner.current);
        const Ctx: typeof AudioContext = window.AudioContext || (window as any).webkitAudioContext;
        const c = new Ctx();
        ctx.current = c;
        at.current = c.currentTime + 0.15;
        const sock = new WebSocket(call.listenUrl);
        sock.binaryType = 'arraybuffer';
        sock.onmessage = (e) => {
            if (!(e.data instanceof ArrayBuffer) || !ctx.current) return;
            const pcm = new Int16Array(e.data, 0, e.data.byteLength >> 1); // ignore a dangling odd byte
            const ch = Math.max(1, fmt.channels);
            const frames = Math.floor(pcm.length / ch);
            if (!frames) return;
            const buf = c.createBuffer(1, frames, fmt.sampleRate);
            const out = buf.getChannelData(0);
            // Mix all channels (caller + assistant) into one.
            for (let i = 0; i < frames; i++) {
                let sum = 0;
                for (let k = 0; k < ch; k++) sum += pcm[i * ch + k];
                out[i] = sum / ch / 32768;
            }
            const src = c.createBufferSource();
            src.buffer = buf;
            src.connect(c.destination);
            at.current = Math.max(at.current, c.currentTime + 0.05);
            src.start(at.current);
            at.current += buf.duration;
        };
        sock.onclose = () => ws.current === sock && stop();
        sock.onerror = () => ws.current === sock && stop();
        ws.current = sock;
        setListening(call.id);
    };

    return { listening, start, stop };
}

/** "Live now" on the dashboard: calls in progress, with listen / say / whisper / end. Polls every 5 seconds. */
export default function LiveCallsPanel() {
    const { toast } = useUI();
    const [calls, setCalls] = useState<LiveCall[]>([]);
    const [fmt, setFmt] = useState<Audio>({ sampleRate: 16000, channels: 1 });
    const [text, setText] = useState<Record<number, string>>({});
    const listener = usePcmListener();

    useEffect(() => {
        let alive = true;
        const load = () =>
            api<{ calls: LiveCall[]; audio: Audio }>('/live-calls')
                .then((r) => {
                    if (!alive) return;
                    setCalls(r.calls);
                    setFmt(r.audio);
                    if (listener.listening && !r.calls.some((c) => c.id === listener.listening)) listener.stop();
                })
                .catch(() => {});
        load();
        const t = window.setInterval(() => document.visibilityState === 'visible' && load(), 5000);
        return () => {
            alive = false;
            window.clearInterval(t);
        };
    }, [listener.listening]);

    if (calls.length === 0) return null;

    const act = (c: LiveCall, path: 'say' | 'instruct' | 'end') =>
        api(`/live-calls/${c.id}/${path}`, { method: 'POST', body: path === 'end' ? {} : { text: text[c.id] ?? '' } }).then(
            () => {
                toast(path === 'end' ? 'Call ended' : path === 'say' ? 'The bot is saying it now' : 'Instruction sent to the bot');
                setText({ ...text, [c.id]: '' });
            },
            (e) => toast(e.message || 'Could not reach the call', 'error'),
        );

    return (
        <div className="card" style={{ marginBottom: 16, borderColor: 'var(--accent)' }}>
            <div className="card-h">
                <div>
                    <h3>
                        <span className="lp-live" style={{ display: 'inline-block', width: 8, height: 8, borderRadius: 99, background: 'var(--danger)', marginRight: 8 }} />
                        Live now · {calls.length} call{calls.length === 1 ? '' : 's'}
                    </h3>
                    <p>Listen in, tell the bot what to do (the caller does not hear it), make it say something, or end the call.</p>
                </div>
            </div>
            <div className="card-b" style={{ display: 'grid', gap: 12 }}>
                {calls.map((c) => (
                    <div key={c.id} style={{ display: 'grid', gap: 8, borderBottom: '1px solid var(--border)', paddingBottom: 10 }}>
                        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                            <b>{c.direction === 'inbound' ? 'Phone call' : c.direction === 'outbound' ? 'AI calling out' : 'Website call'}</b>
                            <span className="hint">{c.customer ?? 'visitor'}</span>
                            <span style={{ marginLeft: 'auto', display: 'flex', gap: 6 }}>
                                {c.listenUrl && (
                                    <button className="btn btn-ghost btn-sm" onClick={() => (listener.listening === c.id ? listener.stop() : listener.start(c, fmt))}>
                                        <Icon name={listener.listening === c.id ? 'pause' : 'play'} className="i i-sm" />
                                        {listener.listening === c.id ? 'Stop listening' : 'Listen'}
                                    </button>
                                )}
                                {c.canControl && (
                                    <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => confirm('End this call now?') && act(c, 'end')}>
                                        <Icon name="hang" className="i i-sm" />
                                        End
                                    </button>
                                )}
                            </span>
                        </div>
                        {c.canControl && (
                            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                                <input className="input" style={{ flex: 1, minWidth: 200 }} dir="auto" maxLength={300} placeholder="e.g. Offer 10% off today" value={text[c.id] ?? ''} onChange={(e) => setText({ ...text, [c.id]: e.target.value })} />
                                <button className="btn btn-ghost btn-sm" disabled={!text[c.id]} onClick={() => act(c, 'instruct')} title="Only the bot sees this">
                                    Tell the bot
                                </button>
                                <button className="btn btn-ghost btn-sm" disabled={!text[c.id]} onClick={() => act(c, 'say')} title="The caller hears these exact words">
                                    Say it
                                </button>
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}
