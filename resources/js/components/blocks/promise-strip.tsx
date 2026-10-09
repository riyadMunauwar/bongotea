import { cn } from '@/lib/utils';
import type { PromiseStripProps } from '@/types/blocks';

/** Desktop columns by how many promises there are; two to a row on a phone. */
const DESKTOP_COLUMNS: Record<number, string> = {
    1: 'md:grid-cols-1',
    2: 'md:grid-cols-2',
    3: 'md:grid-cols-3',
    4: 'md:grid-cols-4',
};

/**
 * The strip of promises under the hero, as one bordered card split into
 * cells.
 */
export function PromiseStrip({ items }: PromiseStripProps) {
    if (items.length === 0) {
        return null;
    }

    return (
        <div className="shell-container pt-4 md:pt-6">
            <div className={cn('grid grid-cols-2 gap-px overflow-hidden rounded-[14px] border border-border bg-border', DESKTOP_COLUMNS[items.length])}>
                {items.map((item, index) => (
                    <div key={index} className="flex items-center gap-3 bg-white p-3 md:px-[18px] md:py-4">
                        {item.icon && (
                            <span className="flex size-10 flex-none items-center justify-center rounded-[10px] bg-green-50 text-[17px] font-bold text-primary" aria-hidden="true">
                                {item.icon}
                            </span>
                        )}
                        <span className="flex min-w-0 flex-col gap-px">
                            <b className="text-[14px] font-bold text-ink">{item.title}</b>
                            {item.text && <span className="text-[12px] leading-[1.4] text-muted">{item.text}</span>}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}
