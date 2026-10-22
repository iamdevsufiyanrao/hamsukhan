import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import ErrorBoundary, { reportClientError } from './components/ErrorBoundary';
import { installAudioFocus } from './lib/audioFocus';

installAudioFocus();
// Errors outside React rendering (event handlers, SDK callbacks) go to the server log too.
window.addEventListener('error', (e) => reportClientError(e.message, e.error?.stack));
window.addEventListener('unhandledrejection', (e) => reportClientError(String(e.reason?.message ?? e.reason), e.reason?.stack));

const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });

createInertiaApp({
    title: (title) => {
        const site = document.querySelector('meta[name="application-name"]')?.getAttribute('content') || 'Hamsukhan';
        return title ? `${title} · ${site}` : site;
    },
    resolve: (name) => {
        const page = pages[`./pages/${name}.tsx`] as { default: any } | undefined;
        if (!page) throw new Error(`Page not found: ${name}`);
        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(
            <ErrorBoundary>
                <App {...props} />
            </ErrorBoundary>,
        );
    },
    progress: { color: '#E8590C' },
});
