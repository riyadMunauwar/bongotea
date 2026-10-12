import { cn } from '@/lib/utils';
import type { InfoCardsProps } from '@/types/blocks';
import { ButtonLink, SectionHeading } from './shared';

const GRID: Record<InfoCardsProps['columns'], string> = {
    1: 'md:grid-cols-1',
    2: 'md:grid-cols-2',
    3: 'md:grid-cols-2 lg:grid-cols-3',
};

/**
 * Cards with room to explain: a badge, a title and the line under it, the
 * words, labelled lists ticked off, a note set apart and a button. A card
 * with its own anchor is offset like a Block's, clear of the sticky header.
 */
export function InfoCards({ eyebrow, heading, text, columns, cards }: InfoCardsProps) {
    if (cards.length === 0) {
        return null;
    }

    return (
        <div className="shell-container flex flex-col gap-6 py-5 md:gap-11 md:py-9">
            <SectionHeading eyebrow={eyebrow} heading={heading} text={text} />
            <div className={cn('grid grid-cols-1 gap-[14px]', GRID[columns])}>
                {cards.map((card, index) => (
                    <article
                        key={index}
                        id={card.anchor ?? undefined}
                        className={cn('flex flex-col gap-4 rounded-[18px] border border-border bg-white p-[22px] md:p-7', card.anchor && 'scroll-mt-[130px] lg:scroll-mt-[170px]')}
                    >
                        <header className="flex items-start gap-[14px]">
                            {card.badge && (
                                <span className="flex size-11 flex-none items-center justify-center rounded-[12px] bg-green-50 text-[18px] font-bold text-primary-dark" aria-hidden="true">
                                    {card.badge}
                                </span>
                            )}
                            <div className="flex flex-col gap-1">
                                {card.eyebrow && <span className="text-[13px] font-semibold text-eyebrow">{card.eyebrow}</span>}
                                <h3 className="m-0 text-[clamp(19px,1.8vw,22px)] leading-[1.35] font-semibold text-ink">{card.title}</h3>
                                {card.subtitle && <span className="text-[15px] font-medium text-primary">{card.subtitle}</span>}
                            </div>
                        </header>

                        {card.text && <p className="m-0 text-[15px] leading-[1.8] whitespace-pre-line text-body">{card.text}</p>}

                        {card.lists.map((list, listIndex) => (
                            <div key={listIndex} className="flex flex-col gap-2">
                                {list.label && <b className="text-[14px] font-semibold text-ink">{list.label}</b>}
                                <ul className="m-0 flex list-none flex-col gap-[6px] p-0">
                                    {list.items.map((item, itemIndex) => (
                                        <li key={itemIndex} className="flex gap-[10px] text-[15px] leading-[1.65] text-body">
                                            <span
                                                className="mt-[3px] flex size-[18px] flex-none items-center justify-center rounded-full bg-green-50 text-[11px] font-bold text-primary"
                                                aria-hidden="true"
                                            >
                                                ✓
                                            </span>
                                            {item}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}

                        {card.note && (
                            <div className="flex flex-col gap-1 rounded-[12px] border-l-[3px] border-gold-accent bg-surface px-4 py-3">
                                {card.note.label && <b className="text-[13px] font-semibold text-eyebrow">{card.note.label}</b>}
                                <span className="text-[14px] leading-[1.7] whitespace-pre-line text-body">{card.note.text}</span>
                            </div>
                        )}

                        {card.button && (
                            <ButtonLink
                                button={card.button}
                                className="mt-auto self-start rounded-[10px] border border-primary px-[18px] py-[10px] text-[15px] font-semibold text-primary hover:bg-green-50 hover:text-primary-dark"
                            />
                        )}
                    </article>
                ))}
            </div>
        </div>
    );
}
