import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

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
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#E8590C' },
});
