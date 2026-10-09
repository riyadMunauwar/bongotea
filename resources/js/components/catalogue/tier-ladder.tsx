import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import type { Tier } from '@/lib/pricing';
import { useTaka } from '@/lib/use-taka';

/** What a Kilogram costs in the first Tier: the Regular Price. */
export function regularPrice(tiers: Tier[]): number {
    return tiers[0].price;
}

/** What a Kilogram costs in the last, heaviest Tier: the lowest a Product goes. */
export function lowestPrice(tiers: Tier[]): number {
    return tiers[tiers.length - 1].price;
}

/** A Tier's range as the storefront writes it: ১–৫ কেজি, ৫১+ কেজি. */
export function tierRange(tier: Tier): string {
    return bn.catalogue.tierRange(toBanglaDigits(tier.min), tier.max === null ? null : toBanglaDigits(tier.max));
}

/**
 * A card's price ladder: one cell per Tier of the Product's own, three to a
 * row, each with its range, its price and what it saves on the Regular Price.
 */
export function TierLadder({ tiers }: { tiers: Tier[] }) {
    const taka = useTaka();
    const regular = regularPrice(tiers);

    return (
        <div className="flex flex-col gap-1 md:gap-[6px]">
            <div className="grid grid-cols-3 gap-1">
                {tiers.map((tier) => {
                    const saving = regular - tier.price;

                    return (
                        <div
                            key={tier.min}
                            className="flex flex-col items-center rounded-[6px] bg-surface px-[2px] py-1 leading-[1.35] shadow-[inset_0_0_0_1px_var(--color-divider)] md:rounded-[8px] md:px-1 md:py-[6px]"
                        >
                            <span className="text-[10px] whitespace-nowrap text-muted md:text-[11px]">{tierRange(tier)}</span>
                            <b className="text-[13px] font-bold text-ink md:text-[14px]">{taka(tier.price)}</b>
                            <span className="hidden text-[10px] whitespace-nowrap text-muted md:block">
                                {saving > 0 ? bn.catalogue.offPerKilogram(taka(saving)) : bn.catalogue.regular}
                            </span>
                        </div>
                    );
                })}
            </div>
            <span className="text-[10px] leading-[1.4] text-muted md:text-[12px]">{bn.catalogue.ladderNote}</span>
        </div>
    );
}
