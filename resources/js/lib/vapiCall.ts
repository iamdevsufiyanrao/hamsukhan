import { useEffect, useRef, useState } from 'react';
import { claimAudio } from './audioFocus';

export type CallPhase = 'idle' | 'connecting' | 'listening' | 'thinking' | 'speaking' | 'ended';
export type Turn = { role: 'user' | 'ai'; text: string };

const SDK_URL = 'https://esm.sh/@vapi-ai/web@2';

/** Pulls a readable sentence out of the many error shapes the Vapi SDK emits. */
function errorText(e: any): string {
    const raw = e?.error?.message ?? e?.error?.msg ?? e?.errorMsg ?? e?.message ?? (typeof e?.error === 'string' ? e.error : null);
    const text = Array.isArray(raw) ? raw.join('; ') : typeof raw === 'string' ? raw : '';
    if (/permission|notallowed/i.test(text)) return 'Microphone access is blocked. Allow the microphone for this site and try again.';
    if (/not\s*found.*(device|microphone)|requested device/i.test(text)) return 'No microphone found on this device.';
    return text || 'The call could not connect. Please try again.';
}

/**
 * One browser voice call through the Vapi Web SDK: phase (listening/thinking/speaking), live transcript and errors.
 * Used by the dashboard test call and the landing-page demo.
 */
export function useVapiCall(onEnded?: () => void) {
    const [phase, setPhase] = useState<CallPhase>('idle');
    const [turns, setTurns] = useState<Turn[]>([]);
    const [partial, setPartial] = useState<Turn | null>(null);
    const [error, setError] = useState<string | null>(null);
    const client = useRef<any>(null);
    const endedCb = useRef(onEnded);
    endedCb.current = onEnded;

    const stop = () => {
        client.current?.stop?.();
        client.current = null;
    };
    useEffect(() => () => stop(), []);

    const start = async (publicKey: string, assistantId: string, overrides: Record<string, unknown>) => {
        setError(null);
        setTurns([]);
        setPartial(null);
        setPhase('connecting');
        // A call silences any sample or recording that is playing (and is never stopped by one).
        claimAudio({ stop: () => {} });
        try {
            // @ts-ignore: loaded from the CDN only when a real call starts
            const { default: Vapi } = await import(/* @vite-ignore */ SDK_URL);
            const vapi = new Vapi(publicKey);
            client.current = vapi;
            vapi.on('call-start', () => setPhase('listening'));
            vapi.on('speech-start', () => setPhase('speaking'));
            vapi.on('speech-end', () => setPhase('listening'));
            vapi.on('call-end', () => {
                client.current = null;
                setPartial(null);
                setPhase('ended');
                endedCb.current?.();
            });
            vapi.on('error', (e: any) => {
                console.warn('[vapi]', e);
                setError(errorText(e));
                client.current?.stop?.();
                client.current = null;
                setPhase('ended');
            });
            vapi.on('message', (m: any) => {
                if (m?.type !== 'transcript' || !m.transcript) return;
                const turn: Turn = { role: m.role === 'user' ? 'user' : 'ai', text: m.transcript };
                if (m.transcriptType === 'final') {
                    setPartial(null);
                    setTurns((t) => [...t, turn]);
                    if (turn.role === 'user') setPhase('thinking');
                } else {
                    setPartial(turn);
                }
            });
            await vapi.start(assistantId, overrides);
        } catch (e) {
            setError(errorText(e));
            client.current = null;
            setPhase('ended');
        }
    };

    return { phase, turns, partial, error, start, stop, reset: () => { stop(); setPhase('idle'); setTurns([]); setPartial(null); setError(null); } };
}

export const PHASE_LABEL: Record<CallPhase, string> = {
    idle: 'Ready',
    connecting: 'Connecting…',
    listening: 'Listening, speak now',
    thinking: 'Thinking…',
    speaking: 'Speaking…',
    ended: 'Call ended',
};
