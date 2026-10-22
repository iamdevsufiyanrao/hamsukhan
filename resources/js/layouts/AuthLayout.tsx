import { Head, Link } from '@inertiajs/react';
import { ReactNode } from 'react';
import Icon, { Logo } from '@/components/Icon';

export default function AuthLayout({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) {
    return (
        <div className="auth">
            <Head title={title} />
            <div className="auth-form">
                <Link href="/" aria-label="Hamsukhan home">
                    <Logo />
                </Link>
                <div className="auth-inner">
                    <h1>{title}</h1>
                    <p className="sub">{subtitle}</p>
                    {children}
                </div>
                <div className="auth-foot">
                    © {new Date().getFullYear()} Hamsukhan · <a href="/privacy">Privacy</a> · <a href="/terms">Terms</a>
                </div>
            </div>

            <div className="auth-art" aria-hidden="true">
                <div className="art-bars">
                    {Array.from({ length: 28 }, (_, i) => (
                        <span key={i} style={{ height: `${30 + ((i * 37) % 70)}%`, animationDelay: `${(i % 7) * 0.12}s` }} />
                    ))}
                </div>
                <div>
                    <h2>
                        Website visitors. <b>Turned into bookings.</b>
                    </h2>
                    <p className="lead">Hamsukhan is an AI voice receptionist on your website. Visitors talk in Urdu or English, it answers approved questions and sends you a clean booking or callback request.</p>
                </div>
                <div className="art-call">
                    <div className="h">
                        <i />
                        Live call · Smart Geeks<span>01:24</span>
                    </div>
                    <div className="b u">Can I book a screen repair for Saturday?</div>
                    <div className="b ai">Sure! I have 11am or 3pm free. Which one works for you?</div>
                </div>
                <div className="art-feats">
                    <div>
                        <Icon name="mic" className="i i-sm" />
                        Urdu + English
                    </div>
                    <div>
                        <Icon name="calendar" className="i i-sm" />
                        Auto bookings
                    </div>
                    <div>
                        <Icon name="phone" className="i i-sm" />
                        Callback requests
                    </div>
                </div>
            </div>
        </div>
    );
}
