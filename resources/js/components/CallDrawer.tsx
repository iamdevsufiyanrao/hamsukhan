import { useEffect, useMemo, useRef, useState } from 'react';
import Icon from './Icon';
import { Drawer, StatusChip } from './ui';
import { api, fmtDate, fmtDur, fmtTime } from '@/lib/format';
import type { CallDetail } from '@/types';

/** Loads one call's full transcript from the backend when opened. */
export default function CallDrawer({ callId, onClose }: { callId: number | null; onClose: () => void }) {
    const [call, setCall] = useState<CallDetail | null>(null);
    const [error, setError] = useState('');
    const audio = useRef<HTMLAudioElement>(null);
    const [playing, setPlaying] = useState(false);
    const [progress, setProgress] = useState(0);
    const bars = useMemo(() => Array.from({ length: 56 }, (_, i) => 20 + ((i * 53 + (callId ?? 0) * 7) % 80)), [callId]);

    useEffect(() => {
        setCall(null);
        setError('');
        setPlaying(false);
        setProgress(0);
        if (!callId) return;
        let live = true;
        api<CallDetail>(`/calls/${callId}`)
            .then((c) => live && setCall(c))
            .catch((e) => live && setError(e.message));
        return () => {
            live = false;
        };
    }, [callId]);

    const toggle = () => {
        const a = audio.current;
        if (!a) return;
        if (playing) a.pause();
        else a.play().catch(() => setError('Could not play the recording.'));
    };

    return (
        <Drawer open={callId !== null} onClose={onClose} title={call?.title ?? 'Call details'} sub={call ? `#${call.id} · ${fmtDate(call.startedAt)}, ${fmtTime(call.startedAt)}${call.isTest ? ' · test call' : ''}` : ''}>
            {error && <div className="empty">{error}</div>}
            {!call && !error && (
                <>
                    <div className="loading-line" style={{ width: '60%' }} />
                    <div className="loading-line" />
                    <div className="loading-line" style={{ width: '80%' }} />
                </>
            )}
            {call && (
                <>
                    <div className="meta-grid">
                        <div>
                            <small>Duration</small>
                            <strong className="num">{fmtDur(call.duration)}</strong>
                        </div>
                        <div>
                            <small>Cost</small>
                            <strong className="num">${call.cost.toFixed(4)}</strong>
                        </div>
                        <div>
                            <small>Status</small>
                            <strong>
                                <StatusChip status={call.status} />
                            </strong>
                        </div>
                    </div>
                    <div className="player">
                        <button className="pbtn" onClick={toggle} disabled={!call.recordingUrl} title={call.recordingUrl ? '' : 'No recording for this call'} aria-label={playing ? 'Pause recording' : 'Play recording'} style={!call.recordingUrl ? { opacity: 0.4 } : undefined}>
                            <Icon name={playing ? 'pause' : 'play'} />
                        </button>
                        <div className="wave">
                            {bars.map((h, i) => (
                                <span key={i} className={i < progress * bars.length ? 'p' : ''} style={{ height: h + '%' }} />
                            ))}
                        </div>
                        <span className="num" style={{ fontSize: 12, fontWeight: 700, color: 'var(--muted)' }}>
                            {call.recordingUrl ? fmtDur(call.duration) : 'No recording'}
                        </span>
                        {call.recordingUrl && (
                            <audio
                                ref={audio}
                                src={call.recordingUrl}
                                preload="none"
                                onPlay={() => setPlaying(true)}
                                onPause={() => setPlaying(false)}
                                onEnded={() => {
                                    setPlaying(false);
                                    setProgress(0);
                                }}
                                onTimeUpdate={(e) => setProgress(e.currentTarget.currentTime / (e.currentTarget.duration || 1))}
                            />
                        )}
                    </div>
                    <div className="section-t">Summary</div>
                    <p style={{ color: 'var(--text-2)', marginBottom: 20, fontSize: 13.5 }}>{call.summary || '-'}</p>
                    <div className="section-t">Transcript</div>
                    <div className="bubbles">
                        {call.transcript.length === 0 ? (
                            <div className="empty">No transcript, the call never connected.</div>
                        ) : (
                            call.transcript.map(([role, text], i) => (
                                <div key={i} className={'bub ' + role}>
                                    <small>{role === 'ai' ? 'Bot' : 'Caller'}</small>
                                    {text}
                                </div>
                            ))
                        )}
                    </div>
                </>
            )}
        </Drawer>
    );
}
