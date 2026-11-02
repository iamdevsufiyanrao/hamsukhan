import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { CSSProperties, MouseEvent, ReactNode, useEffect, useRef, useState } from 'react';
import VideoPlayer from '@/components/VideoPlayer';
import Icon, { IconName, Logo } from '@/components/Icon';
import LiveDemo from '@/components/LiveDemo';
import { Sample, SampleCard, useSamplePlayer } from '@/components/SamplePlayer';
import { useTheme } from '@/layouts/AppLayout';
import type { IntroVideo as IntroVideoData, SharedProps } from '@/types';

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
    { icon: 'user', title: 'Create your account', text: 'Sign up with your email. Your first assistant is ready straight away with a friendly greeting and a professional voice.' },
    { icon: 'sliders', title: 'Teach it your business', text: 'Write what it should say, add your timings, services, prices and policies, or upload your menu and price list. It only quotes what you approve.' },
    { icon: 'mic', title: 'Pick or clone a voice', text: 'Choose a ready Urdu/English voice, or record 1 to 2 minutes and it speaks in your own voice (with your recorded consent).' },
    { icon: 'code', title: 'Put it on your website', text: 'Paste one line of code, or switch on the Hamsukhan app on Shopify. A call button appears and visitors can talk to it at once.' },
];

const PROBLEMS: { icon: IconName; title: string; text: string }[] = [
    { icon: 'phone', title: 'Missed calls are missed customers', text: 'A visitor who cannot reach you at once usually goes to the next shop. Staff are busy, on leave or already on another call.' },
    { icon: 'clock', title: 'People enquire after closing time', text: 'Many questions come in the evening, at night and on holidays, exactly when nobody is there to answer.' },
    { icon: 'wallet', title: 'A full-time receptionist is costly', text: 'A salary, training, leave and one person can only take one call at a time, and only during working hours.' },
];

const SOLUTION = [
    'Answers every website visitor instantly, day and night',
    'Talks naturally in Urdu, English or both mixed',
    'Collects name, WhatsApp number and what they want',
    'Sends you the booking, order or callback straight away',
];

const CAPABILITIES: { group: string; icon: IconName; items: [string, string][] }[] = [
    {
        group: 'Talks to your customers',
        icon: 'wave',
        items: [
            ['Urdu, English or both mixed', 'Callers speak the way they normally do. Pakistani names, phone numbers and Rupee prices are understood.'],
            ['Your own voice, or a ready one', 'Start with a professional voice, pick one from the voice library, or clone your own voice with a short recording.'],
            ['Knows your business', 'Upload menus, price lists, FAQs and policies once. Quick facts like address and timings go straight into every answer.'],
            ['Safe, approved answers', 'It always says it is an AI, only quotes what you approved, and offers a callback when it is not sure.'],
        ],
    },
    {
        group: 'Captures bookings and orders',
        icon: 'calendar',
        items: [
            ['Bookings, orders and callbacks', 'Every call ends with a clean record: name, WhatsApp number, what they want and the preferred time.'],
            ['Your own questions', 'Add custom fields such as device model, delivery address or number of guests. Ready presets for restaurants, clinics and salons.'],
            ['Live booking in Google Calendar', 'Set your opening hours and connect Google Calendar: the assistant checks free slots and books them during the call.'],
            ['Callback form', 'Visitors who prefer not to talk can leave their number with one tap, and it lands in your bookings.'],
        ],
    },
    {
        group: 'Follows up for you',
        icon: 'phone',
        items: [
            ['Alerts on WhatsApp and email', 'You hear about every new lead within seconds, plus an optional summary at the end of each day.'],
            ['AI call-back', 'One click and the assistant calls the customer back on their phone to answer questions or confirm the booking.'],
            ['Appointment reminders', 'Confirmed bookings get a reminder call a few hours before, so fewer people forget to come.'],
            ['Calling campaigns and transfers', 'Call a list of customers with a message you approve, and pass a caller to a real person on your team when needed.'],
        ],
    },
    {
        group: 'Keeps you in control',
        icon: 'trend',
        items: [
            ['Recordings, transcripts and summaries', 'Listen to any call, read the full conversation and see a one-line summary and outcome.'],
            ['Insights and missed questions', 'See which questions it could not answer, so you can add the answer once and it knows it next time.'],
            ['Listen live', 'Watch calls as they happen, listen in, tell the assistant what to say or end the call.'],
            ['Team, stores and connectors', 'Give staff their own login and role, run several stores from one account, and send bookings into your own software.'],
        ],
    },
];

const INDUSTRIES: { icon: IconName; name: string; text: string }[] = [
    { icon: 'user', name: 'Salons and spas', text: 'Books a haircut or facial with the stylist and time the customer wants.' },
    { icon: 'shield', name: 'Clinics and dentists', text: 'Shares timings and fees, and books a check-up with the right doctor.' },
    { icon: 'book', name: 'Restaurants and bakeries', text: 'Takes delivery and takeaway orders with items, address and total.' },
    { icon: 'wrench', name: 'Repair shops', text: 'Notes the device and fault, gives your price range and books a visit.' },
    { icon: 'home', name: 'Real estate', text: 'Asks budget, area and size, and books a property viewing.' },
    { icon: 'users', name: 'Schools, tutors and gyms', text: 'Explains fees and timings and books a trial class or admission visit.' },
    { icon: 'settings', name: 'Car service and workshops', text: 'Books a service slot and notes the car model and problem.' },
    { icon: 'layout', name: 'Online stores', text: 'Answers delivery, return and product questions on your Shopify store.' },
];

const PLATFORMS: { icon: IconName; name: string; text: string }[] = [
    { icon: 'layout', name: 'Shopify', text: 'Connect your store from the dashboard, or switch on the Hamsukhan app embed in your theme.' },
    { icon: 'code', name: 'WordPress', text: 'Paste one line in your theme footer or any "header and footer scripts" plugin.' },
    { icon: 'home', name: 'Wix, Squarespace and others', text: 'Add the line as custom code on your site settings page.' },
    { icon: 'plug', name: 'Your own website or software', text: 'Plain HTML, Laravel or any framework, plus signed webhooks into your system.' },
];

const COMPARE: [string, string, string][] = [
    ['Available', 'Office hours, minus leave and breaks', '24 hours a day, 7 days a week'],
    ['Calls at the same time', 'One', 'Many at once'],
    ['Languages', 'Depends on the person', 'Urdu, English and mixed'],
    ['Monthly cost', 'A full salary', 'From the Basic plan price'],
    ['Records every call', 'No', 'Recording, transcript and summary'],
    ['Sends the lead to you', 'When they remember', 'Instantly on WhatsApp and email'],
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

const VIDEO_POINTS: [IconName, string, string][] = [
    ['mic', 'What Hamsukhan is', 'An AI receptionist that talks to your website visitors in Urdu and English.'],
    ['sliders', 'How you set it up', 'Your prompt, your voice and one line of code. About ten minutes.'],
    ['calendar', 'What you get', 'Bookings, orders and callbacks sent to you on WhatsApp and email.'],
    ['wallet', 'What it costs', 'One monthly plan with unlimited calls. No per-minute bills.'],
];

/** "Watch: Hamsukhan in 60 seconds": an uploaded video (vertical in a phone frame, or wide) or a YouTube link. */
/**
 * Rotating hooks under the hero text: one big reason at a time (every 4 s, pauses on hover/focus,
 * no motion for people who ask for reduced motion). The no-balance hook leads: in Pakistan many
 * would-be customers can't phone a business because they have no mobile balance.
 */
function HeroHooks({ trialText }: { trialText: string | null }) {
    const hooks: { icon: IconName; title: string; text: string }[] = [
        { icon: 'phone', title: 'Customers call you without any mobile balance', text: 'It is a free internet call from your website. No balance, no package, so no lead is lost because their credit ran out.' },
        ...(trialText ? [{ icon: 'wallet' as IconName, title: `${trialText}. Really.`, text: 'The full Basic plan for 30 days: unlimited calls, your voice, bookings and alerts. No card, no payment details.' }] : []),
        { icon: 'clock', title: 'Answers at 2 AM, on Sundays and on Eid', text: 'Every enquiry after closing time becomes a booking or order instead of a missed call.' },
        { icon: 'mic', title: 'Real Pakistani Urdu, in your own voice', text: 'Shukriya, ji haan, bilkul: it talks like your best staff member, in Urdu or English, never Hindi.' },
        { icon: 'users', title: 'Never a busy line', text: 'Many customers can talk at the same time. Nobody waits, nobody hangs up.' },
    ];
    const [i, setI] = useState(0);
    const [paused, setPaused] = useState(false);
    useEffect(() => {
        if (paused || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const t = window.setInterval(() => setI((n) => (n + 1) % hooks.length), 4000);
        return () => window.clearInterval(t);
    }, [paused, hooks.length]);
    const h = hooks[i % hooks.length];

    return (
        <div className="lp-hooks lp-enter" style={{ '--d': '560ms' } as CSSProperties} onMouseEnter={() => setPaused(true)} onMouseLeave={() => setPaused(false)} onFocus={() => setPaused(true)} onBlur={() => setPaused(false)}>
            <div className="lp-hook" key={i} aria-live="polite">
                <span className="lp-hook-ic">
                    <Icon name={h.icon} />
                </span>
                <span>
                    <b>{h.title}</b>
                    <small>{h.text}</small>
                </span>
            </div>
            <div className="lp-hook-dots" role="tablist" aria-label="Reasons">
                {hooks.map((x, n) => (
                    <button key={x.title} type="button" role="tab" aria-selected={n === i % hooks.length} aria-label={x.title} className={n === i % hooks.length ? 'on' : ''} onClick={() => setI(n)} />
                ))}
            </div>
        </div>
    );
}

function IntroVideo({ video, name, onDemo }: { video: IntroVideoData; name: string; onDemo: () => void }) {
    const wide = !!video.youtubeId || !video.vertical;
    const player = video.youtubeId ? (
        <iframe
            src={`https://www.youtube-nocookie.com/embed/${encodeURIComponent(video.youtubeId)}?rel=0`}
            title={`${name} introduction video`}
            loading="lazy"
            allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen"
            allowFullScreen
        />
    ) : (
        <VideoPlayer src={video.src ?? ''} poster={video.poster} label={`${name} introduction video`} />
    );

    return (
        <section id="video" className="lp-section lp-video-sec">
            <div className={'lp-wrap lp-video' + (wide ? ' wide' : '')}>
                <Reveal className="lp-video-copy">
                    <span className="lp-kicker">Watch first</span>
                    <h2>
                        {name} in <em className="lp-serif">60 seconds.</em>
                    </h2>
                    <p>A short introduction and guide: what it does, how it works and how to get started.</p>
                    <ul className="lp-video-points">
                        {VIDEO_POINTS.map(([icon, title, text]) => (
                            <li key={title}>
                                <span className="lp-feature-ic">
                                    <Icon name={icon} />
                                </span>
                                <span>
                                    <b>{title}</b>
                                    {text}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <button className="btn btn-ghost btn-lg" onClick={onDemo}>
                        <Icon name="mic" className="i i-sm" />
                        Then try it yourself
                    </button>
                </Reveal>
                <Reveal className={'lp-video-frame' + (wide ? ' wide' : ' phone')} delay={120}>
                    {player}
                </Reveal>
            </div>
        </section>
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
    const [faq, setFaq] = useState<number | null>(0);
    const [menu, setMenu] = useState(false);
    const [scrolled, setScrolled] = useState(false);
    const progress = useRef<HTMLDivElement>(null);
    const art = useRef<HTMLDivElement>(null);
    const name = site.name || 'Hamsukhan';
    // Plans come from the server (the super admin can change prices); fair-use limits are never sent here.
    const { plans, trialDays } = config;
    const basic = plans.find((p) => p.id === 'basic') ?? plans[0];
    const startHref = auth ? '/dashboard' : site.allowSignups ? '/register' : '/login';
    const trialText = trialDays >= 28 ? 'First month free' : trialDays > 0 ? `${trialDays} days free` : null;
    const startLabel = auth ? 'Open dashboard' : !site.allowSignups ? 'Log in' : trialDays >= 28 ? 'Start your free month' : trialDays > 0 ? `Start ${trialDays}-day free trial` : 'Get started';
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
        ['What exactly is Hamsukhan?', `${name} is an AI receptionist for your website. Visitors tap a call button and talk to it from their phone or laptop, no app needed. It answers their questions from the information you gave it, and hands you a clean booking, order or callback request.`],
        ['How long does setup take?', 'About ten minutes. Sign up, write what your business does (or pick a template), choose a voice and paste one line on your website. You can test it from your dashboard before any visitor sees it.'],
        ['Which languages can it speak?', 'Urdu, English and a natural mix of both, including Roman Urdu style phrasing. Callers can switch between them in the middle of a sentence.'],
        ['What if the AI does not know an answer?', 'It only quotes the prices, timings and policies you approved. When it is unsure it says so and takes the caller\u2019s name and WhatsApp number for your team. Your dashboard lists these questions so you can add the answer once.'],
        ['Does it answer my phone line too?', 'It is built for calls from your website. On request we can also connect a phone number for incoming calls, AI call-backs, reminders and calling campaigns. Talk to us about your case.'],
        ['Do I have to clone my voice?', 'No. Every assistant starts with a professional voice, and you can pick others from the voice library. Cloning is optional and only for your own voice, or one you have clear permission to use; we record that consent.'],
        ['Does it work on Shopify and WordPress?', 'Yes, on any website. On Shopify connect your store from the dashboard or switch on the Hamsukhan app embed; on WordPress and others paste one line of code. The Business plan runs up to 5 stores from one login.'],
        ['How does billing work?', `A flat monthly plan, paid in advance: ${plans.map((p) => `${p.name} ${rs(p.pricePkr)}/month (${p.stores} store${p.stores > 1 ? 's' : ''}, ${p.users} user${p.users > 1 ? 's' : ''})`).join(' or ')}. Calls are unlimited under a fair-use policy, so there is no per-minute bill.`],
        ['How do I pay?', 'In Rupees by JazzCash, Easypaisa or bank transfer, for 1, 3, 6 or 12 months at a time. Pay online where available and the plan switches on at once, or send the payment and upload the receipt and we switch it on after checking it.'],
        ['Can I cancel?', 'Yes. There is no contract: simply do not renew. When the plan ends your assistant stops taking calls, and your settings, calls and bookings stay safe if you come back.'],
        ['Can bookings go into my own software?', 'Yes. Each booking or order can be sent to your system with a signed webhook mapped to your own fields, with automatic retries. There is a ready package for Laravel and plain PHP, and Google Calendar for live booking.'],
        ['What happens to my data and my callers\u2019 data?', 'It is used only to run your assistant and show you your calls. It is not sold and not used to train AI models without your written agreement. You can delete calls and voices any time.'],
    ];

    const go = (id: string) => {
        setMenu(false);
        document.getElementById(id)?.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
    };

    const headline = ['Turn', 'website', 'visitors', 'into'];
    const video = site.introVideo && (site.introVideo.src || site.introVideo.youtubeId) ? site.introVideo : null;
    const NAV: [string, string][] = [
        ...(video ? ([['video', 'Watch']] as [string, string][]) : []),
        ['how', 'How it works'],
        ['features', 'Features'],
        ['demo', 'Demo'],
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
                        <Link href="/docs">Guide</Link>
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
                        {trialText && !auth ? (
                            <Link href={startHref} className="lp-eyebrow lp-offer lp-enter" style={{ '--d': '0ms' } as CSSProperties}>
                                <span className="lp-offer-tag">{trialText}</span>
                                No card, no payment. Try everything for {trialDays >= 28 ? '30 days' : `${trialDays} days`}.
                                <Icon name="chev" className="i i-sm" />
                            </Link>
                        ) : (
                            <span className="lp-eyebrow lp-enter" style={{ '--d': '0ms' } as CSSProperties}>
                                <span className="lp-live" />
                                AI voice receptionist · Urdu + English
                            </span>
                        )}
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
                            {name} is an AI receptionist on your website. Visitors tap a button and talk in Urdu or English; it answers their questions, takes the booking or order and sends it to you on WhatsApp. <b>Aap ki awaaz mein, 24/7.</b>
                        </p>
                        <HeroHooks trialText={trialText} />
                        <div className="lp-cta lp-enter" style={{ '--d': '620ms' } as CSSProperties}>
                            <button className="btn btn-primary btn-lg lp-shine" onClick={() => go('demo')}>
                                <span className="lp-play">
                                    <Icon name="mic" className="i i-sm" />
                                </span>
                                Try a Live Urdu Demo
                            </button>
                            <Link href={startHref} className="btn btn-ghost btn-lg">
                                {startLabel}
                                <Icon name="chev" className="i i-sm lp-arrow" />
                            </Link>
                            {video && (
                                <button className="btn btn-lg lp-watch" onClick={() => go('video')}>
                                    <Icon name="play" className="i i-sm" />
                                    Watch 60 sec
                                </button>
                            )}
                        </div>
                        <ul className="lp-ticks lp-enter" style={{ '--d': '720ms' } as CSSProperties}>
                            {['No mobile balance needed to call you', 'Ready in about 10 minutes', 'Unlimited calls, one monthly price'].map((t) => (
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
                        <CountUp to={basic?.pricePkr ?? 0} prefix="Rs " />
                    </strong>
                    <span>A month on the Basic plan, unlimited calls</span>
                </Reveal>
                <Reveal delay={240}>
                    <strong>
                        <CountUp to={LANGUAGES.length} />
                    </strong>
                    <span>Ways to talk: Urdu, English, or both mixed</span>
                </Reveal>
            </section>

            {/* ---------- intro video (default brand reel, or what the super admin uploaded) ---------- */}
            {video && <IntroVideo video={video} name={name} onDemo={() => go('demo')} />}

            {/* ---------- problem → solution ---------- */}
            <section id="why" className={'lp-section' + (video ? ' lp-alt' : '')}>
                <div className="lp-wrap">
                    <Reveal className="lp-head">
                        <span className="lp-kicker">Why businesses use it</span>
                        <h2>
                            Every unanswered call is <em className="lp-serif">a lost customer.</em>
                        </h2>
                        <p>Most small businesses cannot answer every enquiry, every hour. {name} picks up every time.</p>
                    </Reveal>
                    <div className="lp-features">
                        {PROBLEMS.map((f, i) => (
                            <Reveal key={f.title} className="lp-feature lp-problem" delay={i * 90}>
                                <span className="lp-feature-ic">
                                    <Icon name={f.icon} />
                                </span>
                                <h3>{f.title}</h3>
                                <p>{f.text}</p>
                            </Reveal>
                        ))}
                    </div>
                    <Reveal className="lp-solution" delay={120}>
                        <div>
                            <span className="lp-chip">The fix</span>
                            <h3>A receptionist that never misses a call.</h3>
                        </div>
                        <ul className="lp-list">
                            {SOLUTION.map((t) => (
                                <li key={t}>
                                    <Icon name="check" className="i i-sm" />
                                    {t}
                                </li>
                            ))}
                        </ul>
                    </Reveal>
                </div>
            </section>

            {/* ---------- how ---------- */}
            <section id="how" className="lp-section">
                <div className="lp-wrap">
                    <Reveal className="lp-head">
                        <span className="lp-kicker">How it works</span>
                        <h2>
                            Live in four steps. <em className="lp-serif">No developer needed.</em>
                        </h2>
                        <p>From sign-up to a receptionist answering on your website takes about ten minutes.</p>
                    </Reveal>
                    <div className="lp-steps four">
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
                    <Reveal className="lp-after" delay={200}>
                        <span className="lp-feature-ic">
                            <Icon name="send" />
                        </span>
                        <p>
                            <b>Then it works for you:</b> a visitor taps the button and talks, the assistant answers, takes the details and you get the booking, order or callback on WhatsApp and email within seconds. Everything is saved in your dashboard.
                        </p>
                    </Reveal>
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

            {/* ---------- everything it does ---------- */}
            <section id="features" className="lp-section">
                <div className="lp-wrap">
                    <Reveal className="lp-head">
                        <span className="lp-kicker">Everything it does</span>
                        <h2>
                            Everything a great receptionist does. <em className="lp-serif">And more.</em>
                        </h2>
                        <p>All of this is included in every plan.</p>
                    </Reveal>
                    <div className="lp-caps">
                        {CAPABILITIES.map((g, gi) => (
                            <Reveal key={g.group} className="lp-cap lp-glow" delay={(gi % 2) * 100} onMouseMove={spot}>
                                <div className="lp-cap-h">
                                    <span className="lp-feature-ic">
                                        <Icon name={g.icon} />
                                    </span>
                                    <h3>{g.group}</h3>
                                </div>
                                <dl>
                                    {g.items.map(([t, d]) => (
                                        <div key={t}>
                                            <dt>
                                                <Icon name="check" className="i i-sm" />
                                                {t}
                                            </dt>
                                            <dd>{d}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </Reveal>
                        ))}
                    </div>
                </div>
            </section>

            {/* ---------- who it's for ---------- */}
            <section id="industries" className="lp-section lp-alt">
                <div className="lp-wrap">
                    <Reveal className="lp-head">
                        <span className="lp-kicker">Who it is for</span>
                        <h2>
                            Made for businesses that <em className="lp-serif">take enquiries.</em>
                        </h2>
                        <p>If customers call or message you to ask, book or order, {name} can answer them.</p>
                    </Reveal>
                    <div className="lp-inds">
                        {INDUSTRIES.map((b, i) => (
                            <Reveal key={b.name} className="lp-ind" delay={(i % 4) * 70}>
                                <span className="lp-feature-ic">
                                    <Icon name={b.icon} />
                                </span>
                                <b>{b.name}</b>
                                <p>{b.text}</p>
                            </Reveal>
                        ))}
                    </div>
                </div>
            </section>

            {/* ---------- install ---------- */}
            <section id="install" className="lp-section">
                <div className="lp-wrap lp-install">
                    <Reveal>
                        <span className="lp-kicker">One line of code</span>
                        <h2>
                            Works on <em className="lp-serif">any</em> website.
                        </h2>
                        <p>Copy the line from your dashboard and paste it before the closing body tag, or connect Shopify in one click. Change colours, labels and icons any time without touching the code again.</p>
                        <div className="lp-platforms">
                            {PLATFORMS.map((pl) => (
                                <div key={pl.name}>
                                    <Icon name={pl.icon} className="i i-sm" />
                                    <span>
                                        <b>{pl.name}</b>
                                        {pl.text}
                                    </span>
                                </div>
                            ))}
                        </div>
                        <ul className="lp-list">
                            {['Matches your brand colours', 'Works on mobile and desktop', 'Only your own websites can use your assistant', 'We tell you the moment it is installed'].map((t) => (
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

            {/* ---------- compare ---------- */}
            <section id="compare" className="lp-section">
                <div className="lp-wrap">
                    <Reveal className="lp-head">
                        <span className="lp-kicker">Compare</span>
                        <h2>
                            A receptionist, <em className="lp-serif">without the limits.</em>
                        </h2>
                        <p>{name} does not replace your team; it takes the repetitive calls so your people can focus on customers in front of them.</p>
                    </Reveal>
                    <Reveal className="lp-compare" delay={80}>
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">
                                        <span className="lp-sr">Topic</span>
                                    </th>
                                    <th scope="col">Hiring a receptionist</th>
                                    <th scope="col" className="us">
                                        {name}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {COMPARE.map(([k, a, b]) => (
                                    <tr key={k}>
                                        <th scope="row">{k}</th>
                                        <td>{a}</td>
                                        <td className="us">
                                            <Icon name="check" className="i i-sm" />
                                            {k === 'Monthly cost' && basic ? `${rs(basic.pricePkr)} a month` : b}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Reveal>
                </div>
            </section>

            {/* ---------- pricing ---------- */}
            <section id="pricing" className="lp-section">
                <div className="lp-wrap">
                    <Reveal className="lp-head">
                        <span className="lp-kicker">Pricing</span>
                        <h2>
                            Simple. <em className="lp-serif">One monthly price.</em>
                        </h2>
                        <p>Unlimited calls on every plan, no per-minute bills and no setup fee. Pay in Rupees by JazzCash, Easypaisa or bank transfer, for 1, 3, 6 or 12 months. No contract: stop any time by not renewing.</p>
                    </Reveal>
                    <div className="lp-pricing">
                        {plans.map((p, i) => (
                            <Reveal key={p.id} className="lp-price-card" delay={i * 120}>
                                <div className="lp-price-top">
                                    <span className="lp-chip">{p.name}</span>
                                    <div className="lp-price">
                                        <strong>{rs(p.pricePkr)}</strong>
                                        <span>/ month</span>
                                    </div>
                                    <p>
                                        {p.stores === 1 ? '1 store (website)' : `Up to ${p.stores} stores (websites)`} · {p.users === 1 ? '1 user' : `${p.users} users`}
                                    </p>
                                </div>
                                <ul className="lp-list">
                                    {[
                                        'Unlimited calls (fair use)',
                                        p.stores > 1 ? `${p.stores} stores, each with its own assistant, voice and widget` : 'One assistant on your website',
                                        p.users > 1 ? `You plus ${p.users - 1} team members with their own login and role` : 'Your own login',
                                        'Works on any website, including Shopify',
                                        'Professional voice included, own voice clone optional',
                                        'Bookings, orders, callbacks and live Google Calendar booking',
                                        'WhatsApp and email lead alerts',
                                        'Recordings, transcripts, summaries and insights',
                                        'Renew for 1, 3, 6 or 12 months',
                                    ].map((x) => (
                                        <li key={x}>
                                            <Icon name="check" className="i i-sm" />
                                            {x}
                                        </li>
                                    ))}
                                </ul>
                                <Link href={startHref} className={'btn btn-lg lp-full ' + (i === 0 ? 'btn-primary lp-shine' : 'btn-ghost')}>
                                    {auth ? 'Open dashboard' : !site.allowSignups ? 'Log in' : trialDays > 0 ? `Start ${trialDays}-day free trial` : `Get ${p.name}`}
                                </Link>
                            </Reveal>
                        ))}
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
                    <p>Give your website a receptionist that talks in Urdu and English and never takes a day off. Set it up today in about ten minutes.</p>
                    <div className="lp-cta center">
                        <Link href={startHref} className="btn btn-primary btn-lg lp-shine">
                            {startLabel}
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
                        {video && <button onClick={() => go('video')}>Watch the video</button>}
                        <button onClick={() => go('how')}>How it works</button>
                        <button onClick={() => go('features')}>Features</button>
                        <button onClick={() => go('industries')}>Who it is for</button>
                        <button onClick={() => go('install')}>Shopify and WordPress</button>
                        <button onClick={() => go('pricing')}>Pricing</button>
                    </div>
                    <div className="lp-foot-col">
                        <b>Company</b>
                        <button onClick={() => go('faq')}>FAQ</button>
                        <Link href="/docs">Guide & developer docs</Link>
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
