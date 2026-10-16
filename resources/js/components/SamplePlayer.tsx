import { useEffect, useRef, useState } from 'react';
import Icon from '@/components/Icon';

export type Sample = { id: number; name: string; label: string | null; language: string; src: string; key: string };

const BARS = 48;
const buffers = new Map<number, AudioBuffer>();
let ctx: AudioContext | null = null;

function audioCtx() {
    if (!ctx) ctx = new (window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext)();
    return ctx;
}

/** Fetches the scrambled bytes, unscrambles them in memory and decodes. Nothing playable ever touches disk or an <audio> tag. */
async function load(s: Sample): Promise<AudioBuffer> {
    const hit = buffers.get(s.id);
    if (hit) return hit;
    const res = await fetch(s.src, { credentials: 'same-origin', cache: 'no-store' });
    if (!res.ok) throw new Error(res.status === 403 ? 'expired' : 'failed');
    const bytes = new Uint8Array(await res.arrayBuffer());
    const key = s.key.match(/../g)!.map((h) => parseInt(h, 16));
    for (let i = 0; i < bytes.length; i++) bytes[i] ^= key[i % key.length];
    const buf = await audioCtx().decodeAudioData(bytes.buffer);
    buffers.set(s.id, buf);
    return buf;
}

function peaksOf(buf: AudioBuffer): number[] {
    const data = buf.getChannelData(0);
    const step = Math.floor(data.length / BARS) || 1;
    const out = Array.from({ length: BARS }, (_, b) => {
        let max = 0;
        for (let i = b * step; i < Math.min(data.length, (b + 1) * step); i += 16) max = Math.max(max, Math.abs(data[i]));
        return max;
    });
    const top = Math.max(...out, 0.01);
    return out.map((v) => 0.15 + 0.85 * (v / top));
}

function placeholderPeaks(id: number) {
    return Array.from({ length: BARS }, (_, i) => 0.25 + 0.6 * Math.abs(Math.sin(id * 7.3 + i * 0.55) * Math.cos(i * 0.21)));
}

function fmt(s: number) {
    return `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;
}

/** One player for a list of samples: only one plays at a time. */
export function useSamplePlayer() {
    const [current, setCurrent] = useState<number | null>(null);
    const [loading, setLoading] = useState<number | null>(null);
    const [progress, setProgress] = useState(0);
    const [error, setError] = useState<{ id: number; msg: string } | null>(null);
    const [peaks, setPeaks] = useState<Record<number, number[]>>({});
    const node = useRef<AudioBufferSourceNode | null>(null);
    const started = useRef(0);
    const offset = useRef<Record<number, number>>({});
    const raf = useRef(0);

    const stop = (keepOffset: boolean) => {
        cancelAnimationFrame(raf.current);
        if (node.current) {
            node.current.onended = null;
            try {
                node.current.stop();
            } catch {}
            node.current = null;
        }
        if (!keepOffset && current !== null) offset.current[current] = 0;
    };

    useEffect(() => () => stop(true), []);

    const toggle = async (s: Sample) => {
        setError(null);
        if (current === s.id && node.current) {
            // pause: remember where we were
            offset.current[s.id] = audioCtx().currentTime - started.current;
            stop(true);
            setCurrent(null);
            return;
        }
        stop(true);
        setCurrent(null);
        setLoading(s.id);
        try {
            const c = audioCtx();
            if (c.state === 'suspended') await c.resume();
            const buf = await load(s);
            setPeaks((p) => (p[s.id] ? p : { ...p, [s.id]: peaksOf(buf) }));
            let from = offset.current[s.id] ?? 0;
            if (from >= buf.duration - 0.05) from = 0;
            const src = c.createBufferSource();
            src.buffer = buf;
            src.connect(c.destination);
            src.start(0, from);
            node.current = src;
            started.current = c.currentTime - from;
            setCurrent(s.id);
            src.onended = () => {
                cancelAnimationFrame(raf.current);
                offset.current[s.id] = 0;
                node.current = null;
                setCurrent(null);
                setProgress(0);
            };
            const tick = () => {
                setProgress(Math.min(1, (c.currentTime - started.current) / buf.duration));
                raf.current = requestAnimationFrame(tick);
            };
            tick();
        } catch (e) {
            setError({ id: s.id, msg: (e as Error).message === 'expired' ? 'Refresh the page to listen again.' : 'Could not play this sample.' });
        } finally {
            setLoading(null);
        }
    };

    const state = (s: Sample) => {
        const buf = buffers.get(s.id);
        const playing = current === s.id;
        const pos = playing ? progress : buf ? (offset.current[s.id] ?? 0) / buf.duration : 0;
        return {
            playing,
            loading: loading === s.id,
            progress: pos,
            peaks: peaks[s.id] ?? placeholderPeaks(s.id),
            time: buf ? `${fmt(pos * buf.duration)} / ${fmt(buf.duration)}` : null,
            error: error?.id === s.id ? error.msg : null,
        };
    };

    return { toggle, state };
}

export function SampleCard({ sample, player, className = '' }: { sample: Sample; player: ReturnType<typeof useSamplePlayer>; className?: string }) {
    const st = player.state(sample);
    return (
        <div className={'sample-card' + (st.playing ? ' playing' : '') + (className ? ' ' + className : '')} onContextMenu={(e) => e.preventDefault()}>
            <button className="sample-play" onClick={() => player.toggle(sample)} aria-label={st.playing ? `Pause ${sample.name}` : `Play ${sample.name}`} disabled={st.loading}>
                {st.loading ? <Icon name="loader" className="i spin" /> : <Icon name={st.playing ? 'pause' : 'play'} className="i" />}
            </button>
            <div className="sample-main">
                <div className="sample-top">
                    <b>{sample.name}</b>
                    <span className="sample-lang">{sample.language}</span>
                </div>
                {sample.label && <small>{sample.label}</small>}
                <div className="sample-wave" aria-hidden="true">
                    {st.peaks.map((h, i) => (
                        <span key={i} className={i / st.peaks.length < st.progress ? 'on' : ''} style={{ height: `${Math.round(h * 100)}%` }} />
                    ))}
                </div>
                <div className="sample-foot">
                    {st.error ? <span className="err">{st.error}</span> : <span>{st.time ?? 'Tap play to listen'}</span>}
                    <span className="sample-lock">
                        <Icon name="shield" className="i" />
                        Listen only
                    </span>
                </div>
            </div>
        </div>
    );
}
