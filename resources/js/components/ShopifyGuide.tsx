import Icon from '@/components/Icon';
import { useUI } from '@/components/ui';

/** Install steps for Shopify stores: the Hamsukhan app embed, or the same one-line snippet pasted into theme.liquid. */
export default function ShopifyGuide({ publicKey, snippet }: { publicKey: string; snippet: string }) {
    const { toast } = useUI();
    const copy = (text: string, done: string) =>
        navigator.clipboard?.writeText(text).then(
            () => toast(done),
            () => toast('Select the text and copy it manually', 'error'),
        );

    return (
        <div className="card">
            <div className="card-h">
                <div>
                    <h3>Add to your Shopify store</h3>
                    <p>Use the Hamsukhan app embed, or paste the snippet into your theme.</p>
                </div>
            </div>
            <div className="card-b">
                <h4 style={{ margin: '0 0 8px' }}>Option 1 · Shopify app embed</h4>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                    <pre className="code mono" style={{ flex: 1, margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-all' }}>
                        {publicKey}
                    </pre>
                    <button className="btn btn-ink btn-sm" onClick={() => copy(publicKey, 'Bot key copied')}>
                        <Icon name="copy" className="i i-sm" />
                        Copy key
                    </button>
                </div>
                <div className="steps">
                    <div>
                        <b>1</b>Install the Hamsukhan app on your store.
                    </div>
                    <div>
                        <b>2</b>Shopify admin → Online Store → Themes → Customize → App embeds.
                    </div>
                    <div>
                        <b>3</b>Turn on "Hamsukhan voice assistant", paste the bot key above and save.
                    </div>
                </div>

                <h4 style={{ margin: '20px 0 8px' }}>Option 2 · Paste into your theme</h4>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                    <pre className="code mono" style={{ flex: 1, margin: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-all' }}>
                        {snippet}
                    </pre>
                    <button className="btn btn-ink btn-sm" onClick={() => copy(snippet, 'Snippet copied')}>
                        <Icon name="copy" className="i i-sm" />
                        Copy code
                    </button>
                </div>
                <div className="steps">
                    <div>
                        <b>1</b>Shopify admin → Online Store → Themes → ⋯ → Edit code.
                    </div>
                    <div>
                        <b>2</b>Open layout/theme.liquid and paste the line just before &lt;/body&gt;.
                    </div>
                    <div>
                        <b>3</b>Save and reload your store.
                    </div>
                </div>

                <div className="notice" style={{ marginTop: 14 }}>
                    <Icon name="alert" className="i i-sm" />
                    If you set allowed websites, add both your-store.myshopify.com and your own domain.
                </div>
            </div>
        </div>
    );
}
