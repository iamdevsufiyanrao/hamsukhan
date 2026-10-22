import { Component, ReactNode } from 'react';

/** Sends a browser error to the server log (best effort, never throws). */
export function reportClientError(message: string, stack?: string) {
    try {
        const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        fetch('/client-errors', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
            body: JSON.stringify({ message: message.slice(0, 500), stack: (stack ?? '').slice(0, 3000), url: location.pathname }),
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => {});
    } catch {}
}

/** A crash in one screen shows a friendly "reload" card instead of a blank page, and is reported to the server. */
export default class ErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidCatch(error: Error, info: { componentStack?: string | null }) {
        reportClientError(error.message, `${error.stack ?? ''}\n${info.componentStack ?? ''}`);
    }

    render() {
        if (!this.state.failed) return this.props.children;

        return (
            <div style={{ minHeight: '100vh', display: 'grid', placeItems: 'center', padding: 16, background: 'var(--bg)' }}>
                <div className="card" style={{ maxWidth: 420, padding: 24, textAlign: 'center' }}>
                    <h3 style={{ marginBottom: 8 }}>Something went wrong on this screen</h3>
                    <p style={{ color: 'var(--text-2)', marginBottom: 16 }}>We have been told about it. Reload the page to continue; nothing you saved is lost.</p>
                    <button className="btn btn-primary" onClick={() => location.reload()}>
                        Reload
                    </button>
                </div>
            </div>
        );
    }
}
