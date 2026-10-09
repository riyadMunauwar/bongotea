import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Picture } from '@/components/blocks/shared';
import { OtherTeas } from '@/components/catalogue/other-teas';
import { StrengthBar } from '@/components/catalogue/strength-bar';
import { themeStyle } from '@/components/catalogue/theme';
import { lowestPrice, regularPrice, tierRange } from '@/components/catalogue/tier-ladder';
import { MenuAnchor } from '@/components/storefront/menu-anchor';
import { bn } from '@/lang/bn';
import { formatPhone, toBanglaDigits } from '@/lib/bangla';
import { cn } from '@/lib/utils';
import { useTaka } from '@/lib/use-taka';
import type { ProductCard, ProductDetail } from '@/types/catalogue';

type ProductPageProps = {
    product: ProductDetail;
    others: ProductCard[];
};

/**
 * A Product at its own address: its gallery, what it is like, where it does
 * best, how to brew and sell it, its own Tier ladder, and other teas. Every
 * characteristic the Merchant left blank is simply not drawn.
 */
export default function ProductPage() {
    const { product, others } = usePage<ProductPageProps>().props;
    const { links, store } = usePage().props;
    const taka = useTaka();

    const regular = regularPrice(product.tiers);
    const lowest = lowestPrice(product.tiers);

    const characteristics = (
        [
            { label: bn.product.liquorColour, value: product.liquorColour },
            { label: bn.product.aroma, value: product.aroma },
            { label: bn.product.cups, value: product.cupsPerKilogram },
        ] as { label: string; value: string | null }[]
    ).filter((item): item is { label: string; value: string } => item.value !== null);
    const hasCharacteristics = product.strength !== null || characteristics.length > 0;

    return (
        <div style={themeStyle(product.category.theme)} className="shell-container flex flex-col gap-8 pt-5 pb-10 md:gap-12 md:pt-8 md:pb-[72px]">
            <Link href="/" className="self-start text-[14px] font-semibold text-primary no-underline">
                {bn.product.allTeas}
            </Link>

            <div className="grid items-start gap-6 md:grid-cols-2 md:gap-12">
                <Gallery product={product} />

                <div className="flex flex-col gap-[18px]">
                    <span className="flex flex-wrap items-center gap-2">
                        <Link href={product.category.href} className="rounded-full bg-(--cat-tint) px-[11px] py-[3px] text-[13px] font-semibold text-(--cat-text) no-underline">
                            {product.category.name}
                        </Link>
                        <span className="text-[13px] text-muted">
                            {bn.catalogue.brand}: <b className="font-semibold text-ink">{product.brand.name}</b>
                        </span>
                        {product.badge && <span className="rounded-full bg-eyebrow px-[10px] py-[3px] text-[12px] font-semibold text-white">{product.badge}</span>}
                    </span>

                    <h1 className="m-0 text-[clamp(28px,3.6vw,44px)] leading-[1.25] font-semibold text-ink">{product.name}</h1>

                    {product.shortDescription && <p className="m-0 text-[clamp(16px,1.4vw,19px)] leading-[1.7] text-muted">{product.shortDescription}</p>}

                    {!product.isAvailable && (
                        <span className="self-start rounded-full bg-surface px-3 py-[6px] text-[14px] font-semibold text-muted">{bn.catalogue.unavailable}</span>
                    )}

                    {hasCharacteristics && (
                        <div className="grid grid-cols-2 gap-px overflow-hidden rounded-[12px] border border-border bg-border">
                            {product.strength !== null && (
                                <div className="flex flex-col gap-2 bg-white px-4 py-[14px]">
                                    <span className="text-[13px] text-muted">{bn.catalogue.strength}</span>
                                    <span className="flex items-center gap-2">
                                        <StrengthBar strength={product.strength} size="page" />
                                        <b className="text-[14px] font-semibold text-ink">{bn.catalogue.strengthOutOf(toBanglaDigits(product.strength))}</b>
                                    </span>
                                </div>
                            )}
                            {characteristics.map((item) => (
                                <div key={item.label} className="flex flex-col gap-1 bg-white px-4 py-[14px]">
                                    <span className="text-[13px] text-muted">{item.label}</span>
                                    <b className="text-[15px] font-semibold text-ink">{item.value}</b>
                                </div>
                            ))}
                            {(characteristics.length + (product.strength !== null ? 1 : 0)) % 2 === 1 && <div className="bg-white" />}
                        </div>
                    )}

                    {(product.bestFor.length > 0 || product.notBestFor) && (
                        <div className="flex flex-col gap-[10px]">
                            {product.bestFor.length > 0 && (
                                <>
                                    <span className="text-[15px] font-semibold text-ink">{bn.product.bestFor}</span>
                                    <div className="flex flex-wrap gap-2">
                                        {product.bestFor.map((use) => (
                                            <span key={use} className="rounded-full bg-green-50 px-3 py-[6px] text-[14px] font-medium text-primary-dark">
                                                {use}
                                            </span>
                                        ))}
                                    </div>
                                </>
                            )}
                            {product.notBestFor && <span className="text-[14px] leading-[1.6] text-muted">{product.notBestFor}</span>}
                        </div>
                    )}

                    {product.brewingGuide && (
                        <div className="flex flex-col gap-1 rounded-[12px] bg-surface px-4 py-[14px]">
                            <span className="text-[13px] font-semibold text-eyebrow">{bn.product.brewing}</span>
                            <span className="text-[15px] leading-[1.6] text-ink">{product.brewingGuide}</span>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center justify-between gap-3 pt-[6px]">
                        <span className="text-[15px] text-muted">
                            <b className="text-[24px] font-bold text-primary-dark">
                                {lowest < regular ? `${taka(lowest)}–${taka(regular)}` : taka(regular)}
                            </b>
                            {bn.blocks.perKilogram}
                        </span>
                        {product.isAvailable ? (
                            <MenuAnchor
                                href={links.bulkOrder}
                                className="rounded-[10px] bg-primary px-6 py-[14px] text-[16px] font-semibold text-white no-underline hover:bg-primary-dark hover:text-white"
                            >
                                {bn.product.addToOrder}
                            </MenuAnchor>
                        ) : (
                            store.hotline && (
                                <span className="text-[14px] leading-[1.6] text-muted">
                                    {bn.product.unavailableNote}{' '}
                                    <a href={store.hotline.href} className="font-semibold">
                                        {formatPhone(store.hotline.number)}
                                    </a>
                                </span>
                            )
                        )}
                    </div>
                </div>
            </div>

            <PriceLadder product={product} />

            {product.descriptionHtml && <div className="prose-story max-w-[760px]" dangerouslySetInnerHTML={{ __html: product.descriptionHtml }} />}

            {product.businessTips.length > 0 && (
                <section className="flex flex-col gap-5 border-t border-border pt-8 md:pt-12">
                    <div className="flex flex-col gap-2">
                        <span className="text-[14px] font-semibold text-eyebrow">{bn.product.tipsEyebrow}</span>
                        <h2 className="m-0 text-[clamp(22px,2.8vw,34px)] leading-[1.35] font-semibold text-ink">{bn.product.tipsHeading}</h2>
                    </div>
                    <div className="grid gap-[14px] md:grid-cols-3">
                        {product.businessTips.map((tip, index) => (
                            <div key={index} className="flex flex-col gap-[10px] rounded-[14px] bg-surface p-5">
                                <span className="flex size-8 items-center justify-center rounded-full bg-primary text-[15px] font-bold text-white">{toBanglaDigits(index + 1)}</span>
                                <span className="text-[15px] leading-[1.7] text-ink">{tip}</span>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            <OtherTeas products={others} />
        </div>
    );
}

/** The cover large, and the rest of the gallery as thumbnails that swap into it. */
function Gallery({ product }: { product: ProductDetail }) {
    const [shown, setShown] = useState(0);
    const images = product.gallery;

    return (
        <div className="flex flex-col gap-3">
            <Picture
                src={images[shown] ?? null}
                alt={product.name}
                stripes="stripes-light"
                label={bn.product.picture(product.name)}
                className={cn('flex aspect-square items-end rounded-[16px] p-[14px]', !product.isAvailable && 'grayscale')}
            />
            {images.length > 1 && (
                <div className="flex gap-2 overflow-x-auto">
                    {images.map((url, index) => (
                        <button
                            key={url}
                            type="button"
                            onClick={() => setShown(index)}
                            aria-label={bn.product.showPicture(toBanglaDigits(index + 1))}
                            aria-pressed={index === shown}
                            className={cn(
                                'relative size-16 flex-none cursor-pointer overflow-hidden rounded-[8px] border-2 bg-transparent p-0',
                                index === shown ? 'border-primary' : 'border-transparent',
                            )}
                        >
                            <img src={url} alt="" loading="lazy" className="absolute inset-0 size-full object-cover" />
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

/**
 * The Product's own Tiers as a table: each range, its price, and what it
 * takes off the Regular Price, with a bar that grows as the saving does.
 */
function PriceLadder({ product }: { product: ProductDetail }) {
    const taka = useTaka();
    const regular = regularPrice(product.tiers);
    const maxSaving = regular - lowestPrice(product.tiers);

    if (product.tiers.length < 2) {
        return null;
    }

    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-2">
                <span className="text-[14px] font-semibold text-eyebrow">{bn.product.ladderEyebrow}</span>
                <h2 className="m-0 text-[clamp(20px,2.4vw,28px)] font-semibold text-ink">{bn.product.ladderHeading}</h2>
                <p className="m-0 text-[14px] leading-[1.6] text-muted">{bn.product.ladderNote}</p>
            </div>
            <div className="flex flex-col overflow-hidden rounded-[14px] border border-border">
                {product.tiers.map((tier, index) => {
                    const saving = regular - tier.price;

                    return (
                        <div key={tier.min} className={cn('grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-[6px] px-4 py-3 md:grid-cols-[160px_1fr_auto]', index > 0 && 'border-t border-divider')}>
                            <span className="text-[15px] font-semibold text-ink">{tierRange(tier)}</span>
                            <span className="order-last col-span-2 flex items-center gap-3 md:order-none md:col-span-1">
                                <span className="h-[6px] flex-1 overflow-hidden rounded-full bg-divider">
                                    <span className="block h-full rounded-full bg-gold-accent" style={{ width: maxSaving > 0 ? `${(saving / maxSaving) * 100}%` : '0%' }} />
                                </span>
                                <span className="min-w-[110px] text-[13px] text-muted">{saving > 0 ? bn.catalogue.lessPerKilogram(taka(saving)) : bn.catalogue.regularPrice}</span>
                            </span>
                            <b className="text-right text-[17px] font-bold text-primary-dark">
                                {taka(tier.price)}
                                <span className="text-[13px] font-normal text-muted">{bn.blocks.perKilogram}</span>
                            </b>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
