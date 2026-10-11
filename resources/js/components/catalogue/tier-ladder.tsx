import { discountLabel } from '@/components/cart/figures';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { type KilogramRange, type Tier, tierFor } from '@/lib/pricing';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';

/** What a Kilogram costs in the first Tier: the most a Product charges. */
export function firstTierPrice(tiers: Tier[]): number {
    return tiers[0].price;
}

/** What a Kilogram costs in the last, heaviest Tier: the lowest a Product goes. */
export function lowestPrice(tiers: Tier[]): number {
    return tiers[tiers.length - 1].price;
}

/** A range of Cart weights as the storefront writes it: ১–৫ কেজি, ৫১+ কেজি. */
export function tierRange(range: KilogramRange): string {
    return bn.catalogue.tierRange(toBanglaDigits(range.min), range.max === null ? null : toBanglaDigits(range.max));
}

/**
 * A card's price ladder: one cell per Tier of the Product's own, three to a
 * row, each with its range, its price and what it takes off the MRP. Once the
 * Cart holds anything, the Tier its total puts this Product in is filled in
 * and the note says so.
 */
export function TierLadder({ mrp, tiers, cartKilograms = 0 }: { mrp: number; tiers: Tier[]; cartKilograms?: number }) {
    const taka = useTaka();
    const current = cartKilograms > 0 ? tierFor({ id: 0, mrp, tiers }, cartKilograms) : null;

    return (
        <div className="flex flex-col gap-1 md:gap-[6px]">
            <div className="grid grid-cols-3 gap-1">
                {tiers.map((tier) => {
                    const off = discountLabel(mrp, tier.price, taka);
                    const isCurrent = tier === current;

                    return (
                        <div
                            key={tier.min}
                            className={cn(
                                'flex flex-col items-center rounded-[6px] px-[2px] py-1 leading-[1.35] transition-colors md:rounded-[8px] md:px-1 md:py-[6px]',
                                isCurrent ? 'bg-primary shadow-[inset_0_0_0_1px_var(--color-primary)]' : 'bg-surface shadow-[inset_0_0_0_1px_var(--color-divider)]',
                            )}
                        >
                            <span className={cn('text-[10px] whitespace-nowrap md:text-[11px]', isCurrent ? 'text-white/85' : 'text-muted')}>{tierRange(tier)}</span>
                            <b className={cn('text-[13px] font-bold md:text-[14px]', isCurrent ? 'text-white' : 'text-ink')}>{taka(tier.price)}</b>
                            <span className={cn('hidden text-[10px] whitespace-nowrap md:block', isCurrent ? 'text-white/85' : 'text-muted')}>
                                {off ?? bn.catalogue.mrp}
                            </span>
                        </div>
                    );
                })}
            </div>
            <span className="text-[10px] leading-[1.4] text-muted md:text-[12px]">
                {current === null ? bn.catalogue.ladderNote : bn.cart.ladderNow(toBanglaDigits(cartKilograms), tierRange(current))}
            </span>
        </div>
    );
}
