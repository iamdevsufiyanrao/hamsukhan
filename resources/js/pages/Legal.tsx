import { Head, Link } from '@inertiajs/react';
import { Logo } from '@/components/Icon';

type Props = { doc: 'terms' | 'privacy'; site: string; supportEmail: string | null; supportPhone: string | null; updated: string };

type Section = { h: string; p: string[] };

/** Plain-language template. It must be reviewed by qualified Pakistani counsel before the paid launch. */
function terms(site: string): Section[] {
    return [
        { h: 'What the service is', p: [`${site} gives your business an AI voice receptionist that talks to visitors on your website in Urdu and English. It is a website voice conversation in the browser, not a telephone line.`, 'It answers questions from the information you approve, collects booking, order and callback details, and shows you a transcript and summary of each conversation.'] },
        { h: 'Your account', p: ['You must give correct details, keep your password private and tell us straight away if you think someone else has used your account.', 'You are responsible for everything done through your account, including what your bot is told to say.'] },
        { h: 'What your bot may say', p: ['You are responsible for the prices, hours, services and policies you give the bot. Keep them accurate and up to date.', 'The AI can make mistakes. It is told to only use your approved information and to offer a callback when unsure, but you must review conversations and correct wrong information.', 'Do not use the service for medical diagnosis, emergencies, legal or financial advice, or to collect card numbers, passwords, OTP codes or CNIC numbers.'] },
        { h: 'Telling callers about the AI and recording', p: ['Callers are told before a call starts that they are talking to an AI and that the call is recorded and transcribed. Do not remove or hide this notice.', 'You are responsible for having a lawful reason to record and process your callers’ information and for answering their requests to see or delete it.'] },
        { h: 'Voice cloning', p: ['You may only clone your own voice, or the voice of a person who has given you clear permission. We keep a record of each consent (account, time, IP address).', 'Cloning someone’s voice without permission, impersonation or fraud leads to immediate suspension and may be reported.', 'You can delete a cloned voice at any time. Cloned voice models are never given out for download.'] },
        { h: 'Prices, wallet and payments', p: ['Calls are paid from a prepaid wallet. You top up by JazzCash, Easypaisa or bank transfer and the payment is added once we confirm it.', 'Billable time starts when the call connects and ends when it ends. Failed connections and the public demo on our website are never charged.', 'Prices are shown before you top up. Unused balance refunds are handled case by case: write to support.'] },
        { h: 'Acceptable use', p: ['No spam, abuse, unlawful content, attempts to break or overload the service, or reselling without our written agreement.', 'We may pause a bot or account that breaks these terms or puts callers at risk.'] },
        { h: 'Availability and changes', p: ['We work to keep the service running but cannot promise it will never be interrupted, for example when a voice or AI provider has an outage.', 'We may update these terms. We will tell you about important changes before they apply.'] },
        { h: 'Liability', p: ['To the extent the law allows, our total liability is limited to the amount you paid us in the three months before the claim.', 'We are not responsible for losses caused by wrong information you gave the bot.'] },
        { h: 'Ending the service', p: ['You can delete your account at any time from Settings. We can close accounts that break these terms.'] },
    ];
}

function privacy(site: string): Section[] {
    return [
        { h: 'Who we are', p: [`${site} provides AI voice receptionists for businesses. For your account data we decide how it is used. For callers’ data we act on behalf of the business whose website they called.`] },
        { h: 'What we collect', p: ['Account data: name, email, business details, password (stored hashed), payment references and screenshots for top-ups.', 'Caller data: call audio, transcript, summary, and details the caller chose to give, such as name, phone/WhatsApp number, requested service and preferred time.', 'Voice samples: recordings uploaded for voice cloning, plus the consent record.', 'Business documents and facts you upload for the bot.', 'Technical data: IP address, browser, and logs needed for security and billing.'] },
        { h: 'What we never ask for', p: ['The bot is told never to ask for card numbers, CVV, bank PINs, passwords, OTP codes or CNIC numbers. Please do not give them in a call.'] },
        { h: 'How we use it', p: ['To run the conversation, show the business its call logs and bookings, send notifications, bill the wallet, prevent abuse and fix problems.', 'We do not sell personal data. We do not use business or caller data to train AI models without explicit written agreement.'] },
        { h: 'Service providers', p: ['Calls are processed by Vapi (call engine), ElevenLabs (voice and speech recognition), OpenAI (language model) and Deepgram (speech recognition, English). The site is hosted by Hostinger. They only process data to provide the service to us.'] },
        { h: 'How long we keep it', p: ['Call recordings, transcripts and bookings are kept while the business account is open, unless the business deletes them sooner.', 'Public demo calls on our website are kept for at most 7 days, only to prevent abuse.', 'Voice samples and cloned voices are deleted when the owner deletes the voice or the account.', 'When an account is deleted, its data is removed except what the law requires us to keep, such as payment records.'] },
        { h: 'Security', p: ['Connections use HTTPS. Provider keys are stored encrypted. Access to customer data is limited to the account owner and, for support, our administrators, and every administrator action on an account is logged.'] },
        { h: 'Your choices', p: ['Callers can ask the business, or us, to see or delete their information. Account owners can export call logs and delete their account from Settings.', 'If you do not want to talk to the AI, use "Request a callback" on the website instead.'] },
        { h: 'Cookies', p: ['We use cookies needed to sign in and keep the site secure. The call widget on business websites does not set tracking cookies.'] },
    ];
}

export default function Legal({ doc, site, supportEmail, supportPhone, updated }: Props) {
    const title = doc === 'terms' ? 'Terms of Service' : 'Privacy Policy';
    const sections = doc === 'terms' ? terms(site) : privacy(site);

    return (
        <div style={{ minHeight: '100vh', background: 'var(--bg)' }}>
            <Head title={title} />
            <div style={{ maxWidth: 760, margin: '0 auto', padding: '28px 16px 64px' }}>
                <Link href="/" aria-label={`${site} home`}>
                    <Logo />
                </Link>
                <h1 style={{ fontSize: 30, letterSpacing: '-.02em', margin: '28px 0 6px' }}>{title}</h1>
                <p style={{ color: 'var(--muted)', marginBottom: 28 }}>
                    Last updated {updated} · <Link href={doc === 'terms' ? '/privacy' : '/terms'} className="link">{doc === 'terms' ? 'Privacy Policy' : 'Terms of Service'}</Link>
                </p>
                {sections.map((s, i) => (
                    <section key={s.h} style={{ marginBottom: 22 }}>
                        <h2 style={{ fontSize: 17, marginBottom: 8 }}>
                            {i + 1}. {s.h}
                        </h2>
                        {s.p.map((t) => (
                            <p key={t} style={{ color: 'var(--text-2)', marginBottom: 8, lineHeight: 1.65 }}>
                                {t}
                            </p>
                        ))}
                    </section>
                ))}
                <section style={{ marginTop: 32, padding: 16, borderRadius: 'var(--r)', background: 'var(--surface)', border: '1px solid var(--border)' }}>
                    <h2 style={{ fontSize: 16, marginBottom: 6 }}>Contact</h2>
                    <p style={{ color: 'var(--text-2)' }}>
                        Questions or a data request? {supportEmail ? <a className="link" href={`mailto:${supportEmail}`}>{supportEmail}</a> : 'Use the contact form on our home page'}
                        {supportPhone ? ` · WhatsApp ${supportPhone}` : ''}
                    </p>
                </section>
            </div>
        </div>
    );
}
