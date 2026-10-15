import { Link } from '@inertiajs/react';
import { discountPercent } from '@/components/cart/figures';
import { lowestPrice, tierRange } from '@/components/catalogue/tier-ladder';
import { themeStyle } from '@/components/catalogue/theme';
import { bn } from '@/lang/bn';
import { tierFor } from '@/lib/pricing';
import { useCart } from '@/lib/use-cart';
import { useCurrentCarts } from '@/lib/use-current-carts';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';
import type { PriceMatrixProps } from '@/types/blocks';
import { SectionHeading } from './shared';

/**
 * "কোন চায়ে কত ছাড়": every published Product by Category against its own
 * Tiers. Each Product owns its ranges (ADR-0031), so a row is that Product's
 * ladder, each cell naming its own range; once the Cart holds anything, the
 * Tier its total puts each Product in is highlighted. The MRP is named once
 * per row, in a column of its own, so each cell need only say what share of
 * it the Tier takes off. Scrolls sideways on a narrow screen with the names
 * held in place.
 */
export function PriceMatrix({ heading, text, categories }: PriceMatrixProps) {
    const taka = useTaka();
    const cart = useCart(useCurrentCarts().bulk?.id ?? null);
    const total = cart.priced.totalKilograms;
    const columns = Math.max(1, ...categories.flatMap((category) => category.products.map((product) => product.tiers.length)));
    const gridTemplateColumns = `var(--matrix-name) minmax(88px, auto) repeat(${columns}, minmax(104px, 1fr))`;

    if (categories.length === 0) {
        return null;
    }

    return (
        <section className="shell-container flex flex-col gap-[14px] py-10 md:py-[72px]">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <SectionHeading heading={heading} text={text} />
                <span className="text-[12px] text-muted">{bn.matrix.swipe}</span>
            </div>
            <div className="overflow-x-auto rounded-[16px] border border-border [--matrix-name:136px] [scrollbar-width:thin] md:[--matrix-name:200px]">
                <div className="flex min-w-max flex-col">
                    <div className="grid bg-surface text-[12px] font-semibold text-muted" style={{ gridTemplateColumns }}>
                        <span className="sticky left-0 bg-surface px-4 py-3">{bn.matrix.tea}</span>
                        <span className="px-[10px] py-3 text-center">{bn.catalogue.mrp}</span>
                        <span className="px-[10px] py-3" style={{ gridColumn: `span ${columns}` }}>
                            {bn.matrix.ladder}
                        </span>
                    </div>
                    {categories.map((category) => (
                        <div key={category.id} style={themeStyle(category.theme)}>
                            <div className="border-t border-border bg-(--cat-tint)">
                                <div className="sticky left-0 w-max px-4 py-2 text-[13px] font-bold text-(--cat-text)">
                                    {category.name}
                                    {category.note && <span className="font-medium"> · {category.note}</span>}
                                </div>
                            </div>
                            {category.products.map((product) => {
                                const current = total > 0 ? tierFor(product, total) : null;
                                const maxSaving = product.mrp - lowestPrice(product.tiers);

                                return (
                                    <div key={product.id} className="grid border-t border-divider" style={{ gridTemplateColumns }}>
                                        <Link
                                            href={product.href}
                                            className="sticky left-0 flex flex-col gap-[2px] bg-white px-4 py-3 no-underline shadow-[1px_0_0_var(--color-divider)]"
                                        >
                                            <b className="text-[15px] font-semibold text-ink">{product.name}</b>
                                            <span className="text-[11px] text-muted">{maxSaving > 0 ? bn.catalogue.upToOff(taka(maxSaving)) : bn.matrix.flat}</span>
                                        </Link>
                                        <span className="flex items-center justify-center bg-white px-2 py-[10px] text-[14px] text-muted shadow-[inset_0_-2px_0_var(--color-divider)]">
                                            {taka(product.mrp)}
                                        </span>
                                        {product.tiers.map((tier) => (
                                            <div
                                                key={tier.min}
                                                className={cn(
                                                    'flex flex-col items-center justify-center gap-[3px] px-2 py-[10px] transition-colors',
                                                    tier === current ? 'bg-green-50 shadow-[inset_0_-2px_0_var(--color-primary)]' : 'bg-white shadow-[inset_0_-2px_0_var(--color-divider)]',
                                                )}
                                            >
                                                <span className="text-[11px] whitespace-nowrap text-muted">{tierRange(tier)}</span>
                                                <b className="text-[16px] font-bold text-ink">{taka(tier.price)}</b>
                                                <span className="text-[11px] font-semibold whitespace-nowrap text-(--cat-text)">
                                                    {bn.matrix.percentOff(discountPercent(product.mrp, tier.price))}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                );
                            })}
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
