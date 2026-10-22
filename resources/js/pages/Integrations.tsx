import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/components/Icon';
import { FieldError, Modal, useUI } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { fmtDate, fmtTime, timeAgo } from '@/lib/format';

type MapRow = { target: string; source: string; value?: string };
type Integration = {
    id: number;
    name: string;
    kind: 'webhook' | 'form';
    url: string;
    events: string[];
    fieldMap: MapRow[];
    isActive: boolean;
    lastStatus: string | null;
    lastDeliveryAt: string | null;
    secret: string;
};
type Delivery = {
    id: number;
    integration: string;
    event: string;
    isTest: boolean;
    status: 'pending' | 'delivered' | 'failed';
    attempts: number;
    code: number | null;
    response: string | null;
    reference: string | null;
    customer: string | null;
    nextAttemptAt: string | null;
    createdAt: string;
};
type Calendar = { available: boolean; email: string | null; connected: boolean; error: string | null; liveBooking: boolean };
type Props = { integrations: Integration[]; deliveries: Delivery[]; sources: Record<string, string>; events: string[]; max: number; calendar: Calendar };

const EVENT_LABEL: Record<string, string> = { 'booking.created': 'New booking', 'order.created': 'New order', 'callback.created': 'Callback request' };
const STATUS_CHIP: Record<string, string> = { delivered: 'ok', failed: 'bad', pending: 'warn', retrying: 'warn' };

/** Owner's Google Calendar: bookings the bot makes go in, busy times are never offered. */
function CalendarCard({ calendar }: { calendar: Calendar }) {
    return (
        <div className="card" style={{ marginBottom: 16 }}>
            <div className="card-h">
                <div>
                    <h3>Google Calendar</h3>
                    <p>Appointments your bot books appear in your calendar, and times you are busy there are never offered to callers.</p>
                </div>
                {calendar.connected ? (
                    <button className="btn btn-ghost btn-sm" onClick={() => confirm('Disconnect Google Calendar? New bookings will no longer be added to it.') && router.delete('/integrations/google', { preserveScroll: true })}>
                        Disconnect
                    </button>
                ) : (
                    <button className="btn btn-primary btn-sm" disabled={!calendar.available} onClick={() => router.post('/integrations/google/connect')}>
                        <Icon name="calendar" className="i i-sm" />
                        Connect Google Calendar
                    </button>
                )}
            </div>
            <div className="card-b stack">
                {!calendar.available && <div className="notice">Google Calendar is not switched on for the platform yet. Ask support.</div>}
                {calendar.connected && (
                    <div className="notice" style={{ background: 'var(--info-soft)', color: 'var(--info)' }}>
                        <Icon name="check" className="i i-sm" />
                        Connected to {calendar.email ?? 'your Google account'} (primary calendar).
                    </div>
                )}
                {calendar.error && (
                    <div className="notice" style={{ color: 'var(--danger)' }}>
                        <Icon name="alert" className="i i-sm" />
                        {calendar.error}
                    </div>
                )}
                {!calendar.liveBooking && (
                    <span className="hint">
                        Live booking is off, so the bot only takes booking requests. Switch it on in <a className="link" href="/bot-studio">Bot Studio → Opening hours</a>.
                    </span>
                )}
            </div>
        </div>
    );
}

export default function Integrations({ integrations, deliveries, sources, events, max, calendar }: Props) {
    const [editing, setEditing] = useState<Integration | 'new' | null>(null);
    const [showSecret, setShowSecret] = useState<number | null>(null);
    const [openDelivery, setOpenDelivery] = useState<Delivery | null>(null);
    const [busy, setBusy] = useState<number | null>(null);
    const { toast } = useUI();

    const post = (url: string, id: number, confirmText?: string) => {
        if (confirmText && !confirm(confirmText)) return;
        router.post(url, {}, { preserveScroll: true, onStart: () => setBusy(id), onFinish: () => setBusy(null) });
    };

    return (
        <>
            <Head title="Integrations" />
            <CalendarCard calendar={calendar} />
            <div className="hello">
                <div>
                    <h2>Send bookings & orders to your software</h2>
                    <p>Every time your bot takes a booking or order, Hamsukhan delivers it to your own system, instantly, signed and retried if your server is down.</p>
                </div>
                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                    <a className="btn btn-ghost" href="/integrations/connector.zip">
                        <Icon name="download" />
                        PHP / Laravel connector
                    </a>
                    <button className="btn btn-primary" disabled={integrations.length >= max} onClick={() => setEditing('new')}>
                        <Icon name="plus" />
                        Add connector
                    </button>
                </div>
            </div>

            {integrations.length === 0 ? (
                <div className="card" style={{ marginBottom: 16 }}>
                    <div className="empty">
                        <b style={{ display: 'block', color: 'var(--text)', fontSize: 15, marginBottom: 6 }}>No connectors yet</b>
                        Add a webhook URL (any software that can receive JSON) or a form connector (fills an existing HTML order/booking form).
                    </div>
                </div>
            ) : (
                <div className="int-grid">
                    {integrations.map((i) => (
                        <div key={i.id} className="card int-card" style={!i.isActive ? { opacity: 0.65 } : undefined}>
                            <div className="top-row">
                                <div style={{ minWidth: 0 }}>
                                    <h3>{i.name}</h3>
                                    <div className="url mono">{i.url}</div>
                                </div>
                                <span className="int-kind">{i.kind === 'webhook' ? 'Webhook · JSON' : 'Form fields'}</span>
                            </div>
                            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                                {i.events.map((e) => (
                                    <span key={e} className="tag">
                                        {EVENT_LABEL[e] ?? e}
                                    </span>
                                ))}
                                {!i.isActive && <span className="chip bad">Paused</span>}
                            </div>
                            <div style={{ fontSize: 12.5, color: 'var(--muted)', fontWeight: 600 }}>
                                {i.lastDeliveryAt ? (
                                    <>
                                        Last delivery {timeAgo(i.lastDeliveryAt)} · <span className={'chip ' + (STATUS_CHIP[i.lastStatus ?? ''] ?? '')}>{i.lastStatus}</span>
                                    </>
                                ) : (
                                    'Nothing sent yet, send a test'
                                )}
                            </div>
                            <div>
                                <div className="section-t" style={{ margin: '0 0 6px' }}>
                                    Signing secret
                                </div>
                                <div className="secret-box">
                                    <code className="mono">{showSecret === i.id ? i.secret : 'whsec_' + '•'.repeat(24)}</code>
                                    <button className="icon-btn" style={{ width: 32, height: 32 }} title={showSecret === i.id ? 'Hide' : 'Show'} onClick={() => setShowSecret(showSecret === i.id ? null : i.id)}>
                                        <Icon name={showSecret === i.id ? 'eyeOff' : 'eye'} className="i i-sm" />
                                    </button>
                                    <button
                                        className="icon-btn"
                                        style={{ width: 32, height: 32 }}
                                        title="Copy"
                                        onClick={() => navigator.clipboard?.writeText(i.secret).then(() => toast('Secret copied'), () => toast('Copy failed', 'error'))}
                                    >
                                        <Icon name="copy" className="i i-sm" />
                                    </button>
                                </div>
                            </div>
                            <div className="acts">
                                <button className="btn btn-primary btn-sm" disabled={busy === i.id} onClick={() => post(`/integrations/${i.id}/test`, i.id)}>
                                    <Icon name="send" className="i i-sm" />
                                    {busy === i.id ? 'Sending…' : 'Send test'}
                                </button>
                                <button className="btn btn-ghost btn-sm" onClick={() => setEditing(i)}>
                                    Edit
                                </button>
                                <button className="btn btn-ghost btn-sm" onClick={() => post(`/integrations/${i.id}/toggle`, i.id)}>
                                    {i.isActive ? 'Pause' : 'Turn on'}
                                </button>
                                <button className="btn btn-ghost btn-sm" onClick={() => post(`/integrations/${i.id}/secret`, i.id, 'Create a new secret? Your software must be updated with it, or deliveries will be rejected.')}>
                                    <Icon name="key" className="i i-sm" />
                                </button>
                                <button
                                    className="btn btn-ghost btn-sm"
                                    style={{ color: 'var(--danger)' }}
                                    onClick={() => confirm(`Delete "${i.name}"?`) && router.delete(`/integrations/${i.id}`, { preserveScroll: true })}
                                >
                                    <Icon name="trash" className="i i-sm" />
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <div className="card" style={{ marginBottom: 16 }}>
                <div className="card-h">
                    <div>
                        <h3>Delivery log</h3>
                        <p>Latest 30 deliveries. Failed ones retry after 1 min, 5 min, 30 min and 2 hours.</p>
                    </div>
                </div>
                <div className="tbl-wrap" style={{ marginTop: 14 }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Connector</th>
                                <th>Event</th>
                                <th>Status</th>
                                <th>Your reference</th>
                                <th className="r" />
                            </tr>
                        </thead>
                        <tbody>
                            {deliveries.length === 0 && (
                                <tr style={{ cursor: 'default' }}>
                                    <td colSpan={6}>
                                        <div className="empty">Nothing delivered yet.</div>
                                    </td>
                                </tr>
                            )}
                            {deliveries.map((d) => (
                                <tr key={d.id} onClick={() => setOpenDelivery(d)}>
                                    <td className="t-time">
                                        {fmtTime(d.createdAt)}
                                        <span>{fmtDate(d.createdAt)}</span>
                                    </td>
                                    <td>
                                        <b>{d.integration}</b>
                                        {d.isTest && <span className="chip test" style={{ marginLeft: 6 }}>Test</span>}
                                    </td>
                                    <td>
                                        {EVENT_LABEL[d.event] ?? d.event}
                                        {d.customer && <div style={{ fontSize: 12, color: 'var(--muted)' }}>{d.customer}</div>}
                                    </td>
                                    <td>
                                        <span className={'chip ' + STATUS_CHIP[d.status]}>{d.status === 'pending' ? `Retrying · try ${d.attempts + 1}` : d.status[0].toUpperCase() + d.status.slice(1)}</span>
                                        {d.code && <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2 }}>HTTP {d.code}</div>}
                                    </td>
                                    <td className="mono">{d.reference ?? '-'}</td>
                                    <td className="r">
                                        {d.status !== 'delivered' && (
                                            <button
                                                className="btn btn-ghost btn-sm"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    post(`/integration-deliveries/${d.id}/retry`, -d.id);
                                                }}
                                            >
                                                <Icon name="refresh" className="i i-sm" />
                                                Retry now
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <DevGuide />

            {editing && <ConnectorModal integration={editing === 'new' ? null : editing} sources={sources} events={events} onClose={() => setEditing(null)} />}

            <Modal open={!!openDelivery} onClose={() => setOpenDelivery(null)} title="Delivery details" desc={openDelivery ? `${openDelivery.integration} · ${EVENT_LABEL[openDelivery.event] ?? openDelivery.event} · ${openDelivery.attempts} attempt(s)` : ''}>
                {openDelivery && (
                    <div className="stack">
                        <div className="kv-grid">
                            <div>
                                <small>Status</small>
                                <strong>{openDelivery.status}</strong>
                            </div>
                            <div>
                                <small>Response</small>
                                <strong>{openDelivery.code ? `HTTP ${openDelivery.code}` : 'No response'}</strong>
                            </div>
                            <div>
                                <small>Next try</small>
                                <strong>{openDelivery.nextAttemptAt ? fmtTime(openDelivery.nextAttemptAt) : '-'}</strong>
                            </div>
                        </div>
                        <div className="section-t" style={{ margin: 0 }}>
                            What your server answered
                        </div>
                        <pre className="code mono" style={{ whiteSpace: 'pre-wrap', maxHeight: 220 }}>
                            {openDelivery.response || '(empty)'}
                        </pre>
                    </div>
                )}
            </Modal>
        </>
    );
}

Integrations.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

function ConnectorModal({ integration, sources, events, onClose }: { integration: Integration | null; sources: Record<string, string>; events: string[]; onClose: () => void }) {
    const form = useForm<{ name: string; kind: 'webhook' | 'form'; url: string; events: string[]; fieldMap: MapRow[] }>({
        name: integration?.name ?? '',
        kind: integration?.kind ?? 'webhook',
        url: integration?.url ?? '',
        events: integration?.events ?? ['booking.created', 'order.created'],
        fieldMap: integration?.fieldMap?.length
            ? integration.fieldMap
            : [
                  { target: 'name', source: 'customer_name' },
                  { target: 'phone', source: 'phone' },
                  { target: 'message', source: 'request' },
              ],
    });
    const map = form.data.fieldMap;
    const setRow = (i: number, p: Partial<MapRow>) => form.setData('fieldMap', map.map((r, j) => (j === i ? { ...r, ...p } : r)));
    const submit = () => {
        const opts = { preserveScroll: true, onSuccess: onClose };
        integration ? form.put(`/integrations/${integration.id}`, opts) : form.post('/integrations', opts);
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={integration ? 'Edit connector' : 'Add a connector'}
            desc="Where should Hamsukhan send new bookings and orders?"
            footer={
                <>
                    <button className="btn btn-ghost" onClick={onClose}>
                        Cancel
                    </button>
                    <button className="btn btn-primary" disabled={form.processing} onClick={submit}>
                        {form.processing ? 'Saving…' : 'Save connector'}
                    </button>
                </>
            }
        >
            <div className="stack">
                <div className="radio-cards">
                    <button type="button" className={form.data.kind === 'webhook' ? 'on' : ''} onClick={() => form.setData('kind', 'webhook')}>
                        <b>Webhook (JSON)</b>
                        For your own software or our PHP/Laravel connector. Signed, full data.
                    </button>
                    <button type="button" className={form.data.kind === 'form' ? 'on' : ''} onClick={() => form.setData('kind', 'form')}>
                        <b>Form fields</b>
                        Fills an existing HTML form: you choose which field gets what.
                    </button>
                </div>
                <div className="field">
                    <label htmlFor="c-name">Name</label>
                    <input id="c-name" className={'input' + (form.errors.name ? ' bad' : '')} placeholder="e.g. My shop CRM" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    <FieldError msg={form.errors.name} />
                </div>
                <div className="field">
                    <label htmlFor="c-url">{form.data.kind === 'form' ? "Form's action URL" : 'Webhook URL'}</label>
                    <input
                        id="c-url"
                        className={'input mono' + (form.errors.url ? ' bad' : '')}
                        placeholder={form.data.kind === 'form' ? 'https://your-software.com/orders/store' : 'https://your-software.com/hamsukhan/webhook'}
                        value={form.data.url}
                        onChange={(e) => form.setData('url', e.target.value)}
                    />
                    <span className="hint">{form.data.kind === 'form' ? 'The URL in the form’s action="…" attribute. The form must accept POSTs without a login (or use the webhook option).' : 'Using our Laravel connector? It listens on /hamsukhan/webhook.'}</span>
                    <FieldError msg={form.errors.url} />
                </div>
                <div className="field">
                    <label>Send when</label>
                    <div className="check-row">
                        {events.map((e) => (
                            <label key={e}>
                                <input
                                    type="checkbox"
                                    checked={form.data.events.includes(e)}
                                    onChange={(ev) => form.setData('events', ev.target.checked ? [...form.data.events, e] : form.data.events.filter((x) => x !== e))}
                                />
                                {EVENT_LABEL[e] ?? e}
                            </label>
                        ))}
                    </div>
                    <FieldError msg={form.errors.events} />
                </div>

                {form.data.kind === 'form' && (
                    <div className="field">
                        <label>Field mapping</label>
                        <div className="map-row map-head">
                            <span>Your form field name</span>
                            <span>Gets this value</span>
                            <span>Fixed text</span>
                            <span />
                        </div>
                        {map.map((r, i) => (
                            <div className="map-row" key={i}>
                                <input className="input mono" placeholder="name=&quot;…&quot;" value={r.target} onChange={(e) => setRow(i, { target: e.target.value })} />
                                <select className="select" value={r.source} onChange={(e) => setRow(i, { source: e.target.value })}>
                                    <option value="">Fixed text →</option>
                                    {Object.entries(sources).map(([k, label]) => (
                                        <option key={k} value={k}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                                <input className="input" placeholder={r.source ? '' : 'e.g. phone-bot'} disabled={!!r.source} value={r.value ?? ''} onChange={(e) => setRow(i, { value: e.target.value })} />
                                <button className="icon-btn" style={{ width: 32, height: 32 }} title="Remove" onClick={() => form.setData('fieldMap', map.filter((_, j) => j !== i))}>
                                    <Icon name="trash" className="i i-sm" />
                                </button>
                            </div>
                        ))}
                        <button className="btn btn-ghost btn-sm" style={{ alignSelf: 'flex-start' }} onClick={() => form.setData('fieldMap', [...map, { target: '', source: '' }])}>
                            <Icon name="plus" className="i i-sm" />
                            Add field
                        </button>
                        <FieldError msg={form.errors.fieldMap} />
                    </div>
                )}
            </div>
        </Modal>
    );
}

function DevGuide() {
    return (
        <div className="card">
            <div className="card-h">
                <div>
                    <h3>For your developer</h3>
                    <p>Install the connector in any Laravel or plain PHP software in a few minutes.</p>
                </div>
            </div>
            <div className="card-b stack">
                <div className="steps" style={{ marginTop: 0 }}>
                    <div>
                        <b>1</b>
                        <span>
                            Download the connector above and put it in your project (e.g. <span className="mono">packages/hamsukhan-connector</span>), then add it with Composer as a path repository and run{' '}
                            <span className="mono">composer require hamsukhan/connector</span>.
                        </span>
                    </div>
                    <div>
                        <b>2</b>
                        <span>
                            In <span className="mono">.env</span>: <span className="mono">HAMSUKHAN_WEBHOOK_SECRET=</span> (the signing secret above) and <span className="mono">HAMSUKHAN_HANDLER=App\Hamsukhan\SaveOrder</span>.
                        </span>
                    </div>
                    <div>
                        <b>3</b>
                        <span>Write the handler, it gets every booking/order and returns your own record id:</span>
                    </div>
                </div>
                <pre className="code mono" style={{ whiteSpace: 'pre' }}>{`namespace App\\Hamsukhan;

use Hamsukhan\\Connector\\Contracts\\EventHandler;
use Hamsukhan\\Connector\\Event;

class SaveOrder implements EventHandler
{
    public function handle(Event $event): ?string
    {
        if ($event->isTest()) return 'TEST-OK';

        $order = \\App\\Models\\Order::create([
            'customer' => $event->customerName(),
            'phone'    => $event->phone(),
            'items'    => $event->field('items'),
            'address'  => $event->field('address'),
            'source'   => 'hamsukhan',
        ]);

        return 'ORD-'.$order->id;   // shown next to the booking in Hamsukhan
    }
}`}</pre>
                <p style={{ fontSize: 12.5, color: 'var(--muted)' }}>
                    Not on Laravel? Use <span className="mono">Hamsukhan\Connector\Receiver::run($secret, fn ($event) =&gt; …)</span> in a single PHP file, see the README inside the zip.
                </p>
            </div>
        </div>
    );
}
