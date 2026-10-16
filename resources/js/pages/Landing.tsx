import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { CSSProperties, MouseEvent, ReactNode, useEffect, useRef, useState } from 'react';
import Icon, { IconName, Logo } from '@/components/Icon';
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
            ['user', 'Hi, can I get a haircut and blow dry tomorrow?'],
            ['ai', 'Of course! Tomorrow I have 2:30 pm or 5 pm with Hina. Which one suits you?'],
            ['user', '5 pm, please.'],
            ['ai', "Lovely, you're booked for 5 pm. Can I take your name and number?"],
            ['user', "It's Ayesha, the number ends in 4412."],
            ['ai', "Thanks Ayesha! You'll get a reminder before your visit. See you tomorrow."],
        ],
        captured: { kind: 'Booking', rows: [['Service', 'Haircut + blow dry'], ['When', 'Tomorrow, 5:00 pm'], ['Name', 'Ayesha'], ['Stylist', 'Hina']] },
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
            ['ai', "Perfect. Your total is $18.50 and it'll be with you in about 35 minutes."],
        ],
        captured: { kind: 'Order', rows: [['Items', '2 chicken burgers, large fries, lemonade'], ['Type', 'Delivery'], ['Address', 'House 12, Street 4'], ['Total', '$18.50']] },
    },
    {
        id: 'repair',
        label: 'Repair shop',
        icon: 'wrench',
        business: 'Smart Geeks',
        lines: [
            ['user', 'My phone screen is cracked. How much to fix it?'],
            ['ai', 'Which phone is it? Most screen repairs take about an hour and start from $45.'],
            ['user', "It's an iPhone 13. Can I come in on Saturday?"],
            ['ai', 'Yes, I have 11 am or 3 pm on Saturday. Which works better?'],
            ['user', '11 am.'],
            ['ai', "Booked! Bring the phone at 11 and it'll be ready by lunch."],
        ],
        captured: { kind: 'Booking', rows: [['Device', 'iPhone 13'], ['Issue', 'Cracked screen'], ['When', 'Saturday, 11:00 am'], ['Quote', 'From $45']] },
    },
    {
        id: 'clinic',
        label: 'Clinic',
        icon: 'shield',
        business: 'Smile Dental',
        lines: [
            ['user', 'Hello, I need a dental check-up. Is the doctor free this week?'],
            ['ai', 'Dr. Imran has Thursday at 10 am or Friday at 4 pm. Is this your first visit?'],
            ['user', 'Yes, first time. Thursday is good.'],
            ['ai', 'Wonderful. Please arrive ten minutes early to fill in a short form. May I have your name?'],
            ['user', 'Bilal Ahmed.'],
            ['ai', "Thank you Bilal, you're confirmed for Thursday at 10 am."],
        ],
        captured: { kind: 'Booking', rows: [['Visit', 'Check-up (new patient)'], ['Doctor', 'Dr. Imran'], ['When', 'Thursday, 10:00 am'], ['Name', 'Bilal Ahmed']] },
    },
];

const STEPS: { icon: IconName; title: string; text: string }[] = [
    { icon: 'mic', title: 'Record your voice', text: 'Read anything for 30 seconds in a quiet room. We turn it into a natural voice clone that sounds like you.' },
    { icon: 'sliders', title: 'Teach it your business', text: 'Pick a template, add your hours, prices and menus. Upload files and the bot answers from them.' },
    { icon: 'code', title: 'Add one line to your site', text: 'Paste a single script tag. A call button appears and visitors can talk to your receptionist right away.' },
];

const FEATURES: { icon: IconName; title: string; text: string }[] = [
    { icon: 'wave', title: 'Your own cloned voice', text: 'Callers hear you, not a robot. Keep up to five voices and switch between them in one click.' },
    { icon: 'calendar', title: 'Bookings and orders', text: 'The bot asks for exactly the details you need and writes them down as a clean booking or order.' },
    { icon: 'play', title: 'Every call recorded', text: 'Listen back, read the full transcript and see a one-line summary of what each caller wanted.' },
    { icon: 'plug', title: 'Sends to your software', text: 'Signed webhooks, field mapping and a ready PHP package push each booking into your own system.' },
    { icon: 'book', title: 'Knows your answers', text: 'Menus, price lists, FAQs and policies. Upload them once and the bot uses them on every call.' },
    { icon: 'wallet', title: 'Pay only for talk time', text: 'No monthly plan. Top up your wallet and pay per minute. You always see what each call cost.' },
];

const TOPICS: [string, string][] = [
    ['sales', 'Sales question'],
    ['support', 'Help with my account'],
    ['partnership', 'Partnership'],
    ['other', 'Something else'],
];

const BUSINESSES = ['Salons', 'Restaurants', 'Clinics', 'Repair shops', 'Gyms', 'Real estate', 'Car service', 'Tutors', 'Hotels', 'Bakeries'];
const LANGUAGES = ['English', 'Urdu', 'Arabic', 'Hindi', 'Spanish'];

function money(n: number) {
    return '$' + n.toFixed(n < 1 ? 2 : n % 1 === 0 ? 0 : 2);
}

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
    const rate = config.ratePerMinute;
    const startHref = auth ? '/dashboard' : site.allowSignups ? '/register' : '/login';
    const startLabel = auth ? 'Open dashboard' : site.allowSignups ? 'Get started' : 'Log in';
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
        ['Do I need a phone number?', 'No. Calls come from a button on your own website. Visitors tap it and talk to your receptionist straight from their browser, on phone or laptop.'],
        ['How much of my voice do you need?', 'About 30 seconds of clear speech. Read naturally in a quiet room and talk about your business, your hours, anything at all. You confirm that the voice is yours before cloning.'],
        ['Which languages can it speak?', `Your receptionist can talk in ${LANGUAGES.slice(0, -1).join(', ')} and ${LANGUAGES[LANGUAGES.length - 1]}. You choose the language for your bot in the studio.`],
        ['Can bookings go into my own software?', 'Yes. Every booking or order can be delivered to your system with a signed webhook, mapped to your own form fields. If your server is down we retry automatically. There is also a ready package for Laravel and plain PHP.'],
        ['How does billing work?', `There is no monthly plan. You top up your wallet and every call is charged at ${money(rate)} per minute of talk time. Top-ups start from ${money(config.minTopUp)} and you can see the cost of each call in your call log.`],
        ['What if my balance runs low?', 'You get a low balance warning in your dashboard well before it runs out. A call only starts when there is enough balance to cover it, so you never end up owing money.'],
    ];

    const go = (id: string) => {
        setMenu(false);
        document.getElementById(id)?.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
    };

    const headline = ['Your', 'voice', 'answers'];
    const NAV: [string, string][] = [
        ['how', 'How it works'],
        ['demo', 'Demo'],
        ...(samples.length ? ([['voices', 'Voices']] as [string, string][]) : []),
        ['features', 'Features'],
        ['pricing', 'Pricing'],
        ['faq', 'FAQ'],
        ['contact', 'Contact'],
    ];

    return (
        <div className="lp">
            <Head title="AI receptionist in your own voice">
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
                            <Link href="/login" className="btn btn-ghost lp-hide-sm">
                                Log in
                            </Link>
                        )}
                        <Link href={startHref} className="btn btn-primary lp-shine">
                            {startLabel}
                        </Link>
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
                            AI receptionist for your website
                        </span>
                        <h1>
                            {headline.map((w, i) => (
                                <span key={w} className="lp-word" style={{ '--d': `${80 + i * 70}ms` } as CSSProperties}>
                                    {w}{' '}
                                </span>
                            ))}
                            <span className="lp-word" style={{ '--d': '300ms' } as CSSProperties}>
                                <em className="lp-serif lp-grad">every call.</em>{' '}
                            </span>
                            {['Even', 'when', "you're", 'busy.'].map((w, i) => (
                                <span key={w} className="lp-word lp-soft" style={{ '--d': `${380 + i * 60}ms` } as CSSProperties}>
                                    {w}{' '}
                                </span>
                            ))}
                        </h1>
                        <p className="lp-lead lp-enter" style={{ '--d': '520ms' } as CSSProperties}>
                            {name} picks up calls on your website in your own cloned voice. It answers questions, takes bookings and orders, records every conversation and sends the details straight to your software.
                        </p>
                        <div className="lp-cta lp-enter" style={{ '--d': '620ms' } as CSSProperties}>
                            <Link href={startHref} className="btn btn-primary btn-lg lp-shine">
                                {auth ? 'Open dashboard' : 'Create your receptionist'}
                                <Icon name="chev" className="i i-sm lp-arrow" />
                            </Link>
                            <button className="btn btn-ghost btn-lg" onClick={() => go('demo')}>
                                <span className="lp-play">
                                    <Icon name="play" className="i i-sm" />
                                </span>
                                Hear a sample call
                            </button>
                        </div>
                        <ul className="lp-ticks lp-enter" style={{ '--d': '720ms' } as CSSProperties}>
                            {['No monthly fee', 'Live in 10 minutes', 'No phone line needed'].map((t) => (
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
                                    Answering in <b>your voice</b>
                                    <span className="mono">00:42</span>
                                </div>
                                <div className="lp-eq">
                                    {Array.from({ length: 30 }, (_, i) => (
                                        <span key={i} style={{ height: `${28 + ((i * 41) % 72)}%`, animationDelay: `${(i % 8) * 0.11}s` }} />
                                    ))}
                                </div>
                                <p>"Sure! I have 11 am or 3 pm on Saturday. Which one works for you?"</p>
                            </div>

                            <div className="lp-toast">
                                <span className="ic">
                                    <Icon name="calendar" className="i i-sm" />
                                </span>
                                <span>
                                    <b>New booking</b>
                                    <small>Screen repair · Sat 11:00 am</small>
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
                    <span>Never misses a call, even at night or on holidays</span>
                </Reveal>
                <Reveal delay={80}>
                    <strong>
                        <CountUp to={30} suffix=" sec" />
                    </strong>
                    <span>Of your voice is all it takes to clone it</span>
                </Reveal>
                <Reveal delay={160}>
                    <strong>
                        <CountUp to={rate} decimals={2} prefix="$" />
                    </strong>
                    <span>Per minute of talk time, nothing else</span>
                </Reveal>
                <Reveal delay={240}>
                    <strong>
                        <CountUp to={LANGUAGES.length} />
                    </strong>
                    <span>Languages, including English, Urdu and Arabic</span>
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
                        <span className="lp-kicker">See it in action</span>
                        <h2>
                            A real conversation, then <em className="lp-serif">a clean booking.</em>
                        </h2>
                        <p>Pick a business and watch the receptionist handle a call from hello to confirmed.</p>
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

            {/* ---------- pricing ---------- */}
            <section id="pricing" className="lp-section">
                <div className="lp-wrap">
                    <Reveal className="lp-head">
                        <span className="lp-kicker">Pricing</span>
                        <h2>
                            Simple. <em className="lp-serif">Pay as you talk.</em>
                        </h2>
                        <p>No plans, no contracts, no setup fee. You only pay for the minutes your receptionist spends on calls.</p>
                    </Reveal>
                    <div className="lp-pricing">
                        <Reveal className="lp-price-card">
                            <div className="lp-price-top">
                                <span className="lp-chip">Pay per minute</span>
                                <div className="lp-price">
                                    <strong>{money(rate)}</strong>
                                    <span>/ minute</span>
                                </div>
                                <p>Top up from {money(config.minTopUp)}. Your wallet is charged only for talk time.</p>
                            </div>
                            <ul className="lp-list">
                                {['Your cloned voice, up to 5 voices', 'Unlimited bookings and orders', 'Recordings and full transcripts', 'Knowledge files and quick facts', 'Webhooks and software connectors', 'Website widget with your branding'].map((x) => (
                                    <li key={x}>
                                        <Icon name="check" className="i i-sm" />
                                        {x}
                                    </li>
                                ))}
                            </ul>
                            <Link href={startHref} className="btn btn-primary btn-lg lp-full lp-shine">
                                {auth ? 'Open dashboard' : 'Start now'}
                            </Link>
                        </Reveal>

                        <Reveal className="lp-calc" delay={120}>
                            <h3>Estimate your monthly cost</h3>
                            <p>Drag to the number of call minutes you expect in a month.</p>
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
                                    <strong className="num">${(minutes * rate).toFixed(2)}</strong>
                                    <span>per month</span>
                                </div>
                                <div>
                                    <small>Per day</small>
                                    <strong className="num">${((minutes * rate) / 30).toFixed(2)}</strong>
                                    <span>for a 24/7 receptionist</span>
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
                        Stop missing calls. <em className="lp-serif">Start booking more.</em>
                    </h2>
                    <p>Give your website a receptionist that sounds like you and never takes a day off.</p>
                    <div className="lp-cta center">
                        <Link href={startHref} className="btn btn-primary btn-lg lp-shine">
                            {auth ? 'Open dashboard' : 'Create your receptionist'}
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

            <footer className="lp-foot">
                <div className="lp-wrap lp-foot-in">
                    <div className="lp-foot-brand">
                        <Logo />
                        <p>An AI receptionist that answers your website calls in your own voice.</p>
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
                        {site.privacyUrl && <a href={site.privacyUrl}>Privacy</a>}
                        {site.termsUrl && <a href={site.termsUrl}>Terms</a>}
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
