export function fmtDur(s: number) {
    return s ? `${Math.floor(s / 60)}m ${String(s % 60).padStart(2, '0')}s` : '-';
}
export function fmtDate(d: string | Date) {
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
}
export function fmtDateLong(d: string | Date) {
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}
export function fmtTime(d: string | Date) {
    return new Date(d).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}
export function usd(n: number, digits = 2) {
    return '$' + n.toFixed(digits);
}
export function pkr(n: number) {
    return 'Rs ' + Math.round(n).toLocaleString('en-PK');
}
export function fileSize(bytes: number) {
    return bytes >= 1024 * 1024 ? (bytes / 1024 / 1024).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
}
export function timeAgo(d: string | Date) {
    const s = Math.round((Date.now() - new Date(d).getTime()) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return `${Math.floor(s / 60)}m ago`;
    if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
    return `${Math.floor(s / 86400)}d ago`;
}
export function hourRange(h: number | null | undefined) {
    if (h === null || h === undefined) return '-';
    const f = (x: number) => `${((x + 11) % 12) + 1} ${x < 12 ? 'am' : 'pm'}`;
    return `${f(h)} – ${f((h + 1) % 24)}`;
}

/** JSON request to a Laravel web route (sends the XSRF cookie like Inertia does). */
export async function api<T = any>(url: string, opts: { method?: string; body?: unknown } = {}): Promise<T> {
    const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
    const res = await fetch(url, {
        method: opts.method ?? 'GET',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest' },
        body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
        credentials: 'same-origin',
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.message || `Request failed (${res.status})`), { status: res.status, data });
    return data as T;
}
