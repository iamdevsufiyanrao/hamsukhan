import { Head, router } from '@inertiajs/react';
import Icon from '@/components/Icon';
import { Seg } from '@/components/ui';
import AppLayout from '@/layouts/AppLayout';
import { fmtDate, fmtTime } from '@/lib/format';

type Status = 'new' | 'read';
type Message = { id: number; name: string; email: string; business: string | null; topic: string; message: string; status: Status; createdAt: string };
type Props = { filter: Status; messages: Message[]; counts: Partial<Record<Status, number>> };

/** Admin: messages sent from the "Contact us" form on the landing page. */
export default function Messages({ filter, messages, counts }: Props) {
    const remove = (m: Message) => {
        if (confirm(`Delete the message from ${m.name}?`)) router.delete(`/admin/messages/${m.id}`, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Messages" />
            <div className="card">
                <div className="toolbar">
                    <Seg
                        value={filter}
                        onChange={(s) => router.get('/admin/messages', { status: s }, { preserveState: true, replace: true })}
                        options={[
                            { value: 'new', label: `New (${counts.new ?? 0})` },
                            { value: 'read', label: `Read (${counts.read ?? 0})` },
                        ]}
                    />
                    <div style={{ flex: 1 }} />
                    <span style={{ fontSize: 12.5, color: 'var(--muted)', fontWeight: 600 }}>Sent from the Contact form on your home page</span>
                </div>
                {messages.length === 0 && <div className="empty">{filter === 'new' ? 'No new messages.' : 'Nothing here.'}</div>}
                <div className="msg-list">
                    {messages.map((m) => (
                        <div key={m.id} className="msg-item">
                            <div className="msg-top">
                                <div className="bk-who">
                                    <b>
                                        {m.name}
                                        {m.business && <span style={{ fontWeight: 600, color: 'var(--muted)' }}> · {m.business}</span>}
                                    </b>
                                    <span>{m.email}</span>
                                </div>
                                <span className="chip test">{m.topic}</span>
                                <div style={{ flex: 1 }} />
                                <span className="t-time">
                                    {fmtDate(m.createdAt)}
                                    <span>{fmtTime(m.createdAt)}</span>
                                </span>
                            </div>
                            <p className="msg-body">{m.message}</p>
                            <div className="row-acts" style={{ justifyContent: 'flex-start' }}>
                                <a className="btn btn-primary btn-sm" href={`mailto:${m.email}?subject=${encodeURIComponent('Re: your message')}`}>
                                    <Icon name="mail" className="i i-sm" />
                                    Reply by email
                                </a>
                                {m.status === 'new' && (
                                    <button className="btn btn-ghost btn-sm" onClick={() => router.post(`/admin/messages/${m.id}/read`, {}, { preserveScroll: true })}>
                                        <Icon name="check" className="i i-sm" />
                                        Mark as read
                                    </button>
                                )}
                                <button className="btn btn-ghost btn-sm" onClick={() => remove(m)}>
                                    <Icon name="trash" className="i i-sm" />
                                    Delete
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

Messages.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;
