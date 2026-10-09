import { Link } from '@inertiajs/react';
import { Picture } from '@/components/blocks/shared';
import { bn } from '@/lang/bn';
import { cn } from '@/lib/utils';
import { useTaka } from '@/lib/use-taka';
import type { ProductCard as ProductCardProps } from '@/types/catalogue';
import { StrengthBar } from './strength-bar';
import { themeStyle } from './theme';
import { lowestPrice, regularPrice, TierLadder } from './tier-ladder';

/**
 * A Product as every list draws it: picture and badge, its Category's pill,
 * its name and Brand, strength, Regular Price with the most a Tier takes off
 * it, and its own Tier ladder. An unavailable Product is drawn greyed and
 * says so, because a regular buyer looks for the tea they always buy.
 *
 * Sized by whoever places it: a grid cell, or a slot in a sideways row.
 */
export function ProductCard({ product, className }: { product: ProductCardProps; className?: string }) {
    const taka = useTaka();
    const regular = regularPrice(product.tiers);
    const maxSaving = regular - lowestPrice(product.tiers);

    return (
        <article
            style={themeStyle(product.category.theme)}
            className={cn(
                'flex min-w-0 flex-col overflow-hidden rounded-[14px] border border-border bg-white transition-shadow hover:shadow-card md:rounded-[16px]',
                className,
            )}
        >
            <Link href={product.href} className="block no-underline" tabIndex={-1} aria-hidden="true">
                <Picture
                    src={product.imageUrl}
                    alt=""
                    stripes="stripes-light"
                    label={bn.catalogue.imageOf(product.name)}
                    className={cn('flex aspect-[16/10] items-end p-2 md:aspect-[5/4] md:p-3', !product.isAvailable && 'grayscale')}
                >
                    {product.badge && (
                        <span className="absolute top-2 left-2 rounded-full bg-eyebrow px-[7px] py-[2px] text-[10px] font-semibold text-white md:top-3 md:left-3 md:px-[10px] md:py-1 md:text-[12px]">
                            {product.badge}
                        </span>
                    )}
                </Picture>
            </Link>

            <div className={cn('flex flex-1 flex-col gap-[6px] p-[14px] md:gap-2 md:px-5 md:pt-[18px] md:pb-5', !product.isAvailable && 'opacity-70')}>
                <span className="flex flex-wrap items-center gap-[6px]">
                    <span className="rounded-full bg-(--cat-tint) px-[7px] py-[2px] text-[10px] font-semibold text-(--cat-text) md:px-[9px] md:py-[3px] md:text-[12px]">
                        {product.category.name}
                    </span>
                    <span className="text-[11px] text-muted md:text-[12px]">{product.brand.name}</span>
                </span>

                <Link href={product.href} className="text-[15px] leading-[1.35] font-bold text-ink no-underline hover:text-primary md:text-[19px]">
                    {product.name}
                </Link>

                {product.shortDescription && <p className="m-0 hidden text-[14px] leading-[1.65] text-muted md:block">{product.shortDescription}</p>}

                {product.strength !== null && (
                    <span className="flex items-center gap-[6px] text-[11px] text-muted md:text-[13px]">
                        {bn.catalogue.strength}
                        <StrengthBar strength={product.strength} />
                    </span>
                )}

                <span className="mt-[2px] flex flex-wrap items-baseline gap-[6px] md:mt-1">
                    <b className="text-[17px] font-extrabold text-ink md:text-[24px]">
                        {taka(regular)}
                        {bn.blocks.perKilogram}
                    </b>
                    {maxSaving > 0 && (
                        <span className="rounded-[5px] bg-offer-tint px-[6px] py-px text-[10px] font-bold text-cart-badge md:rounded-[6px] md:px-2 md:py-[2px] md:text-[12px]">
                            <span className="md:hidden">{bn.catalogue.upToOffShort(taka(maxSaving))}</span>
                            <span className="max-md:hidden">{bn.catalogue.upToOff(taka(maxSaving))}</span>
                        </span>
                    )}
                </span>

                {product.tiers.length > 1 && (
                    <div className="mt-1 md:mt-[6px]">
                        <TierLadder tiers={product.tiers} />
                    </div>
                )}

                <div className="mt-auto pt-[6px] md:pt-[10px]">
                    {product.isAvailable ? (
                        <Link
                            href={product.href}
                            className="box-border flex w-full items-center justify-center rounded-[8px] border border-primary px-[6px] py-[10px] text-[13px] font-semibold text-primary no-underline hover:bg-green-50 hover:text-primary-dark md:rounded-[10px] md:p-3 md:text-[15px] md:font-bold"
                        >
                            {bn.catalogue.view}
                        </Link>
                    ) : (
                        <span className="box-border flex w-full items-center justify-center rounded-[8px] bg-surface px-[6px] py-[10px] text-[13px] font-semibold text-muted md:rounded-[10px] md:p-3 md:text-[15px]">
                            {bn.catalogue.unavailable}
                        </span>
                    )}
                </div>
            </div>
        </article>
    );
}
