import { router, useForm } from '@inertiajs/react';
import { ReactNode, useState } from 'react';
import Icon from './Icon';
import { FieldError, useUI } from './ui';
import { api, fmtDateLong } from '@/lib/format';

export type InstallInfo = { website: string | null; shopifyEnabled: boolean; shopifyShops: string[] };

type Platform = 'shopify' | 'wordpress' | 'wix' | 'squarespace' | 'webflow' | 'other' | 'someone';

const PLATFORMS: { id: Platform; label: string; hint: string }[] = [
    { id: 'shopify', label: 'Shopify', hint: 'One click' },
    { id: 'wordpress', label: 'WordPress', hint: 'Install a plugin' },
    { id: 'wix', label: 'Wix', hint: 'Copy & paste' },
    { id: 'squarespace', label: 'Squarespace', hint: 'Copy & paste' },
    { id: 'webflow', label: 'Webflow', hint: 'Copy & paste' },
    { id: 'other', label: 'Other website', hint: 'Copy & paste' },
    { id: 'someone', label: 'Someone else runs my site', hint: 'We email them' },
];

function Steps({ items }: { items: ReactNode[] }) {
    return (
        <ol className="iw-steps">
            {items.map((s, i) => (
                <li key={i}>
                    <b>{i + 1}</b>
                    <div>{s}</div>
                </li>
            ))}
        </ol>
    );
}

/**
 * "Add to your website" for people who are not developers: pick your platform, follow 2–3 plain steps.
 * Shopify connects in one click (when the platform's Shopify app is set up), WordPress gets a ready plugin with the key
 * inside, the site builders get copy-paste steps, and anyone can email the steps to whoever runs their site.
 */
export default function InstallWizard({ src, publicKey, installedAt, info }: { src: string; publicKey: string; installedAt: string | null; info: InstallInfo }) {
    const { toast } = useUI();
    const [platform, setPlatform] = useState<Platform | null>(info.shopifyShops.length ? 'shopify' : null);
    const snippet = `<script src="${src}" data-bot="${publicKey}" defer></script>`;
    const shop = useForm({ shop: '' });
    const mail = useForm({ email: '', note: '' });
    const [checkUrl, setCheckUrl] = useState(info.website ?? '');
    const [checking, setChecking] = useState(false);
    const [result, setResult] = useState<{ found: boolean; installed: boolean; message: string } | null>(null);

    const copy = () =>
        navigator.clipboard?.writeText(snippet).then(
            () => toast('Code copied, now paste it'),
            () => toast('Select the code and copy it by hand', 'error'),
        );

    const check = async () => {
        if (!checkUrl.trim()) return;
        setChecking(true);
        try {
            const r = await api<{ found: boolean; installed: boolean; message: string }>('/bot-studio/install/check', { method: 'POST', body: { url: checkUrl } });
            setResult(r);
            if (r.found || r.installed) router.reload({ only: ['bot'] });
        } catch (e: any) {
            setResult({ found: false, installed: false, message: e.message || 'Could not check right now.' });
        } finally {
            setChecking(false);
        }
    };

    const CodeBox = (
        <div className="iw-code">
            <pre className="code mono">{snippet}</pre>
            <button type="button" className="btn btn-primary" onClick={copy}>
                <Icon name="copy" className="i i-sm" />
                Copy code
            </button>
        </div>
    );

    const body: Record<Platform, ReactNode> = {
        shopify: info.shopifyEnabled ? (
            <>
                {info.shopifyShops.length > 0 && (
                    <div className="iw-done">
                        <Icon name="check" className="i i-sm" />
                        Connected: <b>{info.shopifyShops.join(', ')}</b>. The call button is on your store. Nothing else to do.
                    </div>
                )}
                <Steps
                    items={[
                        <>Type your Shopify store name (the part before <code>.myshopify.com</code>).</>,
                        <>Press <b>Connect Shopify</b>, then <b>Install</b> on the Shopify page that opens.</>,
                        <>That's it: the call button appears on your store by itself.</>,
                    ]}
                />
                <form
                    className="iw-row"
                    onSubmit={(e) => {
                        e.preventDefault();
                        shop.post('/integrations/shopify/connect');
                    }}
                >
                    <div className="iw-shop">
                        <input className={'input' + (shop.errors.shop ? ' bad' : '')} placeholder="mystore" value={shop.data.shop} onChange={(e) => shop.setData('shop', e.target.value)} aria-label="Shopify store name" />
                        <span>.myshopify.com</span>
                    </div>
                    <button className="btn btn-primary" disabled={!shop.data.shop || shop.processing}>
                        {shop.processing ? 'Opening Shopify…' : 'Connect Shopify'}
                    </button>
                </form>
                <FieldError msg={shop.errors.shop} />
            </>
        ) : (
            <>
                <Steps
                    items={[
                        <>Press <b>Copy code</b> below.</>,
                        <>In Shopify go to <b>Online Store → Themes</b> and press <b>Customize</b>.</>,
                        <>In the left menu, under <b>Footer</b>, press <b>Add section</b> and choose <b>Custom Liquid</b>.</>,
                        <>Paste the code into the box and press <b>Save</b> (top right). Done.</>,
                    ]}
                />
                {CodeBox}
            </>
        ),
        wordpress: (
            <>
                <Steps
                    items={[
                        <>
                            Press <b>Download plugin</b>. Your key is already inside, nothing to type.
                        </>,
                        <>
                            In WordPress go to <b>Plugins → Add New Plugin → Upload Plugin</b>, choose the file and press <b>Install Now</b>.
                        </>,
                        <>
                            Press <b>Activate</b>. Done: open your website and the call button is in the corner.
                        </>,
                    ]}
                />
                <a className="btn btn-primary" href="/bot-studio/wordpress-plugin.zip">
                    <Icon name="download" className="i i-sm" />
                    Download plugin
                </a>
                <p className="iw-small">Works with any theme, WooCommerce and page builders (Elementor, Divi…). To switch it off later: Settings → Hamsukhan in WordPress.</p>
            </>
        ),
        wix: (
            <>
                <Steps
                    items={[
                        <>Press <b>Copy code</b> below.</>,
                        <>In Wix open your site's dashboard → <b>Settings → Custom code</b> (under Advanced) → <b>+ Add Custom Code</b>.</>,
                        <>Paste the code, choose <b>All pages</b> and <b>Body - end</b>, then press <b>Apply</b>. Done.</>,
                    ]}
                />
                {CodeBox}
                <p className="iw-small">Wix allows custom code on sites with a premium plan and a connected domain.</p>
            </>
        ),
        squarespace: (
            <>
                <Steps
                    items={[
                        <>Press <b>Copy code</b> below.</>,
                        <>In Squarespace go to <b>Settings → Advanced → Code Injection</b>.</>,
                        <>Paste the code into <b>Footer</b> and press <b>Save</b>. Done.</>,
                    ]}
                />
                {CodeBox}
            </>
        ),
        webflow: (
            <>
                <Steps
                    items={[
                        <>Press <b>Copy code</b> below.</>,
                        <>In Webflow open <b>Site settings → Custom code</b>.</>,
                        <>Paste the code into <b>Footer code</b>, press <b>Save</b>, then <b>Publish</b> your site. Done.</>,
                    ]}
                />
                {CodeBox}
            </>
        ),
        other: (
            <>
                <Steps
                    items={[
                        <>Press <b>Copy code</b> below.</>,
                        <>Paste it just before <code>&lt;/body&gt;</code> on every page (usually the shared footer or layout file). Done.</>,
                    ]}
                />
                {CodeBox}
                <p className="iw-small">Not sure where that is? Choose "Someone else runs my site" and we will email the steps to them.</p>
            </>
        ),
        someone: (
            <>
                <p className="iw-small" style={{ marginTop: 0 }}>
                    We send your web person the code and simple steps for their platform. Takes them about 2 minutes. Replies come back to you.
                </p>
                <form
                    className="stack"
                    onSubmit={(e) => {
                        e.preventDefault();
                        mail.post('/bot-studio/install/email', { preserveScroll: true, onSuccess: () => mail.reset() });
                    }}
                >
                    <div className="field">
                        <label htmlFor="iw-email">Their email</label>
                        <input id="iw-email" type="email" className={'input' + (mail.errors.email ? ' bad' : '')} placeholder="developer@example.com" value={mail.data.email} onChange={(e) => mail.setData('email', e.target.value)} />
                        <FieldError msg={mail.errors.email} />
                    </div>
                    <div className="field">
                        <label htmlFor="iw-note">Message (optional)</label>
                        <input id="iw-note" className="input" placeholder="Please add this today, thanks!" value={mail.data.note} onChange={(e) => mail.setData('note', e.target.value)} />
                    </div>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <button className="btn btn-primary" disabled={!mail.data.email || mail.processing}>
                            <Icon name="send" className="i i-sm" />
                            {mail.processing ? 'Sending…' : 'Email the instructions'}
                        </button>
                        <a className="btn btn-ghost" href={`https://wa.me/?text=${encodeURIComponent(`Please add our AI call button to the website. Paste this one line just before </body> on every page:\n\n${snippet}\n\nGuide: ${window.location.origin}/docs#install`)}`} target="_blank" rel="noopener">
                            Send on WhatsApp
                        </a>
                    </div>
                </form>
            </>
        ),
    };

    return (
        <div className="card">
            <div className="card-h">
                <div>
                    <h3>Add to your website</h3>
                    <p>No developer needed. Pick where your website is made and follow the steps.</p>
                </div>
            </div>
            <div className="card-b">
                <div className="iw-platforms" role="tablist" aria-label="Where is your website made?">
                    {PLATFORMS.map((p) => (
                        <button key={p.id} type="button" role="tab" aria-selected={platform === p.id} className={platform === p.id ? 'on' : ''} onClick={() => setPlatform(p.id)}>
                            <b>{p.label}</b>
                            <span>{p.hint}</span>
                        </button>
                    ))}
                </div>

                {platform && <div className="iw-body">{body[platform]}</div>}

                <div className="iw-check">
                    <div className={'notice'} style={{ background: installedAt ? 'var(--info-soft)' : undefined, color: installedAt ? 'var(--info)' : undefined }}>
                        <Icon name={installedAt ? 'check' : 'clock'} className="i i-sm" />
                        {installedAt ? `Installed, first seen on your site ${fmtDateLong(installedAt)}.` : 'Not detected on your site yet.'}
                    </div>
                    <div className="iw-row">
                        <input className="input" placeholder="yourwebsite.com" value={checkUrl} onChange={(e) => setCheckUrl(e.target.value)} aria-label="Your website address" onKeyDown={(e) => e.key === 'Enter' && check()} />
                        <button type="button" className="btn btn-ghost" disabled={checking || !checkUrl.trim()} onClick={check}>
                            <Icon name={checking ? 'loader' : 'search'} className={checking ? 'i i-sm spin' : 'i i-sm'} />
                            {checking ? 'Checking…' : 'Check my website'}
                        </button>
                    </div>
                    {result && <p className={'iw-result ' + (result.found || result.installed ? 'ok' : 'bad')}>{result.message}</p>}
                </div>
            </div>
        </div>
    );
}
