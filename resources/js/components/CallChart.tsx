import { useRef, useState } from 'react';
import { fmtDate } from '@/lib/format';

const W = 700;
const H = 230;
const PL = 28;
const PB = 26;
const PT = 12;

export default function CallChart({ series, range }: { series: { date: string; count: number }[]; range: number }) {
    const wrap = useRef<HTMLDivElement>(null);
    const [tip, setTip] = useState<{ x: number; y: number; text: string } | null>(null);

    const data = series.slice(-range);
    const max = Math.max(5, Math.ceil(Math.max(...data.map((d) => d.count)) / 5) * 5);
    const bw = (W - PL) / data.length;
    const gap = Math.min(10, bw * 0.3);
    const every = range > 14 ? 5 : range > 7 ? 2 : 1;
    const y = (v: number) => PT + (H - PT - PB) * (1 - v / max);

    return (
        <div className="chart-wrap" ref={wrap}>
            <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" role="img" aria-label={`Calls per day, last ${range} days`}>
                {Array.from({ length: 6 }, (_, i) => (i * max) / 5).map((g) => (
                    <g key={g}>
                        <line x1={PL} x2={W} y1={y(g)} y2={y(g)} style={{ stroke: 'var(--border)' }} strokeDasharray={g ? '3 4' : undefined} />
                        <text x={PL - 8} y={y(g) + 4} fontSize="11" fontWeight="600" textAnchor="end" style={{ fill: 'var(--muted)' }}>
                            {g}
                        </text>
                    </g>
                ))}
                {data.map((d, i) => {
                    const last = i === data.length - 1;
                    const x = PL + i * bw + gap / 2;
                    const h = Math.max(d.count ? 2 : 0, H - PB - y(d.count));
                    return (
                        <g key={d.date}>
                            <rect
                                x={x}
                                y={H - PB - h}
                                width={bw - gap}
                                height={h}
                                rx={Math.min(6, (bw - gap) / 2)}
                                style={{ fill: last ? 'var(--accent)' : 'var(--border-strong)', cursor: 'pointer' }}
                                onMouseEnter={(e) => {
                                    const r = (e.target as SVGRectElement).getBoundingClientRect();
                                    const w = wrap.current!.getBoundingClientRect();
                                    setTip({ x: r.left - w.left + r.width / 2, y: r.top - w.top, text: `${d.count} calls · ${fmtDate(d.date)}` });
                                }}
                                onMouseLeave={() => setTip(null)}
                            />
                            {(i % every === 0 || last) && (
                                <text x={x + (bw - gap) / 2} y={H - 8} fontSize="11" fontWeight={last ? 800 : 600} textAnchor="middle" style={{ fill: last ? 'var(--accent)' : 'var(--muted)' }}>
                                    {last ? 'Today' : new Date(d.date).getDate()}
                                </text>
                            )}
                        </g>
                    );
                })}
            </svg>
            <div className="chart-tip" style={{ left: tip?.x, top: tip?.y, opacity: tip ? 1 : 0 }}>
                {tip?.text}
            </div>
        </div>
    );
}
