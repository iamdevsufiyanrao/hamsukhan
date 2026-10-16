import Icon from './Icon';
import { StatusChip } from './ui';
import { fmtDate, fmtDur, fmtTime } from '@/lib/format';
import type { CallRowData } from '@/types';

/** One call in a table. `full` adds the quote, duration, status and chevron columns. */
export default function CallRow({ call, full, onOpen }: { call: CallRowData; full?: boolean; onOpen: (id: number) => void }) {
    return (
        <tr onClick={() => onOpen(call.id)}>
            <td className="t-time">
                {fmtTime(call.startedAt)}
                <span>{fmtDate(call.startedAt)}</span>
            </td>
            <td className="t-script">
                <b>{call.title}</b>
                {full && call.quote && ` · “${call.quote}”`}
            </td>
            <td>
                <span className="tag">{call.tag}</span> {call.isTest && <span className="chip test">Test</span>}
            </td>
            {full && (
                <>
                    <td className="num">{fmtDur(call.duration)}</td>
                    <td>
                        <StatusChip status={call.status} />
                    </td>
                </>
            )}
            <td className="r num">${call.cost.toFixed(4)}</td>
            {full && (
                <td className="r" style={{ color: 'var(--muted)' }}>
                    <Icon name="chev" className="i i-sm" />
                </td>
            )}
        </tr>
    );
}
