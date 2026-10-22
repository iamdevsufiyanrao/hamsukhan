import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { CSSProperties, MouseEvent, ReactNode, useEffect, useRef, useState } from 'react';
import Icon, { IconName, Logo } from '@/components/Icon';
import LiveDemo from '@/components/LiveDemo';
import { Sample, SampleCard, useSamplePlayer } from '@/components/SamplePlayer';
import { useTheme } from '@/layouts/AppLayout';
import type { SharedProps } from '@/types';

type Line = ['ai' | 'user', string];
type Demo = {
    id: string;
    label: string;
    icon: IconName;
    business: string;
    lines: Line[];
    captured: { kind: string; rows: [string, string][] };
};

const DEMOS: Demo[] = [
    {
        id: 'salon',
        label: 'Salon',
        icon: 'user',
        business: 'Bloom Salon',
        lines: [
            ['user', 'Kal evening mein haircut aur blow dry ka slot available hai?'],
            ['ai', 'Ji bilkul! Kal Hina ke saath 5 baje ya 7 baje ka slot khali hai. Kaunsa time theek rahega?'],
            ['user', '5 baje, please.'],
            ['ai', 'Zabardast. Apna naam aur WhatsApp number bata dein, main request team ko bhej deti hoon.'],
            ['user', 'Ayesha, 0300 123 4412.'],
            ['ai', 'Shukriya Ayesha! Team WhatsApp par confirm kar degi. Haircut + blow dry Rs 3,500 hai.'],
        ],
        captured: { kind: 'Booking request', rows: [['Service', 'Haircut + blow dry'], ['When', 'Tomorrow, 5:00 pm'], ['Name', 'Ayesha'], ['WhatsApp', '+92 300 1234412']] },
    },
    {
        id: 'restaurant',
        label: 'Restaurant',
        icon: 'book',
        business: 'Grill House',
        lines: [
            ['user', "I'd like to order two chicken burgers and a large fries."],
            ['ai', 'Great choice. Would you like a drink with that? Our mint lemonade is popular today.'],
            ['user', 'Sure, one lemonade. Delivery please.'],
            ['ai', "Got it. What's the delivery address?"],
            ['user', 'House 12, Street 4, near the park.'],
            ['ai', "Perfect. Your total is Rs 2,450 and the team will confirm on WhatsApp in a minute."],
        ],
        captured: { kind: 'Order', rows: [['Items', '2 chicken burgers, large fries, lemonade'], ['Type', 'Delivery'], ['Address', 'House 12, Street 4'], ['Total', 'Rs 2,450']] },
    },
    {
        id: 'repair',
        label: 'Repair shop',
        icon: 'wrench',
        business: 'Smart Geeks',
        lines: [
            ['user', 'My phone screen is cracked. How much to fix it?'],
            ['ai', 'Which phone is it? Most screen repairs take about an hour and start from Rs 12,000.'],
            ['user', "It's an iPhone 13. Can I come in on Saturday?"],
            ['ai', 'Yes, I have 11 am or 3 pm on Saturday. Which works better?'],
            ['user', '11 am.'],
            ['ai', "Booked! Bring the phone at 11 and it'll be ready by lunch."],
        ],
        captured: { kind: 'Booking', rows: [['Device', 'iPhone 13'], ['Issue', 'Cracked screen'], ['When', 'Saturday, 11:00 am'], ['Quote', 'From Rs 12,000']] },
    },
    {
        id: 'clinic',
        label: 'Clinic',
        icon: 'shield',
        business: 'Smile Dental',
        lines: [
            ['user', 'Assalam o Alaikum, clinic kis time band hoti hai? Check-up karwana hai.'],
            ['ai', 'Walaikum Assalam! Clinic raat 9 baje band hoti hai. Dr. Imran ke paas Thursday 10 baje ya Friday 4 baje ka time hai.'],
            ['user', 'Yes, first time. Thursday is good.'],
            ['ai', 'Wonderful. Please arrive ten minutes early to fill in a short form. May I have your name?'],
            ['user', 'Bilal Ahmed.'],
            ['ai', "Thank you Bilal, you're confirmed for Thursday at 10 am."],
        ],
        captured: { kind: 'Booking', rows: [['Visit', 'Check-up (new patient)'], ['Doctor', 'Dr. Imran'], ['When', 'Thursday, 10:00 am'], ['Name', 'Bilal Ahmed']] },
    },
];

const STEPS: { icon: IconName; title: string; text: string }[] = [
    { icon: 'sliders', title: 'Teach it your business', text: 'Pick a template, add your hours, services, prices and policies. The bot only quotes what you approve.' },
    { icon: 'mic', title: 'Pick a voice', text: 'Start with a professional voice. Optionally clone your own voice, with a recorded consent check.' },
    { icon: 'code', title: 'Add one line to your site', text: 'Paste a single script tag. A call button appears and visitors can talk to your receptionist right away.' },
];

const FEATURES: { icon: IconName; title: string; text: string }[] = [
    { icon: 'wave', title: 'Urdu and English, together', text: 'Visitors talk the way they normally do: Urdu, English or a mix. Pakistani names, numbers and Rupees are understood.' },
    { icon: 'calendar', title: 'Bookings and callbacks', text: 'The bot collects name, WhatsApp number, service and time, and hands you a clean booking or callback request.' },
    { icon: 'shield', title: 'Safe, approved answers', text: 'It says it is an AI, only quotes your approved prices and hours, and offers a callback from your team when unsure.' },
    { icon: 'plug', title: 'Sends to your software', text: 'Signed webhooks, field mapping and a ready PHP package push each booking into your own system.' },
    { icon: 'book', title: 'Knows your answers', text: 'Menus, price lists, FAQs and policies. Upload them once and the bot uses them on every call.' },
    { icon: 'wallet', title: 'Pay only for talk time', text: 'No monthly plan. Top up in Rupees by JazzCash, Easypaisa or bank. Failed connections are never billed.' },
];

const TOPICS: [string, string][] = [
    ['sales', 'Sales question'],
    ['support', 'Help with my account'],
    ['partnership', 'Partnership'],
    ['other', 'Something else'],
];

const BUSINESSES = ['Salons', 'Restaurants', 'Clinics', 'Repair shops', 'Gyms', 'Real estate', 'Car service', 'Tutors', 'Hotels', 'Bakeries'];
const LANGUAGES = ['English', 'Urdu', 'Urdu + English'];

function rs(n: number) {
    return 'Rs ' + Math.round(n).toLocaleString('en-PK');
}

const TRUST: { icon: IconName; title: string; text: string }[] = [
    { icon: 'user', title: 'Always says it is an AI', text: 'Visitors are told before the call starts that they are speaking with an AI assistant.' },
    { icon: 'mic', title: 'Asks before recording', text: 'Microphone and recording consent are asked first. Visitors can request a callback instead.' },
    { icon: 'phone', title: 'Human fallback', text: 'Unsure answers, complaints or a request for a person become a callback request for your team.' },
    { icon: 'trash', title: 'You control the data', text: 'Delete calls and voices any time. Public demo calls are removed within 7 days.' },
    { icon: 'ban', title: 'Not used for training', text: 'Your business and caller data is not used to train AI models without your written agreement.' },
    { icon: 'shield', title: 'Never asks for card details', text: 'The bot never takes card numbers, PINs, OTP codes or CNIC numbers in a conversation.' },
];

const reducedMotion = () => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Sets data-in on every [data-reveal] element the first time it scrolls into view. An attribute, not a class, so React re-renders (e.g. the FAQ toggling its class) don't wipe it. */
function useReveal() {
    useEffect(() => {
        const els = Array.from(document.querySelectorAll<HTMLElement>('[data-reveal]'));
        if (reducedMotion() || !('IntersectionObserver' in window)) {
            els.forEach((el) => el.setAttribute('data-in', ''));
            return;
        }
        const io = new IntersectionObserver(
            (entries) =>
                entries.forEach((e) => {
                    if (e.isIntersecting) {
                        e.target.setAttribute('data-in', '');
                        io.unobserve(e.target);
                    }
                }),
            { threshold: 0.12, rootMargin: '0px 0px -40px 0px' },
        );
        els.forEach((el) => io.observe(el));
        return () => io.disconnect();
    }, []);
}

/** Calls back once when the element first becomes visible. */
function useSeen<T extends HTMLElement>() {
    const ref = useRef<T>(null);
    const [seen, setSeen] = useState(false);
    useEffect(() => {
        if (!ref.current || !('IntersectionObserver' in window)) return setSeen(true);
        const io = new IntersectionObserver(([e]) => e.isIntersecting && (setSeen(true), io.disconnect()), { threshold: 0.4 });
        io.observe(ref.current);
        return () => io.disconnect();
    }, []);
    return { ref, seen };
}

function CountUp({ to, decimals = 0, prefix = '', suffix = '' }: { to: number; decimals?: number; prefix?: string; suffix?: string }) {
    const { ref, seen } = useSeen<HTMLSpanElement>();
    const [v, setV] = useState(0);
    useEffect(() => {
        if (!seen) return;
        if (reducedMotion()) return setV(to);
        let raf = 0;
        const start = performance.now();
        const tick = (now: number) => {
            const p = Math.min(1, (now - start) / 1400);
            setV(to * (1 - Math.pow(1 - p, 3)));
            if (p < 1) raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(raf);
    }, [seen, to]);
    return (
        <span ref={ref} className="num">
            {prefix}
            {v.toFixed(decimals)}
            {suffix}
        </span>
    );
}

/** Moves a soft glow under the cursor (used on cards). */
function spot(e: MouseEvent<HTMLElement>) {
    const r = e.currentTarget.getBoundingClientRect();
    e.currentTarget.style.setProperty('--mx', `${e.clientX - r.left}px`);
    e.currentTarget.style.setProperty('--my', `${e.clientY - r.top}px`);
}

function Reveal({ children, className = '', delay = 0, as: Tag = 'div', ...rest }: { children: ReactNode; className?: string; delay?: number; as?: 'div' | 'section' | 'li'; id?: string; onMouseMove?: (e: MouseEvent<HTMLElement>) => void }) {
    return (
        <Tag data-reveal className={'rv ' + className} style={{ '--d': `${delay}ms` } as CSSProperties} {...rest}>
            {children}
        </Tag>
    );
}

function Transcript({ demo }: { demo: Demo }) {
    const { ref, seen } = useSeen<HTMLDivElement>();
    const [shown, setShown] = useState(0);
    useEffect(() => {
        setShown(0);
        if (!seen) return;
        if (reducedMotion()) return setShown(demo.lines.length + 1);
        const timers = Array.from({ length: demo.lines.length + 1 }, (_, i) => window.setTimeout(() => setShown(i + 1), 400 + i * 1100));
        return () => timers.forEach(clearTimeout);
    }, [demo, seen]);

    const done = shown > demo.lines.length;
    return (
        <div className="lp-demo-body" ref={ref}>
            <div className="lp-chat">
                <div className="lp-chat-h">
                    <span className={'lp-live' + (done ? ' off' : '')} />
                    {done ? 'Call ended' : 'Live call'} · {demo.business}
                    <span className="mono">{done ? '01:12' : `00:${String(Math.min(shown, demo.lines.length) * 11).padStart(2, '0')}`}</span>
                </div>
                <div className="lp-msgs">
                    {demo.lines.slice(0, shown).map(([who, text], i) => (
                        <div key={demo.id + i} className={'lp-msg ' + (who === 'user' ? 'caller' : 'ai')}>
                            <small>{who === 'ai' ? 'Receptionist' : 'Caller'}</small>
                            {text}
                        </div>
                    ))}
                    {!done && shown < demo.lines.length && (
                        <div className={'lp-msg typing ' + (demo.lines[shown][0] === 'user' ? 'caller' : 'ai')}>
                            <i />
                            <i />
                            <i />
                        </div>
                    )}
                </div>
            </div>
            <div className={'lp-captured' + (done ? ' on' : '')}>
                <div className="lp-captured-h">
                    <Icon name="check" className="i i-sm" />
                    {demo.captured.kind} captured
                </div>
                {demo.captured.rows.map(([k, v]) => (
                    <div key={k} className="lp-kv">
                        <span>{k}</span>
                        <b>{v}</b>
                    </div>
                ))}
                <div className="lp-sent">
                    <Icon name="send" className="i i-sm" />
                    Sent to your booking system
                </div>
            </div>
        </div>
    );
}

function Contact({ supportEmail, supportPhone }: { supportEmail: string | null; supportPhone: string | null }) {
    const form = useForm({ name: '', email: '', business: '', topic: 'sales', message: '', company_site: '' });
    const [sent, setSent] = useState<string | null>(null);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/contact', {
            preserveScroll: true,
            onSuccess: (page) => {
                setSent((page.props as unknown as SharedProps).flash.success ?? 'Thanks! We got your message.');
                form.reset();
            },
        });
    };

    return (
        <section id="contact" className="lp-section">
            <div className="lp-wrap lp-contact">
                <Reveal className="lp-contact-info">
                    <span className="lp-kicker">Contact us</span>
                    <h2>
                        Let's talk about <em className="lp-serif">your</em> calls.
                    </h2>
                    <p>Questions about setup, pricing or a custom integration? Send us a note and a real person will reply by email, usually within one business day.</p>
                    <div className="lp-contact-cards">
                        {supportEmail && (
                            <a className="lp-contact-card" href={`mailto:${supportEmail}`} onMouseMove={spot}>
                                <span className="lp-feature-ic">
                                    <Icon name="mail" />
                                </span>
                                <span>
                                    <small>Email</small>
                                    <b>{supportEmail}</b>
                                </span>
                            </a>
                        )}
                        {supportPhone && (
                            <a className="lp-contact-card" href={`tel:${supportPhone.replace(/\s+/g, '')}`} onMouseMove={spot}>
                                <span className="lp-feature-ic">
                                    <Icon name="phone" />
                                </span>
                                <span>
                                    <small>Phone</small>
                                    <b>{supportPhone}</b>
                                </span>
                            </a>
                        )}
                        <div className="lp-contact-card static">
                            <span className="lp-feature-ic">
                                <Icon name="clock" />
                            </span>
                            <span>
                                <small>Response time</small>
                                <b>Within one business day</b>
                            </span>
                        </div>
                    </div>
                </Reveal>

                <Reveal className="lp-form-card" delay={120}>
                    {sent ? (
                        <div className="lp-sent-state">
                            <span className="lp-sent-ic">
                                <Icon name="check" />
                            </span>
                            <h3>Message sent</h3>
                            <p>{sent}</p>
                            <button className="btn btn-ghost" onClick={() => setSent(null)}>
                                Send another message
                            </button>
                        </div>
                    ) : (
                        <form onSubmit={submit} noValidate>
                            <div className="lp-topics" role="radiogroup" aria-label="Topic">
                                {TOPICS.map(([v, l]) => (
                                    <button type="button" key={v} role="radio" aria-checked={form.data.topic === v} className={form.data.topic === v ? 'on' : ''} onClick={() => form.setData('topic', v)}>
                                        {l}
                                    </button>
                                ))}
                            </div>
                            <div className="lp-form-grid">
                                <div className="field">
                                    <label htmlFor="c-name">Your name</label>
                                    <input id="c-name" className={'input' + (form.errors.name ? ' bad' : '')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoComplete="name" placeholder="Full name" />
                                    {form.errors.name && <span className="err">{form.errors.name}</span>}
                                </div>
                                <div className="field">
                                    <label htmlFor="c-email">Email</label>
                                    <input id="c-email" type="email" className={'input' + (form.errors.email ? ' bad' : '')} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoComplete="email" placeholder="you@business.com" />
                                    {form.errors.email && <span className="err">{form.errors.email}</span>}
                                </div>
                            </div>
                            <div className="field">
                                <label htmlFor="c-business">
                                    Business name <span className="hint">(optional)</span>
                                </label>
                                <input id="c-business" className="input" value={form.data.business} onChange={(e) => form.setData('business', e.target.value)} autoComplete="organization" placeholder="e.g. Bloom Salon" />
                            </div>
                            <div className="field">
                                <label htmlFor="c-message">Message</label>
                                <textarea id="c-message" className={'textarea' + (form.errors.message ? ' bad' : '')} value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} placeholder="Tell us a little about your business and what you need." rows={5} />
                                {form.errors.message && <span className="err">{form.errors.message}</span>}
                            </div>
                            <input className="lp-hp" tabIndex={-1} autoComplete="off" aria-hidden="true" value={form.data.company_site} onChange={(e) => form.setData('company_site', e.target.value)} />
                            {form.errors.topic && <span className="err">{form.errors.topic}</span>}
                            <button className="btn btn-primary btn-lg lp-full lp-shine" disabled={form.processing}>
                                {form.processing ? 'Sending…' : 'Send message'}
                                {!form.processing && <Icon name="send" className="i i-sm" />}
                            </button>
                        </form>
                    )}
                </Reveal>
            </div>
        </section>
    );
}

function Voices({ samples }: { samples: Sample[] }) {
    const player = useSamplePlayer();
    return (
        <section id="voices" className="lp-section lp-voices">
            <div className="lp-wrap">
                <Reveal className="lp-head">
                    <span className="lp-kicker">Sample voices</span>
                    <h2>
                        Hear it for <em className="lp-serif">yourself.</em>
                    </h2>
                    <p>Press play on any voice below. This is what your callers hear when the receptionist picks up.</p>
                </Reveal>
                <div className={'lp-samples' + (samples.length === 1 ? ' one' : '')}>
                    {samples.map((s, i) => (
                        <Reveal key={s.id} delay={(i % 3) * 90}>
                            <SampleCard sample={s} player={player} />
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}

export default function Landing({ samples = [] }: { samples?: Sample[] }) {
    const { auth, site, config } = usePage<SharedProps>().props;
    const { dark, toggle } = useTheme();
    const [demo, setDemo] = useState(DEMOS[0]);
    const [minutes, setMinutes] = useState(300);
    const [faq, setFaq] = useState<number | null>(0);
    const [menu, setMenu] = useState(false);
    const [scrolled, setScrolled] = useState(false);
    const progress = useRef<HTMLDivElement>(null);
    const art = useRef<HTMLDivElement>(null);
    const name = site.name || 'Hamsukhan';
    const rate = config.ratePerMinutePkr;
    const startHref = auth ? '/dashboard' : site.allowSignups ? '/register' : '/login';
    const startLabel = auth ? 'Open dashboard' : site.allowSignups ? 'Start free pilot' : 'Log in';
    const wa = site.whatsapp ? `https://wa.me/${site.whatsapp.replace(/\D/g, '').replace(/^0/, '92')}` : null;
    useReveal();

    useEffect(() => {
        const onScroll = () => {
            setScrolled(window.scrollY > 8);
            const max = document.documentElement.scrollHeight - window.innerHeight;
            if (progress.current) progress.current.style.transform = `scaleX(${max > 0 ? window.scrollY / max : 0})`;
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    const tilt = (e: MouseEvent<HTMLDivElement>) => {
        if (reducedMotion() || !art.current) return;
        const r = e.currentTarget.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width - 0.5;
        const y = (e.clientY - r.top) / r.height - 0.5;
        art.current.style.transform = `rotateY(${x * 8}deg) rotateX(${-y * 8}deg)`;
    };
    const untilt = () => art.current && (art.current.style.transform = '');

    const faqs: [string, string][] = [
        ['Does it answer my phone line?', 'Not yet. Today it is a voice conversation on your website: visitors tap a button and talk from their browser, no app needed. Pakistani phone-number and call-forwarding support is planned for later.'],
        ['Which languages can it speak?', 'Urdu, English and a natural mix of both (code-switching), including Roman Urdu style phrasing. Other languages are not offered until they pass the same quality checks.'],
        ['What if the AI does not know an answer?', 'It only quotes the prices, hours and policies you approved. When it is unsure it says so and takes a callback request with the caller\u2019s name and WhatsApp number, so your team can follow up.'],
        ['Do I have to clone my voice?', 'No. Every bot starts with a professional voice. Voice cloning is optional and only for your own voice, or one you have clear permission to use; we record that consent.'],
        ['Can bookings go into my own software?', 'Yes. Every booking or order can be delivered to your system with a signed webhook, mapped to your own form fields. If your server is down we retry automatically. There is also a ready package for Laravel and plain PHP.'],
        ['How does billing work?', `There is no monthly plan. You top up your wallet in Rupees and calls are charged at about ${rs(rate)} per minute of talk time, billed by the second. Billable time starts when the call connects and ends when it ends. Failed connections and the website demo are never charged. Top-ups start from about ${rs(config.minTopUpPkr)}. Payment fees and taxes, if any, are shown on your top-up receipt.`],
        ['What if my balance runs low?', 'You get a low balance warning in your dashboard well before it runs out. A call only starts when the balance covers it, and its maximum length is capped to what you can afford, so you never end up owing money.'],
    ];

    const go = (id: string) => {
        setMenu(false);
        document.getElementById(id)?.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
    };

    const headline = ['Turn', 'website', 'visitors', 'into'];
    const NAV: [string, string][] = [
        ['how', 'How it works'],
        ['demo', 'Demo'],
        ...(samples.length ? ([['voices', 'Voices']] as [string, string][]) : []),
        ['features', 'Features'],
        ['pricing', 'Pricing'],
        ['faq', 'FAQ'],
    ];

    return (
        <div className="lp">
            <Head title="AI voice receptionist for your website">
                <link rel="stylesheet" href="https://fonts.bunny.net/css?family=bricolage-grotesque:500,600,700,800|instrument-serif:400i&display=swap" />
            </Head>
            <div className="lp-progress" ref={progress} aria-hidden="true" />

            <header className={'lp-nav' + (scrolled ? ' scrolled' : '')}>
                <div className="lp-wrap lp-nav-in">
                    <Link href="/" aria-label={`${name} home`}>
                        <Logo />
                    </Link>
                    <nav className={'lp-links' + (menu ? ' open' : '')}>
                        {NAV.map(([id, label]) => (
                            <button key={id} onClick={() => go(id)}>
                                {label}
                            </button>
                        ))}
                        {wa ? (
                            <a href={wa} target="_blank" rel="noopener">
                                WhatsApp Support
                            </a>
                        ) : (
                            <button onClick={() => go('contact')}>Contact</button>
                        )}
                        <div className="lp-menu-extra">
                            <button onClick={toggle}>
                                <Icon name={dark ? 'sun' : 'moon'} className="i i-sm" />
                                {dark ? 'Light mode' : 'Dark mode'}
                            </button>
                            {!auth && (
                                <Link href="/login" className="btn btn-ghost">
                                    Log in
                                </Link>
                            )}
                        </div>
                    </nav>
                    <div className="lp-nav-acts">
                        <button className="icon-btn lp-theme" onClick={toggle} aria-label="Toggle dark mode">
                            <Icon name={dark ? 'sun' : 'moon'} />
                        </button>
                        {!auth && (
                            <Link href="/login" className="lp-login lp-hide-sm">
                                Log in
                            </Link>
                        )}
                        <Link href={startHref} className="btn btn-ghost lp-hide-sm">
                            {startLabel}
                        </Link>
                        <button className="btn btn-primary lp-shine" onClick={() => go('demo')}>
                            Try Live Demo
                        </button>
                        <button className="icon-btn lp-burger" onClick={() => setMenu(!menu)} aria-label="Menu" aria-expanded={menu}>
                            <Icon name={menu ? 'x' : 'menu'} />
                        </button>
                    </div>
                </div>
            </header>

            {site.announcement && <div className="lp-announce">{site.announcement}</div>}

            {/* ---------- hero ---------- */}
            <section className="lp-hero">
                <div className="lp-grid-bg" aria-hidden="true" />
                <div className="lp-aurora" aria-hidden="true" />
                <div className="lp-wrap lp-hero-in">
                    <div className="lp-hero-copy">
                        <span className="lp-eyebrow lp-enter" style={{ '--d': '0ms' } as CSSProperties}>
                            <span className="lp-live" />
                            AI voice receptionist · Urdu + English
                        </span>
                        <h1>
                            {headline.map((w, i) => (
                                <span key={w} className="lp-word" style={{ '--d': `${80 + i * 70}ms` } as CSSProperties}>
                                    {w}{' '}
                                </span>
                            ))}
                            <span className="lp-word" style={{ '--d': '300ms' } as CSSProperties}>
                                <em className="lp-serif lp-grad">bookings</em>{' '}
                            </span>
                            {['with', 'an', 'AI', 'voice', 'receptionist.'].map((w, i) => (
                                <span key={w} className="lp-word lp-soft" style={{ '--d': `${380 + i * 60}ms` } as CSSProperties}>
                                    {w}{' '}
                                </span>
                            ))}
                        </h1>
                        <p className="lp-lead lp-enter" style={{ '--d': '520ms' } as CSSProperties}>
                            Let customers speak from your website in Urdu or English. {name} answers approved questions, qualifies the visitor and sends you a clean booking or callback request.
                        </p>
                        <div className="lp-cta lp-enter" style={{ '--d': '620ms' } as CSSProperties}>
                            <button className="btn btn-primary btn-lg lp-shine" onClick={() => go('demo')}>
                                <span className="lp-play">
                                    <Icon name="mic" className="i i-sm" />
                                </span>
                                Try a Live Urdu Demo
                            </button>
                            <Link href={startHref} className="btn btn-ghost btn-lg">
                                {auth ? 'Open dashboard' : 'Start a 30-Day Pilot'}
                                <Icon name="chev" className="i i-sm lp-arrow" />
                            </Link>
                        </div>
                        <ul className="lp-ticks lp-enter" style={{ '--d': '720ms' } as CSSProperties}>
                            {['Website voice conversation', 'No app required', 'Phone-line integration coming later'].map((t) => (
                                <li key={t}>
                                    <Icon name="check" className="i i-sm" />
                                    {t}
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div className="lp-hero-stage lp-enter" style={{ '--d': '300ms' } as CSSProperties} onMouseMove={tilt} onMouseLeave={untilt} aria-hidden="true">
                        <div className="lp-hero-art" ref={art}>
                            <div className="lp-browser">
                                <div className="lp-browser-bar">
                                    <i />
                                    <i />
                                    <i />
                                    <span>
                                        <Icon name="shield" className="i" />
                                        yourbusiness.com
                                    </span>
                                </div>
                                <div className="lp-browser-body">
                                    <div className="lp-site-nav">
                                        <span className="lp-sk dot" />
                                        <span className="lp-sk w20" />
                                        <span className="lp-sk w10 push" />
                                        <span className="lp-sk w10" />
                                        <span className="lp-sk w10" />
                                    </div>
                                    <div className="lp-site-hero">
                                        <div className="lp-sk w60 tall" />
                                        <div className="lp-sk w40 tall" />
                                        <div className="lp-sk w70" />
                                        <div className="lp-sk btn" />
                                    </div>
                                    <div className="lp-sk-row">
                                        <div className="lp-sk card" />
                                        <div className="lp-sk card" />
                                        <div className="lp-sk card" />
                                    </div>
                                    <div className="lp-widget">
                                        <span className="ic">
                                            <Icon name="mic" className="i i-sm" />
                                            <span className="ring" />
                                        </span>
                                        <span>
                                            <b>Talk to us</b>
                                            <small>We answer instantly</small>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div className="lp-callcard">
                                <div className="lp-callcard-h">
                                    <span className="lp-live" />
                                    Talking in <b>Urdu + English</b>
                                    <span className="mono">00:42</span>
                                </div>
                                <div className="lp-eq">
                                    {Array.from({ length: 30 }, (_, i) => (
                                        <span key={i} style={{ height: `${28 + ((i * 41) % 72)}%`, animationDelay: `${(i % 8) * 0.11}s` }} />
                                    ))}
                                </div>
                                <p>"Ji, Saturday ko 11 baje ya 3 baje ka slot khali hai. Kaunsa theek rahega?"</p>
                            </div>

                            <div className="lp-toast">
                                <span className="ic">
                                    <Icon name="calendar" className="i i-sm" />
                                </span>
                                <span>
                                    <b>New booking request</b>
                                    <small>Haircut · Sat 11:00 am · WhatsApp sent</small>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="lp-wrap">
                    <Reveal className="lp-marquee">
                        <span className="lp-marquee-label">Built for</span>
                        <div className="lp-marquee-track">
                            <div className="lp-marquee-row">
                                {[...BUSINESSES, ...BUSINESSES].map((b, i) => (
                                    <span key={i} aria-hidden={i >= BUSINESSES.length}>
                                        <i />
                                        {b}
                                    </span>
                                ))}
                            </div>
                        </div>
                    </Reveal>
                </div>
            </section>

            {/* ---------- stats ---------- */}
            <section className="lp-wrap lp-stats">
                <Reveal delay={0}>
                    <strong>
                        <CountUp to={24} />
                        /7
                    </strong>
                    <span>Answers website visitors, even at night and on holidays</span>
                </Reveal>
                <Reveal delay={80}>
                    <strong>
                        <CountUp to={90} suffix=" sec" />
                    </strong>
                    <span>Free live demo, no sign-up needed</span>
                </Reveal>
                <Reveal delay={160}>
                    <strong>
                        <CountUp to={rate} prefix="Rs " />
                    </strong>
                    <span>About per minute of talk time, billed by the second</span>
                </Reveal>
                <Reveal delay={240}>
                    <strong>
                        <CountUp to={LANGUAGES.length} />
                    </strong>
                    <span>Ways to talk: Urdu, English, or both mixed</span>
                </Reveal>
            </section>

            {/* ---------- how ---------- */}
            <section id="how" className="lp-section">
                <div className="lp-wrap">
                    <Reveal className="lp-head">
                        <span className="lp-kicker">How it works</span>
                        <h2>
                            Set up in three steps. <em className="lp-serif">No developer needed.</em>
                        </h2>
                        <p>From your first recording to a live receptionist on your website takes about ten minutes.</p>
                    </Reveal>
                    <div className="lp-steps">
                        {STEPS.map((s, i) => (
                            <Reveal key={s.title} className="lp-step lp-glow" delay={i * 110} onMouseMove={spot}>
                                <div className="lp-step-top">
                                    <span className="lp-step-ic">
                                        <Icon name={s.icon} />
                                    </span>
                                    <span className="lp-step-n">0{i + 1}</span>
                                </div>
                                <h3>{s.title}</h3>
                                <p>{s.text}</p>
                            </Reveal>
                        ))}
                    </div>
                </div>
            </section>

            {/* ---------- demo ---------- */}
            <section id="demo" className="lp-section lp-dark">
                <div className="lp-grid-bg dark" aria-hidden="true" />
                <div className="lp-wrap">
                    <Reveal className="lp-head">
                        <span className="lp-kicker">Live demo</span>
                        <h2>
                            Talk to it now, <em className="lp-serif">in Urdu or English.</em>
                        </h2>
                        <p>A real AI voice call in your browser. Free, up to 90 seconds, no sign-up.</p>
                    </Reveal>
                    <Reveal delay={60}>
                        <LiveDemo demoBotKey={site.demoBotKey} />
                    </Reveal>
                    <Reveal className="lp-head" delay={80}>
                        <h3 style={{ marginTop: 48 }}>Example conversations</h3>
                        <p>How the receptionist handles a call from hello to a clean booking.</p>
                    </Reveal>
                    <Reveal delay={100}>
                        <div className="lp-tabs" role="tablist">
                            {DEMOS.map((d) => (
                                <button key={d.id} role="tab" aria-selected={demo.id === d.id} className={demo.id === d.id ? 'on' : ''} onClick={() => setDemo(d)}>
                                    <Icon name={d.icon} className="i i-sm" />
                                    {d.label}
                                </button>
                            ))}
                        </div>
                        <Transcript demo={demo} />
                    </Reveal>
                </div>
            </section>

            {/* ---------- sample voices (only when the admin uploaded some) ---------- */}
            {samples.length > 0 && <Voices samples={samples} />}

            {/* ---------- features ---------- */}
            <section id="features" className="lp-section">
                <div className="lp-wrap">
                    <Reveal className="lp-head">
                        <span className="lp-kicker">Features</span>
                        <h2>
                            Everything a great receptionist does. <em className="lp-serif">Without the salary.</em>
                        </h2>
                    </Reveal>
                    <div className="lp-features">
                        {FEATURES.map((f, i) => (
                            <Reveal key={f.title} className="lp-feature lp-glow" delay={(i % 3) * 90} onMouseMove={spot}>
                                <span className="lp-feature-ic">
                                    <Icon name={f.icon} />
                                </span>
                                <h3>{f.title}</h3>
                                <p>{f.text}</p>
                            </Reveal>
                        ))}
                    </div>
                </div>
            </section>

            {/* ---------- install ---------- */}
            <section className="lp-section lp-tight">
                <div className="lp-wrap lp-install">
                    <Reveal>
                        <span className="lp-kicker">One line of code</span>
                        <h2>
                            Works on <em className="lp-serif">any</em> website.
                        </h2>
                        <p>WordPress, Shopify, Wix, Laravel or plain HTML. Copy the snippet from your dashboard, paste it before the closing body tag and you are live. Change colours, labels and icons any time without touching the code again.</p>
                        <ul className="lp-list">
                            {['Matches your brand colours', 'Works on mobile and desktop', 'We tell you the moment it is installed'].map((t) => (
                                <li key={t}>
                                    <Icon name="check" className="i i-sm" />
                                    {t}
                                </li>
                            ))}
                        </ul>
                    </Reveal>
                    <Reveal className="lp-code" delay={120}>
                        <div className="lp-code-h">
                            <span className="lp-code-dots">
                                <i />
                                <i />
                                <i />
                            </span>
                            <span>index.html</span>
                            <Icon name="copy" className="i i-sm" />
                        </div>
                        <pre className="mono">
                            <span className="c">{'<!-- your website -->'}</span>
                            {'\n'}
                            <span className="t">{'<script'}</span> <span className="a">src</span>=<span className="s">"https://{typeof window !== 'undefined' ? window.location.host : 'example.com'}/widget.js"</span>
                            {'\n        '}
                            <span className="a">data-bot</span>=<span className="s">"YOUR_BOT_KEY"</span> <span className="a">defer</span>
                            <span className="t">{'></script>'}</span>
                            <span className="lp-caret" />
                        </pre>
                    </Reveal>
                </div>
            </section>

            {/* ---------- trust ---------- */}
            <section id="trust" className="lp-section lp-alt">
                <div className="lp-wrap">
                    <Reveal className="lp-head">
                        <span className="lp-kicker">Trust and safety</span>
                        <h2>
                            Honest with callers. <em className="lp-serif">Careful with data.</em>
                        </h2>
                        <p>Works in current Chrome, Edge and Safari on phone and laptop. If the microphone is blocked, visitors can request a callback instead.</p>
                    </Reveal>
                    <div className="lp-features">
                        {TRUST.map((f, i) => (
                            <Reveal key={f.title} className="lp-feature" delay={(i % 3) * 90}>
                                <span className="lp-feature-ic">
                                    <Icon name={f.icon} />
                                </span>
                                <h3>{f.title}</h3>
                                <p>{f.text}</p>
                            </Reveal>
                        ))}
                    </div>
                </div>
            </section>

            {/* ---------- pricing ---------- */}
            <section id="pricing" className="lp-section">
                <div className="lp-wrap">
                    <Reveal className="lp-head">
                        <span className="lp-kicker">Pricing</span>
                        <h2>
                            Simple. <em className="lp-serif">Pay as you talk.</em>
                        </h2>
                        <p>No plans, no contracts, no setup fee. Top up in Rupees and pay only for the minutes your receptionist spends talking.</p>
                    </Reveal>
                    <div className="lp-pricing">
                        <Reveal className="lp-price-card">
                            <div className="lp-price-top">
                                <span className="lp-chip">Pay per minute</span>
                                <div className="lp-price">
                                    <strong>{rs(rate)}</strong>
                                    <span>/ minute</span>
                                </div>
                                <p>Top up from {rs(config.minTopUpPkr)} by JazzCash, Easypaisa or bank transfer.</p>
                            </div>
                            <ul className="lp-list">
                                {['Billed by the second from the moment the call connects', 'Never billed: failed connections, silence before connecting, the website demo', 'A call only starts if your balance covers it; its length is capped to your balance', 'Low-balance warning in your dashboard', 'Professional voice included, own voice clone optional', 'Recordings, transcripts, bookings and callbacks'].map((x) => (
                                    <li key={x}>
                                        <Icon name="check" className="i i-sm" />
                                        {x}
                                    </li>
                                ))}
                            </ul>
                            <Link href={startHref} className="btn btn-primary btn-lg lp-full lp-shine">
                                {auth ? 'Open dashboard' : 'Start a 30-day pilot'}
                            </Link>
                        </Reveal>

                        <Reveal className="lp-calc" delay={120}>
                            <h3>Estimate your monthly cost</h3>
                            <p>Drag to the number of conversation minutes you expect in a month. Estimates only, payment fees and taxes are not included.</p>
                            <div className="lp-calc-val">
                                <span className="num">{minutes.toLocaleString()}</span> minutes
                            </div>
                            <input type="range" min={50} max={3000} step={50} value={minutes} onChange={(e) => setMinutes(Number(e.target.value))} aria-label="Minutes per month" style={{ '--p': `${((minutes - 50) / 2950) * 100}%` } as CSSProperties} />
                            <div className="lp-calc-grid">
                                <div>
                                    <small>About</small>
                                    <strong className="num">{Math.round(minutes / 2).toLocaleString()}</strong>
                                    <span>calls of 2 minutes</span>
                                </div>
                                <div>
                                    <small>You pay</small>
                                    <strong className="num">{rs(minutes * rate)}</strong>
                                    <span>per month</span>
                                </div>
                                <div>
                                    <small>Per booking</small>
                                    <strong className="num">{rs((minutes * rate) / Math.max(1, Math.round(minutes / 2 / 4)))}</strong>
                                    <span>if 1 in 4 calls books</span>
                                </div>
                            </div>
                        </Reveal>
                    </div>
                </div>
            </section>

            {/* ---------- faq ---------- */}
            <section id="faq" className="lp-section lp-alt">
                <div className="lp-wrap lp-faq-wrap">
                    <Reveal className="lp-head left">
                        <span className="lp-kicker">FAQ</span>
                        <h2>
                            Questions, <em className="lp-serif">answered.</em>
                        </h2>
                        <p>
                            Anything else?{' '}
                            <button className="lp-inline-link" onClick={() => go('contact')}>
                                Send us a message
                            </button>{' '}
                            and we will get back to you.
                        </p>
                    </Reveal>
                    <div className="lp-faq">
                        {faqs.map(([q, a], i) => (
                            <Reveal key={q} delay={i * 60} className={'lp-faq-item' + (faq === i ? ' open' : '')}>
                                <button onClick={() => setFaq(faq === i ? null : i)} aria-expanded={faq === i}>
                                    {q}
                                    <span className="lp-faq-ic">
                                        <Icon name="plus" className="i i-sm" />
                                    </span>
                                </button>
                                <div className="lp-faq-a">
                                    <p>{a}</p>
                                </div>
                            </Reveal>
                        ))}
                    </div>
                </div>
            </section>

            {/* ---------- contact ---------- */}
            <Contact supportEmail={site.supportEmail} supportPhone={site.supportPhone} />

            {/* ---------- final cta ---------- */}
            <section className="lp-wrap">
                <Reveal className="lp-final">
                    <div className="lp-grid-bg dark" aria-hidden="true" />
                    <div className="lp-eq big" aria-hidden="true">
                        {Array.from({ length: 40 }, (_, i) => (
                            <span key={i} style={{ height: `${20 + ((i * 53) % 80)}%`, animationDelay: `${(i % 9) * 0.1}s` }} />
                        ))}
                    </div>
                    <h2>
                        Turn visitors into <em className="lp-serif">bookings.</em>
                    </h2>
                    <p>Give your website a receptionist that talks in Urdu and English and never takes a day off.</p>
                    <div className="lp-cta center">
                        <Link href={startHref} className="btn btn-primary btn-lg lp-shine">
                            {auth ? 'Open dashboard' : 'Start a 30-Day Pilot'}
                            <Icon name="chev" className="i i-sm lp-arrow" />
                        </Link>
                        {!auth && (
                            <Link href="/login" className="btn btn-lg lp-btn-light">
                                I already have an account
                            </Link>
                        )}
                    </div>
                </Reveal>
            </section>

            <button className="lp-sticky-cta" onClick={() => go('demo')}>
                <Icon name="mic" className="i i-sm" />
                Try Demo
            </button>

            <footer className="lp-foot">
                <div className="lp-wrap lp-foot-in">
                    <div className="lp-foot-brand">
                        <Logo />
                        <p>An AI voice receptionist that turns website visitors into bookings, in Urdu and English.</p>
                    </div>
                    <div className="lp-foot-col">
                        <b>Product</b>
                        <button onClick={() => go('how')}>How it works</button>
                        <button onClick={() => go('features')}>Features</button>
                        <button onClick={() => go('pricing')}>Pricing</button>
                    </div>
                    <div className="lp-foot-col">
                        <b>Company</b>
                        <button onClick={() => go('faq')}>FAQ</button>
                        <button onClick={() => go('contact')}>Contact us</button>
                        {wa && (
                            <a href={wa} target="_blank" rel="noopener">
                                WhatsApp support
                            </a>
                        )}
                        <a href={site.privacyUrl ?? '/privacy'}>Privacy</a>
                        <a href={site.termsUrl ?? '/terms'}>Terms</a>
                    </div>
                    <div className="lp-foot-col">
                        <b>Account</b>
                        {auth ? <Link href="/dashboard">Dashboard</Link> : <Link href="/login">Log in</Link>}
                        {!auth && site.allowSignups && <Link href="/register">Create account</Link>}
                    </div>
                </div>
                <div className="lp-wrap lp-foot-bottom">
                    <span>
                        © {new Date().getFullYear()} {name}. All rights reserved.
                    </span>
                    <button onClick={() => window.scrollTo({ top: 0, behavior: reducedMotion() ? 'auto' : 'smooth' })}>
                        Back to top
                        <Icon name="chev" className="i i-sm lp-up" />
                    </button>
                </div>
                <div className="lp-wordmark" aria-hidden="true">
                    {name}
                </div>
            </footer>
        </div>
    );
}
