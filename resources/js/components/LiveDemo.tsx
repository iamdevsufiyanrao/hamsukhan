import { useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { PHASE_LABEL, useVapiCall } from '@/lib/vapiCall';

type Lang = 'urdu' | 'english' | 'mixed';

const LANGS: [Lang, string][] = [
    ['urdu', 'Urdu'],
    ['english', 'English'],
    ['mixed', 'Urdu + English'],
];

const QUESTIONS = ['Kal evening mein haircut ka slot available hai?', 'Clinic kis time band hoti hai?', 'Charges kya hain aur location WhatsApp kar dein.'];

async function post<T>(url: string, body: unknown): Promise<T> {
    const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.ok === false) throw Object.assign(new Error(data.message || 'Something went wrong, please try again.'), { status: res.status });
    return data as T;
}

/**
 * Real voice demo on the landing page: AI + recording disclosure first, live state and transcript,
 * capped at 90 seconds and never billed (see WidgetController::demo). When the microphone is blocked or the
 * demo is off, the visitor can leave a callback request instead.
 */
export default function LiveDemo({ demoBotKey, maxSeconds = 90 }: { demoBotKey: string | null; maxSeconds?: number }) {
    const [lang, setLang] = useState<Lang>('urdu');
    const [agreed, setAgreed] = useState(false);
    const [secs, setSecs] = useState(0);
    const [startError, setStartError] = useState<string | null>(null);
    const [showCallback, setShowCallback] = useState(!demoBotKey);
    const timer = useRef<number>(undefined);
    const call = useVapiCall(() => window.clearInterval(timer.current));
    const box = useRef<HTMLDivElement>(null);
    // Block body on purpose: newer Chrome returns a Promise from scrollTo(), and React must not get it as a clean-up.
    useEffect(() => {
        box.current?.scrollTo(0, box.current.scrollHeight);
    }, [call.turns, call.partial]);
    useEffect(() => () => window.clearInterval(timer.current), []);
    useEffect(() => {
        if (call.error) {
            window.clearInterval(timer.current);
            if (/microphone/i.test(call.error)) setShowCallback(true);
        }
    }, [call.error]);

    const live = call.phase === 'connecting' || call.phase === 'listening' || call.phase === 'thinking' || call.phase === 'speaking';

    const start = async () => {
        setStartError(null);
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            setShowCallback(true);
            return setStartError('This browser cannot use the microphone here. Leave your number below and we will call you.');
        }
        try {
            const res = await post<{ vapiPublicKey: string; assistantId: string; overrides: Record<string, unknown> }>('/api/demo/start', { language: lang });
            setSecs(0);
            window.clearInterval(timer.current);
            timer.current = window.setInterval(() => setSecs((s) => s + 1), 1000);
            await call.start(res.vapiPublicKey, res.assistantId, res.overrides);
        } catch (e: any) {
            setStartError(e.message);
            if (e.status === 503) setShowCallback(true);
        }
    };

    const left = Math.max(0, maxSeconds - secs);

    return (
        <div className="lp-live-demo">
            <div className="lp-live-demo-main">
                <div className="lp-tabs" role="radiogroup" aria-label="Demo language">
                    {LANGS.map(([id, label]) => (
                        <button key={id} role="radio" aria-checked={lang === id} className={lang === id ? 'on' : ''} disabled={live} onClick={() => setLang(id)}>
                            {label}
                        </button>
                    ))}
                </div>

                <button className={'lp-live-btn' + (live ? ' on' : '')} onClick={() => (live ? call.stop() : start())} disabled={!demoBotKey || (!live && !agreed) || call.phase === 'connecting'} aria-label={live ? 'End demo call' : 'Start demo call'}>
                    <Icon name={call.phase === 'connecting' ? 'loader' : live ? 'hang' : 'mic'} className={call.phase === 'connecting' ? 'i spin' : 'i'} />
                </button>
                <div className="lp-live-state" aria-live="polite">
                    {!demoBotKey ? 'The live demo is being set up. Leave your number and we will call you with a demo.' : live ? `${PHASE_LABEL[call.phase]} · ${left}s left` : call.phase === 'ended' ? 'Demo ended · tap to try again' : 'Tap the mic and ask a question'}
                </div>
                {(startError || call.error) && (
                    <div className="lp-live-err" role="alert">
                        {startError || call.error}
                    </div>
                )}

                {!live && demoBotKey && (
                    <label className="lp-live-consent">
                        <input type="checkbox" checked={agreed} onChange={(e) => setAgreed(e.target.checked)} />
                        <span>
                            I understand I am talking to an <b>AI</b>, my browser will ask for the <b>microphone</b>, and the call is <b>recorded and transcribed</b>. Demo calls last up to {maxSeconds} seconds and are deleted within 7 days. <a href="/privacy">Privacy</a>
                        </span>
                    </label>
                )}

                <div className="lp-live-try">
                    <small>Try asking:</small>
                    {QUESTIONS.map((q) => (
                        <span key={q} dir="auto">
                            "{q}"
                        </span>
                    ))}
                </div>
            </div>

            <div className="lp-live-side">
                <div className="lp-live-transcript" ref={box} aria-label="Live transcript">
                    {call.turns.length === 0 && !call.partial && <p className="lp-live-empty">The live transcript appears here while you talk.</p>}
                    {[...call.turns, ...(call.partial ? [call.partial] : [])].map((t, i) => (
                        <div key={i} dir="auto" className={'lp-live-line ' + t.role + (call.partial && i === call.turns.length ? ' partial' : '')}>
                            <b>{t.role === 'user' ? 'You' : 'AI'}</b>
                            {t.text}
                        </div>
                    ))}
                </div>
                {call.phase === 'ended' && call.turns.length > 0 && (
                    <p className="lp-live-note">
                        In a real account, the name, service, preferred time and phone number from this call would now appear in the owner's Bookings page and be sent to WhatsApp or email.
                    </p>
                )}
                {showCallback ? <DemoCallback botKey={demoBotKey} /> : (
                    <button className="lp-inline-link" onClick={() => setShowCallback(true)}>
                        Rather not talk? Request a callback
                    </button>
                )}
            </div>
        </div>
    );
}

function DemoCallback({ botKey }: { botKey: string | null }) {
    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [agree, setAgree] = useState(false);
    const [state, setState] = useState<'idle' | 'sending' | 'sent'>('idle');
    const [err, setErr] = useState<string | null>(null);

    if (!botKey) return null;
    if (state === 'sent') return <p className="lp-live-note">Thank you! We will call or WhatsApp you soon.</p>;

    const send = async () => {
        setErr(null);
        if (!name.trim() || !phone.trim()) return setErr('Please enter your name and phone number.');
        if (!agree) return setErr('Please tick the box to agree to be contacted.');
        setState('sending');
        try {
            await post(`/api/widget/${botKey}/callback`, { name, phone, consent: true, message: 'Wants a Hamsukhan demo (from the landing page)' });
            setState('sent');
        } catch (e: any) {
            setState('idle');
            setErr(e.message);
        }
    };

    return (
        <div className="lp-live-cb">
            <b>Request a callback</b>
            <input className="input" placeholder="Your name" value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" maxLength={80} />
            <input className="input" placeholder="Phone / WhatsApp, e.g. 0300 1234567" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} autoComplete="tel" maxLength={30} />
            <label className="lp-live-consent">
                <input type="checkbox" checked={agree} onChange={(e) => setAgree(e.target.checked)} />
                <span>I agree to be contacted about a demo.</span>
            </label>
            {err && <div className="lp-live-err">{err}</div>}
            <button className="btn btn-primary" onClick={send} disabled={state === 'sending'}>
                {state === 'sending' ? 'Sending…' : 'Call me back'}
            </button>
        </div>
    );
}
