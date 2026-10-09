import { cn } from '@/lib/utils';
import type { StoryProps } from '@/types/blocks';

/**
 * A large line on one side and the Merchant's rich text on the other; with
 * no line, the text alone. The HTML was sanitised on the server.
 */
export function Story({ eyebrow, quote, bodyHtml }: StoryProps) {
    const hasAside = Boolean(eyebrow || quote);

    if (!hasAside && !bodyHtml) {
        return null;
    }

    return (
        <div className="shell-container py-5 md:py-9">
            <div className={cn('grid items-start gap-7 md:gap-16', hasAside && bodyHtml ? 'md:grid-cols-2' : 'max-w-[760px]')}>
                {hasAside && (
                    <div className="flex flex-col gap-[18px]">
                        {eyebrow && <span className="text-[14px] font-semibold text-eyebrow">{eyebrow}</span>}
                        {quote && <p className="m-0 text-[clamp(24px,3vw,36px)] leading-[1.45] font-semibold text-balance text-ink">“{quote}”</p>}
                        <span className="block h-[3px] w-12 rounded-[3px] bg-gold-accent" />
                    </div>
                )}
                {bodyHtml && <div className="prose-story" dangerouslySetInnerHTML={{ __html: bodyHtml }} />}
            </div>
        </div>
    );
}
