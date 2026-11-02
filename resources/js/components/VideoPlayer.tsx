import { useEffect, useRef, useState } from 'react';

const ICON = {
    play: <path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.6-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5z" fill="currentColor" stroke="none" />,
    pause: (
        <>
            <rect x="6" y="5" width="4" height="14" rx="1.2" fill="currentColor" stroke="none" />
            <rect x="14" y="5" width="4" height="14" rx="1.2" fill="currentColor" stroke="none" />
        </>
    ),
    sound: <path d="M11 5 6 9H3v6h3l5 4V5zM15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" />,
    mute: <path d="M11 5 6 9H3v6h3l5 4V5zM22 9l-6 6M16 9l6 6" />,
    full: <path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />,
    replay: <path d="M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5" />,
};

function Svg({ name }: { name: keyof typeof ICON }) {
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            {ICON[name]}
        </svg>
    );
}

const fmt = (s: number) => (Number.isFinite(s) ? `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}` : '0:00');

/**
 * Hamsukhan-branded video player: big play button over the cover, then a slim control bar
 * (play/pause, seek, time, speed, mute, fullscreen) that hides while playing. Keyboard: Space/K, ←/→ 5 s, M, F.
 */
export default function VideoPlayer({ src, poster, label }: { src: string; poster?: string | null; label: string }) {
    const box = useRef<HTMLDivElement>(null);
    const video = useRef<HTMLVideoElement>(null);
    const hideTimer = useRef<number>(undefined);
    const [playing, setPlaying] = useState(false);
    const [started, setStarted] = useState(false);
    const [ended, setEnded] = useState(false);
    const [muted, setMuted] = useState(false);
    const [time, setTime] = useState(0);
    const [duration, setDuration] = useState(0);
    const [buffered, setBuffered] = useState(0);
    const [speed, setSpeed] = useState(1);
    const [idle, setIdle] = useState(false);

    const v = () => video.current!;
    const toggle = () => {
        if (v().paused || v().ended) {
            void v().play();
        } else {
            v().pause();
        }
    };
    const seek = (t: number) => {
        v().currentTime = Math.max(0, Math.min(duration || 0, t));
    };
    const fullscreen = () => {
        const el = box.current as (HTMLDivElement & { webkitRequestFullscreen?: () => void }) | null;
        const vid = video.current as (HTMLVideoElement & { webkitEnterFullscreen?: () => void }) | null;
        if (document.fullscreenElement) return void document.exitFullscreen();
        if (el?.requestFullscreen) return void el.requestFullscreen();
        vid?.webkitEnterFullscreen?.(); // iPhone Safari only allows fullscreen on the video itself
    };
    const cycleSpeed = () => {
        const next = speed === 1 ? 1.25 : speed === 1.25 ? 1.5 : speed === 1.5 ? 2 : 1;
        v().playbackRate = next;
        setSpeed(next);
    };

    // Controls fade out after 2.5 s of no movement while playing.
    const wake = () => {
        setIdle(false);
        window.clearTimeout(hideTimer.current);
        hideTimer.current = window.setTimeout(() => setIdle(true), 2500);
    };
    useEffect(() => () => window.clearTimeout(hideTimer.current), []);

    const onKey = (e: React.KeyboardEvent) => {
        if ((e.target as HTMLElement).tagName === 'INPUT' && e.key !== ' ') return;
        const k = e.key.toLowerCase();
        if (k === ' ' || k === 'k') toggle();
        else if (k === 'arrowright') seek(time + 5);
        else if (k === 'arrowleft') seek(time - 5);
        else if (k === 'm') v().muted = !v().muted;
        else if (k === 'f') fullscreen();
        else return;
        e.preventDefault();
        wake();
    };

    const pct = duration ? (time / duration) * 100 : 0;

    return (
        <div
            ref={box}
            className={'hs-player' + (playing ? ' playing' : '') + (playing && idle ? ' idle' : '') + (started ? ' started' : '')}
            tabIndex={0}
            role="region"
            aria-label={label}
            onKeyDown={onKey}
            onMouseMove={wake}
            onTouchStart={wake}
        >
            <video
                ref={video}
                src={src}
                poster={poster ?? undefined}
                preload="metadata"
                playsInline
                onClick={toggle}
                onPlay={() => {
                    setPlaying(true);
                    setStarted(true);
                    setEnded(false);
                    wake();
                }}
                onPause={() => setPlaying(false)}
                onEnded={() => {
                    setPlaying(false);
                    setEnded(true);
                }}
                onTimeUpdate={(e) => setTime(e.currentTarget.currentTime)}
                onLoadedMetadata={(e) => setDuration(e.currentTarget.duration)}
                onDurationChange={(e) => setDuration(e.currentTarget.duration)}
                onProgress={(e) => {
                    const b = e.currentTarget.buffered;
                    if (b.length && e.currentTarget.duration) setBuffered((b.end(b.length - 1) / e.currentTarget.duration) * 100);
                }}
                onVolumeChange={(e) => setMuted(e.currentTarget.muted)}
            />

            {!playing && (
                <button type="button" className="hs-player-big" onClick={toggle} aria-label={ended ? 'Watch again' : 'Play video'}>
                    <span>
                        <Svg name={ended ? 'replay' : 'play'} />
                    </span>
                    {!started && duration > 0 && <small>Watch · {fmt(duration)}</small>}
                </button>
            )}

            <div className="hs-player-bar" onClick={(e) => e.stopPropagation()}>
                <button type="button" onClick={toggle} aria-label={playing ? 'Pause' : 'Play'}>
                    <Svg name={playing ? 'pause' : 'play'} />
                </button>
                <div className="hs-player-seek" style={{ '--pct': `${pct}%`, '--buf': `${buffered}%` } as React.CSSProperties}>
                    <input type="range" min={0} max={duration || 0} step={0.1} value={time} onChange={(e) => seek(+e.target.value)} aria-label="Seek" aria-valuetext={`${fmt(time)} of ${fmt(duration)}`} />
                </div>
                <span className="hs-player-time">
                    {fmt(time)} / {fmt(duration)}
                </span>
                <button type="button" className="hs-player-speed" onClick={cycleSpeed} aria-label={`Playback speed ${speed}x`}>
                    {speed}x
                </button>
                <button type="button" onClick={() => (v().muted = !v().muted)} aria-label={muted ? 'Unmute' : 'Mute'}>
                    <Svg name={muted ? 'mute' : 'sound'} />
                </button>
                <button type="button" onClick={fullscreen} aria-label="Full screen">
                    <Svg name="full" />
                </button>
            </div>
        </div>
    );
}
