import type { ValueCardsProps } from '@/types/blocks';
import { SectionHeading } from './shared';

/** What the store stands for: soft cards, one column on a phone and three across above it. */
export function ValueCards({ eyebrow, heading, cards }: ValueCardsProps) {
    if (cards.length === 0) {
        return null;
    }

    return (
        <div className="shell-container flex flex-col gap-6 py-5 md:gap-11 md:py-9">
            <SectionHeading eyebrow={eyebrow} heading={heading} />
            <div className="grid grid-cols-1 gap-[14px] md:grid-cols-3">
                {cards.map((card, index) => (
                    <div key={index} className="flex flex-col gap-[10px] rounded-[18px] bg-surface p-[26px]">
                        {card.icon && (
                            <span className="flex size-11 items-center justify-center rounded-[12px] border border-border bg-white text-[18px] font-bold text-eyebrow" aria-hidden="true">
                                {card.icon}
                            </span>
                        )}
                        <b className="mt-[6px] text-[19px] font-semibold text-ink">{card.title}</b>
                        {card.text && <span className="text-[15px] leading-[1.75] text-muted">{card.text}</span>}
                    </div>
                ))}
            </div>
        </div>
    );
}
