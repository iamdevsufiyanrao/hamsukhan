import { createContext, ReactNode, useCallback, useContext, useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import type { CallStatus } from '@/types';

/* ---------- toast + global modals ---------- */
type GlobalModal = 'test' | 'topup' | null;
type UI = { toast: (msg: string, kind?: 'ok' | 'error') => void; openModal: (m: GlobalModal) => void };
const UIContext = createContext<UI>({ toast: () => {}, openModal: () => {} });
export const useUI = () => useContext(UIContext);

export function UIProvider({ children, renderModals }: { children: ReactNode; renderModals: (open: GlobalModal, close: () => void) => ReactNode }) {
    const [msg, setMsg] = useState('');
    const [kind, setKind] = useState<'ok' | 'error'>('ok');
    const [show, setShow] = useState(false);
    const [modal, setModal] = useState<GlobalModal>(null);
    const timer = useRef<number>(undefined);
    const toast = useCallback((m: string, k: 'ok' | 'error' = 'ok') => {
        setMsg(m);
        setKind(k);
        setShow(true);
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => setShow(false), k === 'error' ? 4500 : 2800);
    }, []);
    return (
        <UIContext.Provider value={{ toast, openModal: setModal }}>
            {children}
            {renderModals(modal, () => setModal(null))}
            <div className={'toast' + (show ? ' on' : '')} role="status" style={kind === 'error' ? { background: 'var(--danger)', color: '#fff' } : undefined}>
                <Icon name={kind === 'error' ? 'alert' : 'check'} className="i" />
                <span>{msg}</span>
            </div>
        </UIContext.Provider>
    );
}

/* ---------- overlays ---------- */
function useEscape(open: boolean, onClose: () => void) {
    useEffect(() => {
        if (!open) return;
        const h = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', h);
        return () => window.removeEventListener('keydown', h);
    }, [open, onClose]);
}

export function Modal({ open, onClose, title, desc, children, footer }: { open: boolean; onClose: () => void; title: string; desc?: string; children?: ReactNode; footer?: ReactNode }) {
    useEscape(open, onClose);
    return (
        <>
            <div className={'scrim' + (open ? ' on' : '')} onClick={onClose} />
            <div className={'modal' + (open ? ' on' : '')} role="dialog" aria-modal="true" aria-hidden={!open}>
                <div className="modal-h">
                    <div>
                        <h3>{title}</h3>
                        {desc && <p>{desc}</p>}
                    </div>
                    <button className="icon-btn" onClick={onClose} aria-label="Close">
                        <Icon name="x" />
                    </button>
                </div>
                {children && <div className="modal-b">{children}</div>}
                {footer && <div className="modal-f">{footer}</div>}
            </div>
        </>
    );
}

export function Drawer({ open, onClose, title, sub, children }: { open: boolean; onClose: () => void; title: string; sub?: string; children: ReactNode }) {
    useEscape(open, onClose);
    return (
        <>
            <div className={'scrim' + (open ? ' on' : '')} onClick={onClose} />
            <aside className={'drawer' + (open ? ' on' : '')} aria-hidden={!open}>
                <div className="drawer-h">
                    <div>
                        <h3>{title}</h3>
                        {sub && <p>{sub}</p>}
                    </div>
                    <button className="icon-btn" onClick={onClose} aria-label="Close">
                        <Icon name="x" />
                    </button>
                </div>
                <div className="drawer-b">{children}</div>
            </aside>
        </>
    );
}

/* ---------- small bits ---------- */
export function StatusChip({ status }: { status: CallStatus }) {
    const cls = status === 'completed' ? 'ok' : status === 'dropped' ? 'warn' : 'bad';
    return <span className={'chip ' + cls}>{status[0].toUpperCase() + status.slice(1)}</span>;
}

export function FieldError({ msg }: { msg?: string }) {
    return msg ? <span className="err">{msg}</span> : null;
}

export function Seg<T extends string>({ value, options, onChange, style }: { value: T; options: { value: T; label: string }[]; onChange: (v: T) => void; style?: React.CSSProperties }) {
    return (
        <div className="seg" style={style}>
            {options.map((o) => (
                <button key={o.value} type="button" className={o.value === value ? 'on' : ''} onClick={() => onChange(o.value)}>
                    {o.label}
                </button>
            ))}
        </div>
    );
}

export function PasswordInput({ placeholder, id, value, onChange, invalid, autoComplete }: { placeholder?: string; id?: string; value: string; onChange: (v: string) => void; invalid?: boolean; autoComplete?: string }) {
    const [show, setShow] = useState(false);
    return (
        <div className="pw">
            <input id={id} className={'input' + (invalid ? ' bad' : '')} type={show ? 'text' : 'password'} placeholder={placeholder} value={value} onChange={(e) => onChange(e.target.value)} autoComplete={autoComplete} />
            <button type="button" onClick={() => setShow(!show)} aria-label={show ? 'Hide password' : 'Show password'}>
                <Icon name={show ? 'eye' : 'eyeOff'} className="i i-sm" />
            </button>
        </div>
    );
}

export function Avatar({ url, initials, size }: { url: string | null; initials: string; size?: number }) {
    return (
        <span className="avatar" style={size ? { width: size, height: size, fontSize: size / 2.9 } : undefined}>
            {url ? <img src={url} alt="" /> : initials}
        </span>
    );
}
