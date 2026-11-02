import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { FieldError, Modal, useUI } from './ui';
import { api, pkr } from '@/lib/format';
import type { PaymentAccount, Plan, SharedProps } from '@/types';
import { PHASE_LABEL, useVapiCall } from '@/lib/vapiCall';

type StartResponse = {
    ok: boolean;
    reason?: string;
    simulated: boolean;
    vapiPublicKey: string | null;
    assistantId: string | null;
    overrides: Record<string, unknown>;
};

/**
 * Test call from the dashboard.
 * - Vapi connected: a real browser call with live status and transcript (see useVapiCall).
 * - Simulated mode: runs a timer, then records the call through the same backend path a real call uses.
 */
export function TestCallModal({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { auth } = usePage<SharedProps>().props;
    const { toast } = useUI();
    const [simState, setSimState] = useState<'idle' | 'connecting' | 'live' | 'saving' | 'ended'>('idle');
    const [secs, setSecs] = useState(0);
    const [note, setNote] = useState('');
    const [agreed, setAgreed] = useState(false);
    const timer = useRef<number>(undefined);
    const secsRef = useRef(0);
    const call = useVapiCall(() => {
        window.clearInterval(timer.current);
        setNote('Call ended. It appears in Call Logs as soon as Vapi sends the report (usually within a minute).');
    });
    const scroller = useRef<HTMLDivElement>(null);
    // Block body on purpose: newer Chrome returns a Promise from scrollTo(), and React must not get it as a clean-up.
    useEffect(() => {
        scroller.current?.scrollTo(0, scroller.current.scrollHeight);
    }, [call.turns, call.partial]);

    const reset = () => {
        window.clearInterval(timer.current);
        call.reset();
        setSimState('idle');
        setSecs(0);
        secsRef.current = 0;
        setNote('');
    };
    useEffect(() => {
        if (!open) reset();
    }, [open]);
    useEffect(() => () => window.clearInterval(timer.current), []);
    useEffect(() => {
        if (call.error) window.clearInterval(timer.current);
    }, [call.error]);

    const tick = () => {
        window.clearInterval(timer.current);
        timer.current = window.setInterval(() => {
            secsRef.current += 1;
            setSecs(secsRef.current);
        }, 1000);
    };

    const start = async () => {
        if (!auth?.bot) return;
        setNote('');
        setSecs(0);
        secsRef.current = 0;
        setSimState('connecting');
        try {
            const res = await api<StartResponse>(`/api/widget/${auth.bot.publicKey}/start?test=1`, { method: 'POST' });
            if (!res.simulated && res.vapiPublicKey && res.assistantId) {
                setSimState('idle');
                tick();
                await call.start(res.vapiPublicKey, res.assistantId, res.overrides);
                return;
            }
            setNote('Simulated call: Vapi is not connected yet, so a sample conversation is recorded.');
            setSimState('live');
            tick();
        } catch (e: any) {
            setSimState('idle');
            if (e.status === 402) {
                toast(e.message || 'Your monthly plan is not active. Renew it from the Billing page.', 'error');
                onClose();
            } else {
                toast(e.message || 'Could not start the call', 'error');
            }
        }
    };

    const endSimulated = async () => {
        window.clearInterval(timer.current);
        setSimState('saving');
        try {
            await api('/test-call/finish', { method: 'POST', body: { seconds: secsRef.current } });
            setSimState('ended');
            setNote('Saved to Call Logs');
            router.reload();
        } catch (e: any) {
            setSimState('ended');
            toast(e.message || 'Could not save the test call', 'error');
        }
    };

    const realLive = call.phase === 'connecting' || call.phase === 'listening' || call.phase === 'thinking' || call.phase === 'speaking';
    const busy = simState === 'connecting' || simState === 'saving' || call.phase === 'connecting';
    const live = realLive || simState === 'live';

    const onClick = () => {
        if (realLive) {
            window.clearInterval(timer.current);
            return call.stop();
        }
        if (simState === 'live') return endSimulated();
        if (!agreed) return toast('Please tick the box first: the call is with an AI and is recorded.', 'error');
        start();
    };

    const simHint = {
        idle: 'Tap to start a test call · included in your plan',
        connecting: 'Connecting…',
        live: 'Connected, say hello · tap to hang up',
        saving: 'Saving the call…',
        ended: 'Call ended · tap to call again',
    }[simState];
    const hint = realLive ? `${PHASE_LABEL[call.phase]} · tap to hang up` : call.phase === 'ended' ? 'Call ended · tap to call again' : simHint;

    return (
        <Modal open={open} onClose={onClose} title="Test your bot" desc="Talk to your receptionist exactly like a website visitor would.">
            <div className="rec">
                <button className={'rec-btn' + (live ? ' on' : '')} onClick={onClick} disabled={busy} aria-label={live ? 'End test call' : 'Start test call'}>
                    <Icon name={busy ? 'loader' : live ? 'hang' : 'phone'} className={busy ? 'i spin' : 'i'} />
                </button>
                <div className="rec-time num">
                    {String(Math.floor(secs / 60)).padStart(2, '0')}:{String(secs % 60).padStart(2, '0')}
                </div>
                <div className="rec-hint" aria-live="polite">
                    {hint}
                </div>
                {call.error && (
                    <div className="rec-hint" style={{ color: 'var(--danger)', maxWidth: 360 }} role="alert">
                        {call.error}
                    </div>
                )}
                {note && (
                    <div className="rec-hint" style={{ maxWidth: 360 }}>
                        {note}
                    </div>
                )}
            </div>
            {(call.turns.length > 0 || call.partial) && (
                <div ref={scroller} style={{ maxHeight: 200, overflowY: 'auto', display: 'grid', gap: 6, padding: 10, borderRadius: 'var(--r-sm)', background: 'var(--sunken)', fontSize: 13 }}>
                    {[...call.turns, ...(call.partial ? [call.partial] : [])].map((t, i) => (
                        <div key={i} dir="auto" style={{ opacity: call.partial && i === call.turns.length ? 0.6 : 1 }}>
                            <b>{t.role === 'user' ? 'You' : 'Bot'}:</b> {t.text}
                        </div>
                    ))}
                </div>
            )}
            {!live && (
                <label className="check">
                    <input type="checkbox" checked={agreed} onChange={(e) => setAgreed(e.target.checked)} />
                    <span>I understand I am talking to an AI, the browser will ask for my microphone, and the call is recorded and transcribed.</span>
                </label>
            )}
        </Modal>
    );
}

/** Manual plan payment: pick a plan and how many months, pay to our JazzCash/Easypaisa/bank account, then submit the reference + screenshot. */
export type Gateway = 'jazzcash' | 'easypaisa';
const GATEWAY_LABEL: Record<Gateway, string> = { jazzcash: 'JazzCash', easypaisa: 'Easypaisa' };

/**
 * Pay for a plan. Online (connected JazzCash / Easypaisa merchant): the gateway's own checkout page takes the payment
 * and the plan switches on as soon as it is paid. Manual (when the super admin keeps it on): send to our account and
 * upload the receipt for review.
 */
export function PaymentModal({ open, onClose, plans, accounts, initialPlan, gateways = [], manual = true }: { open: boolean; onClose: () => void; plans: Plan[]; accounts: PaymentAccount[]; initialPlan: Plan['id']; gateways?: Gateway[]; manual?: boolean }) {
    const form = useForm<{ plan: Plan['id']; months: number; method: string; reference: string; proof: File | null }>({ plan: initialPlan, months: 1, method: 'jazzcash', reference: '', proof: null });
    const [going, setGoing] = useState<Gateway | null>(null);
    useEffect(() => {
        if (open) form.setData('plan', initialPlan);
    }, [open, initialPlan]); // eslint-disable-line react-hooks/exhaustive-deps
    const plan = plans.find((p) => p.id === form.data.plan) ?? plans[0];
    const total = plan.pricePkr * form.data.months;
    const account = accounts.find((a) => a.id === form.data.method) ?? accounts[0];
    const online = gateways.length > 0;

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };
    const payOnline = (gateway: Gateway) => {
        setGoing(gateway);
        router.post('/billing/checkout', { plan: form.data.plan, months: form.data.months, gateway }, { onFinish: () => setGoing(null) });
    };
    const submit = () =>
        form.post('/billing/payments', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => close(),
        });

    return (
        <Modal
            open={open}
            onClose={close}
            title="Get your plan"
            desc={online ? 'Pick the plan and months, then pay on the secure checkout page. Your plan switches on right away.' : 'Send the payment, then submit the transaction ID. Your plan switches on once we verify it.'}
            footer={
                <>
                    <button className="btn btn-ghost" onClick={close}>
                        Cancel
                    </button>
                    {manual && (
                        <button className={'btn ' + (online ? 'btn-ghost' : 'btn-primary')} disabled={form.processing || !form.data.reference || !form.data.proof} onClick={submit}>
                            {form.processing ? 'Submitting…' : 'Submit payment'}
                        </button>
                    )}
                </>
            }
        >
            <div className="section-t">Plan</div>
            <div className="acct">
                {plans.map((p) => (
                    <button key={p.id} type="button" className={p.id === form.data.plan ? 'on' : ''} onClick={() => form.setData('plan', p.id)}>
                        <b>{p.name}</b>
                        {pkr(p.pricePkr)}/month · {p.stores} store{p.stores > 1 ? 's' : ''}
                    </button>
                ))}
            </div>
            <FieldError msg={form.errors.plan} />
            <div className="section-t">Months</div>
            <div className="amounts">
                {[1, 3, 6, 12].map((m) => (
                    <button key={m} type="button" className={m === form.data.months ? 'on' : ''} onClick={() => form.setData('months', m)}>
                        {m}
                    </button>
                ))}
            </div>
            <FieldError msg={form.errors.months} />

            {online && (
                <div className="pay-box" style={{ display: 'grid', gap: 10 }}>
                    <span>
                        Total <b>{pkr(total)}</b> ({plan.name} × {form.data.months} month{form.data.months > 1 ? 's' : ''})
                    </span>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        {gateways.map((g) => (
                            <button key={g} type="button" className="btn btn-primary" disabled={!!going} onClick={() => payOnline(g)}>
                                <Icon name={going === g ? 'loader' : 'shield'} className={going === g ? 'i i-sm spin' : 'i i-sm'} />
                                {going === g ? 'Opening checkout…' : `Pay with ${GATEWAY_LABEL[g]}`}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {manual && (
                <>
                    <div className="section-t">{online ? 'Or send it yourself and upload the receipt' : 'Pay with'}</div>
                    <div className="acct">
                        {accounts.map((a) => (
                            <button key={a.id} type="button" className={a.id === form.data.method ? 'on' : ''} onClick={() => form.setData('method', a.id)}>
                                <b>{a.label}</b>
                                {a.id === 'bank' ? 'IBAN' : 'Mobile account'}
                            </button>
                        ))}
                    </div>
                    <div className="pay-box">
                        Send <b>{pkr(total)}</b> ({plan.name} × {form.data.months} month{form.data.months > 1 ? 's' : ''}) to <b>{account.title}</b>
                        <div className="mono">{account.number}</div>
                    </div>

                    <div className="field" style={{ marginBottom: 14 }}>
                        <label htmlFor="ref">Transaction ID</label>
                        <input id="ref" className={'input mono' + (form.errors.reference ? ' bad' : '')} placeholder="e.g. 88213409" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                        <FieldError msg={form.errors.reference} />
                    </div>
                    <div className="field">
                        <label htmlFor="proof">Payment screenshot</label>
                        <input id="proof" className="input" style={{ paddingTop: 9 }} type="file" accept="image/*" onChange={(e) => form.setData('proof', e.target.files?.[0] ?? null)} />
                        <FieldError msg={form.errors.proof} />
                    </div>
                </>
            )}
        </Modal>
    );
}
