import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { useTaka } from '@/lib/use-taka';
import type { CategoryDetail } from '@/types/catalogue';

/**
 * The top of a Category's panel, as the mockup draws it: its pill, how many
 * teas and what they cost, its quality headline and what it says about
 * itself, and its "why" points ticked. Drawn inside an element wearing the
 * Category's theme.
 */
export function CategoryHeader({ category, as: Heading = 'h3' }: { category: CategoryDetail; as?: 'h1' | 'h3' }) {
    const taka = useTaka();
    const range = category.priceRange;

    return (
        <div className="grid gap-3 border-b border-(--cat-line) px-[6px] pt-[6px] pb-4 md:grid-cols-2 md:gap-8 md:px-2 md:pt-2 md:pb-6">
            <div className="flex flex-col gap-[6px] md:gap-[10px]">
                <div className="flex flex-wrap items-center gap-[10px]">
                    <span className="rounded-full bg-(--cat-pill-bg) px-[11px] py-[3px] text-[13px] font-bold text-(--cat-pill-text)">{category.name}</span>
                    <span className="text-[13px] text-(--cat-panel-subtext)">
                        {bn.blocks.productCount(toBanglaDigits(category.productCount))}
                        {range && ` · ${taka(range.low)}–${taka(range.high).slice(1)}${bn.blocks.perKilogram}`}
                    </span>
                </div>
                {category.qualityHeadline && (
                    <Heading className="m-0 text-[21px] leading-[1.35] font-semibold text-(--cat-panel-text) md:text-[28px]">{category.qualityHeadline}</Heading>
                )}
                {category.about && <p className="m-0 text-[14px] leading-[1.75] text-pretty text-(--cat-panel-subtext) md:text-[15px]">{category.about}</p>}
            </div>
            {category.whyPoints.length > 0 && (
                <div className="flex flex-col justify-center gap-[6px] md:gap-[10px]">
                    <span className="text-[13px] font-bold text-(--cat-accent)">{bn.blocks.why(category.name)}</span>
                    {category.whyPoints.map((point, index) => (
                        <div key={index} className="flex items-center gap-[10px] text-[14px] font-medium text-(--cat-panel-text) md:text-[15px]">
                            <span className="flex size-5 flex-none items-center justify-center rounded-full bg-(--cat-tick-bg) text-[12px] font-bold text-(--cat-accent)">✓</span>
                            {point}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
