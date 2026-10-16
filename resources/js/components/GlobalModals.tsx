import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { FieldError, Modal, useUI } from './ui';
import { api, pkr, usd } from '@/lib/format';
import type { SharedProps } from '@/types';

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
 * - Vapi connected: starts a real browser call with the Vapi Web SDK (loaded on demand).
 * - Simulated mode: runs a timer, then records the call through the same backend path a real call uses.
 */
export function TestCallModal({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { auth } = usePage<SharedProps>().props;
    const { toast, openModal } = useUI();
    const [state, setState] = useState<'idle' | 'connecting' | 'live' | 'saving' | 'ended'>('idle');
    const [secs, setSecs] = useState(0);
    const [note, setNote] = useState('');
    const timer = useRef<number>(undefined);
    const vapi = useRef<any>(null);
    const simulated = useRef(true);
    const secsRef = useRef(0);

    const reset = () => {
        window.clearInterval(timer.current);
        vapi.current?.stop?.();
        vapi.current = null;
        setState('idle');
        setSecs(0);
        secsRef.current = 0;
        setNote('');
    };
    useEffect(() => {
        if (!open) reset();
    }, [open]);
    useEffect(() => () => window.clearInterval(timer.current), []);

    const tick = () => {
        timer.current = window.setInterval(() => {
            secsRef.current += 1;
            setSecs(secsRef.current);
        }, 1000);
    };

    const start = async () => {
        if (!auth?.bot) return;
        setState('connecting');
        setSecs(0);
        secsRef.current = 0;
        try {
            const res = await api<StartResponse>(`/api/widget/${auth.bot.publicKey}/start?test=1`, { method: 'POST' });
            simulated.current = res.simulated || !res.vapiPublicKey;
            if (!simulated.current) {
                // @ts-ignore: loaded from the CDN at runtime only when Vapi is configured
                const { default: Vapi } = await import(/* @vite-ignore */ 'https://esm.sh/@vapi-ai/web@2');
                const client = new Vapi(res.vapiPublicKey);
                client.on('call-end', () => end());
                await client.start(res.assistantId, res.overrides);
                vapi.current = client;
            } else {
                setNote('Simulated call, Vapi is not connected yet, so a sample conversation is recorded.');
            }
            setState('live');
            tick();
        } catch (e: any) {
            setState('idle');
            if (e.status === 402) {
                toast('Not enough balance for a call, top up first.', 'error');
                onClose();
                openModal('topup');
            } else {
                toast(e.message || 'Could not start the call', 'error');
            }
        }
    };

    const end = async () => {
        window.clearInterval(timer.current);
        vapi.current?.stop?.();
        vapi.current = null;
        if (!simulated.current) {
            setState('ended');
            setNote('Call ended. It appears in Call Logs as soon as Vapi sends the report.');
            return;
        }
        setState('saving');
        try {
            const r = await api<{ cost: number }>('/test-call/finish', { method: 'POST', body: { seconds: secsRef.current } });
            setState('ended');
            setNote(`Saved to Call Logs · charged ${usd(r.cost, 4)}`);
            router.reload();
        } catch (e: any) {
            setState('ended');
            toast(e.message || 'Could not save the test call', 'error');
        }
    };

    const onClick = () => {
        if (state === 'idle' || state === 'ended') start();
        else if (state === 'live') end();
    };

    const hint = {
        idle: 'Tap to start a test call · uses your balance',
        connecting: 'Connecting…',
        live: 'Connected, say hello · tap to hang up',
        saving: 'Saving the call…',
        ended: 'Call ended · tap to call again',
    }[state];

    return (
        <Modal open={open} onClose={onClose} title="Test your bot" desc="Talk to your receptionist exactly like a website visitor would.">
            <div className="rec">
                <button className={'rec-btn' + (state === 'live' ? ' on' : '')} onClick={onClick} disabled={state === 'connecting' || state === 'saving'} aria-label="Start or end test call">
                    <Icon name={state === 'connecting' || state === 'saving' ? 'loader' : state === 'live' ? 'hang' : 'phone'} className={state === 'connecting' || state === 'saving' ? 'i spin' : 'i'} />
                </button>
                <div className="rec-time num">
                    {String(Math.floor(secs / 60)).padStart(2, '0')}:{String(secs % 60).padStart(2, '0')}
                </div>
                <div className="rec-hint">{hint}</div>
                {note && (
                    <div className="rec-hint" style={{ maxWidth: 340 }}>
                        {note}
                    </div>
                )}
            </div>
        </Modal>
    );
}

/** Manual top-up: pay to our JazzCash/Easypaisa/bank account, then submit the reference + screenshot. */
export function TopUpModal({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { config } = usePage<SharedProps>().props;
    const form = useForm<{ amount: number; method: string; reference: string; proof: File | null }>({ amount: 25, method: 'jazzcash', reference: '', proof: null });
    const account = config.accounts.find((a) => a.id === form.data.method) ?? config.accounts[0];

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };
    const submit = () =>
        form.post('/billing/top-ups', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => close(),
        });

    return (
        <Modal
            open={open}
            onClose={close}
            title="Add funds"
            desc="Send the payment, then submit the transaction ID. Your balance updates once we verify it."
            footer={
                <>
                    <button className="btn btn-ghost" onClick={close}>
                        Cancel
                    </button>
                    <button className="btn btn-primary" disabled={form.processing || form.data.amount < config.minTopUp || !form.data.reference || !form.data.proof} onClick={submit}>
                        {form.processing ? 'Submitting…' : 'Submit payment'}
                    </button>
                </>
            }
        >
            <div className="amounts">
                {[10, 25, 50, 100].map((a) => (
                    <button key={a} type="button" className={a === form.data.amount ? 'on' : ''} onClick={() => form.setData('amount', a)}>
                        ${a}
                    </button>
                ))}
            </div>
            <div className="field" style={{ marginBottom: 14 }}>
                <label htmlFor="amt">Amount (USD)</label>
                <input id="amt" className={'input num' + (form.errors.amount ? ' bad' : '')} type="number" min={config.minTopUp} value={form.data.amount || ''} onChange={(e) => form.setData('amount', +e.target.value)} />
                <span className="hint">
                    You pay <b>{pkr((form.data.amount || 0) * config.pkrPerUsd)}</b> · ≈ {Math.floor((form.data.amount || 0) / config.ratePerMinute)} minutes of calls · minimum {usd(config.minTopUp)}
                </span>
                <FieldError msg={form.errors.amount} />
            </div>

            <div className="section-t">Pay with</div>
            <div className="acct">
                {config.accounts.map((a) => (
                    <button key={a.id} type="button" className={a.id === form.data.method ? 'on' : ''} onClick={() => form.setData('method', a.id)}>
                        <b>{a.label}</b>
                        {a.id === 'bank' ? 'IBAN' : 'Mobile account'}
                    </button>
                ))}
            </div>
            <div className="pay-box">
                Send <b>{pkr((form.data.amount || 0) * config.pkrPerUsd)}</b> to <b>{account.title}</b>
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
        </Modal>
    );
}
