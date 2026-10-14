import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Picture } from '@/components/blocks/shared';
import { discountLabel } from '@/components/cart/figures';
import { PackChooserButton } from '@/components/cart/pack-chooser';
import { OtherTeas } from '@/components/catalogue/other-teas';
import { RetailFrom } from '@/components/catalogue/product-card';
import { StrengthBar } from '@/components/catalogue/strength-bar';
import { themeStyle } from '@/components/catalogue/theme';
import { firstTierPrice, lowestPrice, tierRange } from '@/components/catalogue/tier-ladder';
import { bn } from '@/lang/bn';
import { formatPhone, toBanglaDigits } from '@/lib/bangla';
import { rowAnchor } from '@/lib/cart';
import { useCart } from '@/lib/use-cart';
import { useMoments } from '@/lib/use-moments';
import { cn } from '@/lib/utils';
import { useTaka } from '@/lib/use-taka';
import type { BulkProductCard, ProductCard, ProductDetail } from '@/types/catalogue';

type ProductPageProps = {
    product: ProductDetail;
    others: ProductCard[];
};

/**
 * A Product at its own address: its gallery, what it is like, where it does
 * best, how to brew, keep and sell it, its own Tier ladder where it is sold
 * পাইকারি, and other teas. A Product sold only খুচরা has no Tiers, so it
 * leads with its cheapest Pack Price and offers its Packs alone (ADR-0036).
 * Its Characteristics are drawn in groups — the basics, the quality, the
 * Merchant's own under অন্যান্য — and then its Storage tips. Every
 * characteristic the Merchant left blank is simply not drawn.
 */
export default function ProductPage() {
    const { product, others } = usePage<ProductPageProps>().props;
    const { links, store } = usePage().props;
    const taka = useTaka();
    const cart = useCart();
    const { productViewed } = useMoments();

    /**
     * Product Viewed, once each time a Product's page is opened — not again as
     * the page redraws. Reported as a Kilogram, so only of a Product sold
     * পাইকারি.
     */
    useEffect(() => {
        if (product.isSoldBulk) {
            productViewed({ id: product.id, mrp: product.mrp, tiers: product.tiers });
        }
    }, [product.id]);

    const basics = filled([
        { label: bn.product.packageWeight, value: product.packageWeight },
        { label: bn.product.teaType, value: product.teaType },
        { label: bn.product.particleSize, value: product.particleSize },
        { label: bn.product.cups, value: product.cupsPerKilogram },
    ]);
    const quality = filled([
        {
            label: bn.product.strength,
            value: product.strength && (
                <span className="flex items-center gap-2">
                    <StrengthBar bars={product.strength.bars} size="page" />
                    <b className="text-[14px] font-semibold text-ink">{product.strength.grade}</b>
                </span>
            ),
        },
        { label: bn.product.grade, value: product.grade },
        { label: bn.product.liquorColour, value: product.liquorColour },
        { label: bn.product.aroma, value: product.aroma },
        { label: bn.product.liquorBody, value: product.liquorBody },
        { label: bn.product.infusion, value: product.infusion },
    ]);

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

                    <CharacteristicGroup heading={bn.product.basicsHeading} characteristics={basics} />
                    <CharacteristicGroup heading={bn.product.qualityHeading} characteristics={quality} />
                    <CharacteristicGroup heading={bn.product.otherCharacteristicsHeading} characteristics={product.customCharacteristics} />

                    {product.storageTips.length > 0 && (
                        <HeadedCard heading={bn.product.storageHeading}>
                            <ul className="m-0 flex list-none flex-col divide-y divide-border overflow-hidden rounded-[12px] border border-border bg-white p-0">
                                {product.storageTips.map((tip, index) => (
                                    <li key={index} className="px-4 py-3 text-[15px] leading-[1.6] text-ink">
                                        <b className="font-semibold">{tip.heading}:</b> <span className="text-muted">{tip.explanation}</span>
                                    </li>
                                ))}
                            </ul>
                        </HeadedCard>
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

                    {product.brewingSteps.length > 0 && (
                        <div className="flex flex-col gap-2 rounded-[12px] bg-surface px-4 py-[14px]">
                            <span className="text-[13px] font-semibold text-eyebrow">{bn.product.brewing}</span>
                            <ol className="m-0 flex list-none flex-col gap-[6px] p-0">
                                {product.brewingSteps.map((step, index) => (
                                    <li key={index} className="flex items-start gap-[10px] text-[15px] leading-[1.6] text-ink">
                                        <span className="mt-[2px] flex size-[22px] flex-none items-center justify-center rounded-full bg-primary text-[12px] font-bold text-white">
                                            {toBanglaDigits(index + 1)}
                                        </span>
                                        {step}
                                    </li>
                                ))}
                            </ol>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center justify-between gap-3 pt-[6px]">
                        {product.isSoldBulk ? <BulkPriceLine product={product} /> : <RetailFrom productId={product.id} />}
                        {product.isAvailable ? (
                            <span className="flex flex-wrap items-center gap-3">
                                <PackChooserButton productId={product.id} productName={product.name} className="w-auto px-6 py-[13px] md:px-6 md:py-[13px] md:text-[16px]" />
                                {product.isSoldBulk && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            cart.set(product.id, Math.max(cart.kilogramsOf(product.id), 1));
                                            router.visit(`${links.bulkOrder}#${rowAnchor(product.id)}`);
                                        }}
                                        className="cursor-pointer rounded-[10px] border-0 bg-primary px-6 py-[14px] text-[16px] font-semibold text-white hover:bg-primary-dark"
                                    >
                                        {bn.cart.add} <span className="font-normal opacity-80">· {bn.cart.addUnit}</span>
                                    </button>
                                )}
                            </span>
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

            {product.isSoldBulk && <PriceLadder product={product} />}

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

type Characteristic = { label: string; value: ReactNode };

/** The Characteristics that have something to say; a blank one is not drawn. */
function filled(characteristics: Characteristic[]): Characteristic[] {
    return characteristics.filter((item) => item.value !== null);
}

/**
 * One group of Characteristics as its own card under its heading, two to a
 * row. A group with nothing in it is not drawn at all, heading included.
 */
function CharacteristicGroup({ heading, characteristics }: { heading: string; characteristics: Characteristic[] }) {
    if (characteristics.length === 0) {
        return null;
    }

    return (
        <HeadedCard heading={heading}>
            <div className="grid grid-cols-2 gap-px overflow-hidden rounded-[12px] border border-border bg-border">
                {characteristics.map((item, index) => (
                    <div key={index} className="flex flex-col gap-1 bg-white px-4 py-[14px]">
                        <span className="text-[13px] text-muted">{item.label}</span>
                        {typeof item.value === 'string' ? <b className="text-[15px] font-semibold text-ink">{item.value}</b> : item.value}
                    </div>
                ))}
                {characteristics.length % 2 === 1 && <div className="bg-white" />}
            </div>
        </HeadedCard>
    );
}

/** A card on the Product's page with its heading above it. */
function HeadedCard({ heading, children }: { heading: string; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-2">
            <h2 className="m-0 text-[15px] font-semibold text-ink">{heading}</h2>
            {children}
        </section>
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

/** The price range a Kilogram of a পাইকারি Product runs over its Tiers, beside its MRP struck through. */
function BulkPriceLine({ product }: { product: BulkProductCard }) {
    const taka = useTaka();
    const first = firstTierPrice(product.tiers);
    const lowest = lowestPrice(product.tiers);

    return (
        <span className="text-[15px] text-muted">
            <b className="text-[24px] font-bold text-primary-dark">{lowest < first ? `${taka(lowest)}–${taka(first)}` : taka(first)}</b>
            {bn.blocks.perKilogram}
            {first < product.mrp && (
                <>
                    {' '}
                    {bn.catalogue.mrp} <s>{taka(product.mrp)}</s> <span className="font-semibold text-primary">{discountLabel(product.mrp, first, taka)}</span>
                </>
            )}
        </span>
    );
}

/**
 * The Product's own Tiers as a table: each range, its price, and what it
 * takes off the MRP, with a bar that grows as the saving does. Not drawn for
 * a flat price at the MRP, which has nothing to compare.
 */
function PriceLadder({ product }: { product: BulkProductCard }) {
    const taka = useTaka();
    const maxSaving = product.mrp - lowestPrice(product.tiers);

    if (product.tiers.length < 2 && maxSaving === 0) {
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
                    const saving = product.mrp - tier.price;

                    return (
                        <div key={tier.min} className={cn('grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-[6px] px-4 py-3 md:grid-cols-[160px_1fr_auto]', index > 0 && 'border-t border-divider')}>
                            <span className="text-[15px] font-semibold text-ink">{tierRange(tier)}</span>
                            <span className="order-last col-span-2 flex items-center gap-3 md:order-none md:col-span-1">
                                <span className="h-[6px] flex-1 overflow-hidden rounded-full bg-divider">
                                    <span className="block h-full rounded-full bg-gold-accent" style={{ width: maxSaving > 0 ? `${(saving / maxSaving) * 100}%` : '0%' }} />
                                </span>
                                <span className="min-w-[110px] text-[13px] text-muted">{discountLabel(product.mrp, tier.price, taka) ?? bn.catalogue.mrp}</span>
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
