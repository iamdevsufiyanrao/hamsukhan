import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Icon, { IconName } from '@/components/Icon';
import { FieldError, useUI } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { timeAgo } from '@/lib/format';

type Secret = { set: boolean; hint: string | null; source: 'admin' | 'env' | null };
type Values = Record<string, any>;
type Tab = 'site' | 'seo' | 'billing' | 'providers' | 'plugins' | 'system';
type Props = {
    tab: Tab;
    values: Values;
    plugins: {
        connectors: { id: number; name: string; kind: string; url: string; isActive: boolean; lastStatus: string | null; lastDeliveryAt: string | null; account: { id: number; name: string; business: string | null } | null }[];
        stats: { total: number; active: number; delivered24h: number; failed24h: number; pending: number };
        packageVersion: string;
    };
    system: {
        cron: { lastRun: string | null; healthy: boolean; line: string; jobs: { name: string; command: string; every: string }[] };
        checks: { label: string; value: string; ok: boolean }[];
        webhookUrl: string;
        settingsUpdatedAt: string | null;
    };
};

const TABS: { id: Tab; label: string; icon: IconName }[] = [
    { id: 'site', label: 'Site', icon: 'home' },
    { id: 'seo', label: 'SEO', icon: 'search' },
    { id: 'billing', label: 'Billing', icon: 'wallet' },
    { id: 'providers', label: 'API keys', icon: 'key' },
    { id: 'plugins', label: 'Plugins', icon: 'plug' },
    { id: 'system', label: 'Cron & system', icon: 'clock' },
];

export default function Settings(props: Props) {
    const [tab, setTab] = useState<Tab>(props.tab);
    const change = (t: Tab) => {
        setTab(t);
        window.history.replaceState(window.history.state, '', `/admin/settings?tab=${t}`);
    };

    return (
        <>
            <Head title="Settings" />
            <div className="tabs" role="tablist">
                {TABS.map((t) => (
                    <button key={t.id} role="tab" aria-selected={tab === t.id} className={tab === t.id ? 'on' : ''} onClick={() => change(t.id)}>
                        <Icon name={t.icon} />
                        {t.label}
                    </button>
                ))}
            </div>
            {tab === 'site' && <SiteTab values={props.values} />}
            {tab === 'seo' && <SeoTab values={props.values} />}
            {tab === 'billing' && <BillingTab values={props.values} />}
            {tab === 'providers' && <ProvidersTab values={props.values} webhookUrl={props.system.webhookUrl} />}
            {tab === 'plugins' && <PluginsTab values={props.values} plugins={props.plugins} />}
            {tab === 'system' && <SystemTab system={props.system} />}
        </>
    );
}

Settings.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

/* ---------- shared bits ---------- */
function useGroupForm(group: string, keys: string[], values: Values) {
    const form = useForm<Values>(Object.fromEntries(keys.map((k) => [k, values[k] ?? ''])));
    const save = () => form.patch(`/admin/settings/${group}`, { preserveScroll: true, onSuccess: () => form.setDefaults() });
    return { form, save };
}

function Text({ form, k, label, hint, type = 'text', placeholder, wide }: { form: any; k: string; label: string; hint?: string; type?: string; placeholder?: string; wide?: boolean }) {
    return (
        <div className="field" style={wide ? { gridColumn: '1 / -1' } : undefined}>
            <label htmlFor={k}>{label}</label>
            {type === 'textarea' ? (
                <textarea id={k} className={'textarea' + (form.errors[k] ? ' bad' : '')} style={{ minHeight: 90 }} placeholder={placeholder} value={form.data[k] ?? ''} onChange={(e) => form.setData(k, e.target.value)} />
            ) : (
                <input id={k} className={'input' + (form.errors[k] ? ' bad' : '')} type={type} step={type === 'number' ? 'any' : undefined} placeholder={placeholder} value={form.data[k] ?? ''} onChange={(e) => form.setData(k, e.target.value)} />
            )}
            {hint && <span className="hint">{hint}</span>}
            <FieldError msg={form.errors[k]} />
        </div>
    );
}

function Toggle({ form, k, label, hint }: { form: any; k: string; label: string; hint?: string }) {
    return (
        <label className="check" style={{ marginTop: 0, gridColumn: '1 / -1' }}>
            <input type="checkbox" checked={!!form.data[k]} onChange={(e) => form.setData(k, e.target.checked)} />
            <span>
                <b style={{ color: 'var(--text)' }}>{label}</b>
                {hint && <span style={{ display: 'block' }}>{hint}</span>}
            </span>
        </label>
    );
}

function Card({ title, desc, children, form, save }: { title: string; desc: string; children: React.ReactNode; form?: any; save?: () => void }) {
    return (
        <div className="card" style={{ marginBottom: 16 }}>
            <div className="card-h">
                <div>
                    <h3>{title}</h3>
                    <p>{desc}</p>
                </div>
            </div>
            <div className="card-b stack">
                <div className="grid-2">{children}</div>
                {form && save && (
                    <div className="form-foot">
                        <span>{form.isDirty ? 'Unsaved changes' : 'Saved'}</span>
                        <button className="btn btn-primary" disabled={!form.isDirty || form.processing} onClick={save}>
                            Save
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

/* ---------- tabs ---------- */
function SiteTab({ values }: { values: Values }) {
    const { form, save } = useGroupForm('site', ['site_name', 'support_email', 'support_phone', 'allow_signups', 'announcement', 'terms_url', 'privacy_url'], values);
    return (
        <Card title="Site" desc="Name, contact details and sign-up rules for the whole platform." form={form} save={save}>
            <Text form={form} k="site_name" label="Site name" hint="Shown in browser tabs and emails." />
            <Text form={form} k="support_email" label="Support email" type="email" />
            <Text form={form} k="support_phone" label="Support phone / WhatsApp" />
            <Text form={form} k="terms_url" label="Terms URL" placeholder="https://…" />
            <Text form={form} k="privacy_url" label="Privacy policy URL" placeholder="https://…" />
            <div />
            <Text form={form} k="announcement" label="Announcement banner" type="textarea" placeholder="e.g. Scheduled maintenance on Sunday 2–3am" hint="Shown at the top of every customer's dashboard. Leave empty to hide." wide />
            <Toggle form={form} k="allow_signups" label="Allow new sign-ups" hint="Turn off to stop new accounts (existing customers can still log in)." />
        </Card>
    );
}

function SeoTab({ values }: { values: Values }) {
    const { form, save } = useGroupForm('seo', ['seo_title', 'seo_description', 'seo_keywords', 'seo_og_image', 'seo_index', 'seo_ga_id'], values);
    return (
        <>
            <Card title="Search & sharing" desc="Meta tags for the public pages (login, sign-up) and link previews on WhatsApp, Facebook, LinkedIn." form={form} save={save}>
                <Text form={form} k="seo_title" label="Meta title" hint={`${String(form.data.seo_title ?? '').length} / 60 characters recommended`} wide />
                <Text form={form} k="seo_description" label="Meta description" type="textarea" hint={`${String(form.data.seo_description ?? '').length} / 160 characters recommended`} wide />
                <Text form={form} k="seo_keywords" label="Keywords" placeholder="comma, separated" wide />
                <Text form={form} k="seo_og_image" label="Share image URL (1200×630)" placeholder="https://…/og.png" />
                <Text form={form} k="seo_ga_id" label="Google Analytics ID" placeholder="G-XXXXXXXXXX" />
                <Toggle form={form} k="seo_index" label="Let search engines index the site" hint="Turn off for a staging/test server." />
            </Card>
            <div className="card">
                <div className="card-h">
                    <div>
                        <h3>Google preview</h3>
                        <p>Roughly how the site appears in search results</p>
                    </div>
                </div>
                <div className="card-b">
                    <div style={{ maxWidth: 600 }}>
                        <div style={{ color: 'var(--info)', fontSize: 18, fontWeight: 600 }}>{form.data.seo_title || 'Untitled'}</div>
                        <div style={{ color: 'var(--accent-ink)', fontSize: 13 }}>{window.location.origin}</div>
                        <div style={{ color: 'var(--text-2)', fontSize: 13.5 }}>{String(form.data.seo_description || '').slice(0, 160)}</div>
                    </div>
                </div>
            </div>
        </>
    );
}

function BillingTab({ values }: { values: Values }) {
    const keys = ['rate_per_minute', 'min_topup_usd', 'pkr_per_usd', 'low_balance_usd', 'jazzcash_title', 'jazzcash_number', 'easypaisa_title', 'easypaisa_number', 'bank_title', 'bank_number'];
    const { form, save } = useGroupForm('billing', keys, values);
    return (
        <Card title="Pricing & payment accounts" desc="What customers pay per minute, and where they send manual top-ups." form={form} save={save}>
            <Text form={form} k="rate_per_minute" label="Price per minute (USD)" type="number" hint="Your cost is roughly $0.10–0.12/min (Vapi + ElevenLabs + LLM). Keep a margin." />
            <Text form={form} k="pkr_per_usd" label="PKR per USD" type="number" hint="Used to show PKR amounts for top-ups." />
            <Text form={form} k="min_topup_usd" label="Minimum top-up (USD)" type="number" />
            <Text form={form} k="low_balance_usd" label="Low-balance warning below (USD)" type="number" />
            <Text form={form} k="jazzcash_title" label="JazzCash account title" />
            <Text form={form} k="jazzcash_number" label="JazzCash number" />
            <Text form={form} k="easypaisa_title" label="Easypaisa account title" />
            <Text form={form} k="easypaisa_number" label="Easypaisa number" />
            <Text form={form} k="bank_title" label="Bank account title" />
            <Text form={form} k="bank_number" label="Bank IBAN" />
        </Card>
    );
}

function ProvidersTab({ values, webhookUrl }: { values: Values; webhookUrl: string }) {
    const secretKeys = ['vapi_private_key', 'vapi_webhook_secret', 'elevenlabs_api_key', 'openai_api_key'];
    const plainKeys = ['vapi_public_key', 'vapi_model', 'elevenlabs_model'];
    const form = useForm<Values>({ ...Object.fromEntries(secretKeys.map((k) => [k, ''])), ...Object.fromEntries(plainKeys.map((k) => [k, values[k] ?? ''])) });
    const { toast } = useUI();
    const save = () => form.patch('/admin/settings/providers', { preserveScroll: true, onSuccess: () => form.setData(Object.fromEntries([...secretKeys.map((k) => [k, '']), ...plainKeys.map((k) => [k, form.data[k]])])) });

    const SecretField = ({ k, label, hint }: { k: string; label: string; hint?: string }) => {
        const s: Secret = values[k];
        return (
            <div className="field">
                <label htmlFor={k}>
                    {label}{' '}
                    {s?.set ? <span className="chip ok">{s.source === 'env' ? 'set in .env' : 'set'} · {s.hint}</span> : <span className="chip warn">not set</span>}
                </label>
                <div style={{ display: 'flex', gap: 8 }}>
                    <input id={k} className={'input mono' + (form.errors[k] ? ' bad' : '')} type="password" autoComplete="off" placeholder={s?.set ? 'Leave empty to keep the current key' : 'Paste the key'} value={form.data[k]} onChange={(e) => form.setData(k, e.target.value)} />
                    {s?.source === 'admin' && (
                        <button className="btn btn-ghost" title="Remove this key" onClick={() => confirm('Remove this key?') && router.delete(`/admin/settings/secret/${k}`, { preserveScroll: true })}>
                            <Icon name="trash" className="i i-sm" />
                        </button>
                    )}
                </div>
                {hint && <span className="hint">{hint}</span>}
                <FieldError msg={form.errors[k]} />
            </div>
        );
    };

    return (
        <>
            <div className="notice" style={{ marginBottom: 16 }}>
                <Icon name="shield" className="i i-sm" />
                Keys are stored encrypted and are never shown again after saving, only the last 4 characters. Keys saved here override the ones in .env.
            </div>
            <div className="card" style={{ marginBottom: 16 }}>
                <div className="card-h">
                    <div>
                        <h3>Vapi: call engine</h3>
                        <p>dashboard.vapi.ai → Organization → API keys</p>
                    </div>
                    <button className="btn btn-ghost btn-sm" onClick={() => router.post('/admin/settings/test/vapi', {}, { preserveScroll: true })}>
                        <Icon name="refresh" className="i i-sm" />
                        Test connection
                    </button>
                </div>
                <div className="card-b grid-2">
                    {SecretField({ k: 'vapi_private_key', label: 'Private key', hint: 'Server only. Used to create/update assistants.' })}
                    <div className="field">
                        <label htmlFor="vapi_public_key">Public key</label>
                        <input id="vapi_public_key" className="input mono" value={form.data.vapi_public_key} onChange={(e) => form.setData('vapi_public_key', e.target.value)} />
                        <span className="hint">Sent to browsers to start web calls. Restrict it to your domains in Vapi.</span>
                    </div>
                    {SecretField({ k: 'vapi_webhook_secret', label: 'Webhook secret', hint: 'Any long random string, required in production.' })}
                    <div className="field">
                        <label htmlFor="vapi_model">LLM model</label>
                        <input id="vapi_model" className="input mono" value={form.data.vapi_model} onChange={(e) => form.setData('vapi_model', e.target.value)} />
                    </div>
                    <div className="field" style={{ gridColumn: '1 / -1' }}>
                        <label>Server URL to paste in Vapi</label>
                        <div className="secret-box">
                            <code className="mono">{webhookUrl}</code>
                            <button className="icon-btn" style={{ width: 32, height: 32 }} onClick={() => navigator.clipboard?.writeText(webhookUrl).then(() => toast('Copied'))}>
                                <Icon name="copy" className="i i-sm" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div className="card" style={{ marginBottom: 16 }}>
                <div className="card-h">
                    <div>
                        <h3>ElevenLabs: voice cloning</h3>
                        <p>elevenlabs.io → Profile → API keys. Needs a plan with Instant Voice Cloning.</p>
                    </div>
                    <button className="btn btn-ghost btn-sm" onClick={() => router.post('/admin/settings/test/elevenlabs', {}, { preserveScroll: true })}>
                        <Icon name="refresh" className="i i-sm" />
                        Test connection
                    </button>
                </div>
                <div className="card-b grid-2">
                    {SecretField({ k: 'elevenlabs_api_key', label: 'API key', hint: 'Also add this key in Vapi → Provider credentials, so cloned voices work in calls.' })}
                    <div className="field">
                        <label htmlFor="elevenlabs_model">Voice model</label>
                        <input id="elevenlabs_model" className="input mono" value={form.data.elevenlabs_model} onChange={(e) => form.setData('elevenlabs_model', e.target.value)} />
                        <span className="hint">eleven_multilingual_v2 supports Urdu.</span>
                    </div>
                </div>
            </div>
            <div className="card" style={{ marginBottom: 16 }}>
                <div className="card-h">
                    <div>
                        <h3>OpenAI (optional)</h3>
                        <p>Only if you want the LLM billed to your own OpenAI account instead of through Vapi.</p>
                    </div>
                </div>
                <div className="card-b grid-2">{SecretField({ k: 'openai_api_key', label: 'API key' })}</div>
            </div>
            <div className="form-foot">
                <span>{form.isDirty ? 'Unsaved changes' : ''}</span>
                <button className="btn btn-primary" disabled={!form.isDirty || form.processing} onClick={save}>
                    Save keys
                </button>
            </div>
        </>
    );
}

function PluginsTab({ values, plugins }: { values: Values; plugins: Props['plugins'] }) {
    const { form, save } = useGroupForm('plugins', ['connectors_enabled', 'form_connectors_enabled', 'connectors_max_per_bot'], values);
    const s = plugins.stats;
    return (
        <>
            <div className="summary-row">
                <div className="card">
                    <small>Connectors</small>
                    <strong className="num">
                        {s.active} / {s.total} active
                    </strong>
                </div>
                <div className="card">
                    <small>Delivered (24h)</small>
                    <strong className="num">{s.delivered24h}</strong>
                </div>
                <div className="card">
                    <small>Failed (24h)</small>
                    <strong className="num" style={s.failed24h ? { color: 'var(--danger)' } : undefined}>
                        {s.failed24h}
                    </strong>
                </div>
                <div className="card">
                    <small>Waiting to retry</small>
                    <strong className="num">{s.pending}</strong>
                </div>
            </div>
            <Card title="Plugin rules" desc="What customers are allowed to connect." form={form} save={save}>
                <Toggle form={form} k="connectors_enabled" label="Customers can add connectors" hint="Off = no new connectors and no deliveries for anyone." />
                <Toggle form={form} k="form_connectors_enabled" label="Allow 'form fields' connectors" hint="Off = only signed webhooks (safer)." />
                <Text form={form} k="connectors_max_per_bot" label="Max connectors per account" type="number" />
                <div className="field">
                    <label>Connector package</label>
                    <a className="btn btn-ghost" href="/admin/settings/package.zip" style={{ alignSelf: 'flex-start' }}>
                        <Icon name="download" />
                        hamsukhan/connector v{plugins.packageVersion}
                    </a>
                </div>
            </Card>
            <div className="card">
                <div className="card-h">
                    <div>
                        <h3>All connectors</h3>
                        <p>Every customer's connector. Pause one that is misbehaving.</p>
                    </div>
                </div>
                <div className="tbl-wrap" style={{ marginTop: 14 }}>
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Connector</th>
                                <th>Last delivery</th>
                                <th className="r" />
                            </tr>
                        </thead>
                        <tbody>
                            {plugins.connectors.length === 0 && (
                                <tr style={{ cursor: 'default' }}>
                                    <td colSpan={4}>
                                        <div className="empty">No connectors yet.</div>
                                    </td>
                                </tr>
                            )}
                            {plugins.connectors.map((c) => (
                                <tr key={c.id} style={{ cursor: 'default', opacity: c.isActive ? 1 : 0.6 }}>
                                    <td className="bk-who">
                                        {c.account ? (
                                            <Link href={`/admin/accounts/${c.account.id}`} className="link">
                                                {c.account.name}
                                            </Link>
                                        ) : (
                                            '-'
                                        )}
                                        <span>{c.account?.business}</span>
                                    </td>
                                    <td>
                                        <b>{c.name}</b> <span className="int-kind">{c.kind === 'webhook' ? 'Webhook' : 'Form'}</span>
                                        <div className="mono" style={{ fontSize: 12, color: 'var(--muted)', wordBreak: 'break-all' }}>
                                            {c.url}
                                        </div>
                                    </td>
                                    <td>
                                        {c.lastDeliveryAt ? (
                                            <>
                                                <span className={'chip ' + (c.lastStatus === 'delivered' ? 'ok' : c.lastStatus === 'failed' ? 'bad' : 'warn')}>{c.lastStatus}</span>
                                                <div style={{ fontSize: 12, color: 'var(--muted)' }}>{timeAgo(c.lastDeliveryAt)}</div>
                                            </>
                                        ) : (
                                            '-'
                                        )}
                                    </td>
                                    <td className="r">
                                        <button className="btn btn-ghost btn-sm" onClick={() => router.post(`/admin/settings/connectors/${c.id}/toggle`, {}, { preserveScroll: true })}>
                                            {c.isActive ? 'Pause' : 'Turn on'}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

function SystemTab({ system }: { system: Props['system'] }) {
    const { toast } = useUI();
    const c = system.cron;
    return (
        <>
            <div className="card" style={{ marginBottom: 16 }}>
                <div className="card-h">
                    <div>
                        <h3>Cron</h3>
                        <p>Retries connector deliveries every minute. Needs one cron line on the server.</p>
                    </div>
                    <button className="btn btn-ghost btn-sm" onClick={() => router.post('/admin/settings/cron/run', {}, { preserveScroll: true })}>
                        <Icon name="play" className="i i-sm" />
                        Run now
                    </button>
                </div>
                <div className="card-b stack">
                    <div className={'alert ' + (c.healthy ? 'imp' : 'warn')} style={{ marginBottom: 0 }}>
                        <Icon name={c.healthy ? 'check' : 'alert'} />
                        <span className="grow">{c.healthy ? `Cron is running, last tick ${timeAgo(c.lastRun!)}.` : c.lastRun ? `Cron last ran ${timeAgo(c.lastRun)}, it looks stopped.` : 'Cron has never run on this server. Add the line below (or run php artisan schedule:work locally).'}</span>
                    </div>
                    <div className="field">
                        <label>Cron line (crontab -e / cPanel → Cron Jobs)</label>
                        <div className="secret-box">
                            <code className="mono">{c.line}</code>
                            <button className="icon-btn" style={{ width: 32, height: 32 }} onClick={() => navigator.clipboard?.writeText(c.line).then(() => toast('Copied'))}>
                                <Icon name="copy" className="i i-sm" />
                            </button>
                        </div>
                    </div>
                    <table className="tbl">
                        <tbody>
                            {c.jobs.map((j) => (
                                <tr key={j.command} style={{ cursor: 'default' }}>
                                    <td>
                                        <b>{j.name}</b>
                                    </td>
                                    <td className="mono">{j.command}</td>
                                    <td className="r">{j.every}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <div className="card">
                <div className="card-h">
                    <div>
                        <h3>System health</h3>
                        <p>Things to fix before going live are marked red.</p>
                    </div>
                </div>
                <div className="tbl-wrap" style={{ marginTop: 14 }}>
                    <table className="tbl">
                        <tbody>
                            {system.checks.map((ch) => (
                                <tr key={ch.label} style={{ cursor: 'default' }}>
                                    <td style={{ width: 220 }}>
                                        <b>{ch.label}</b>
                                    </td>
                                    <td className="mono">{ch.value}</td>
                                    <td className="r">
                                        <span className={'chip ' + (ch.ok ? 'ok' : 'bad')}>{ch.ok ? 'OK' : 'Fix'}</span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
