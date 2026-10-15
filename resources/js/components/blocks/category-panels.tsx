import { useState } from 'react';
import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ProductCard } from '@/components/catalogue/product-card';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { useTaka } from '@/lib/use-taka';
import { cn } from '@/lib/utils';
import type { CategoryPanelsProps } from '@/types/blocks';
import { SectionHeading } from './shared';

type PanelCategory = CategoryPanelsProps['categories'][number];

const GRID: Record<CategoryPanelsProps['columns'], string> = {
    3: 'lg:grid-cols-3',
    4: 'lg:grid-cols-4',
    5: 'lg:grid-cols-5',
};

/**
 * "যে চা দরকার, বেছে নিন": every published Product in a full-width band per
 * Category, the bands white and light grey by turns, narrowed by Tag chips and — once
 * more than one Brand sells — by Brand chips. A band shows its first batch of
 * cards and a "load more" for the next while any are left; choosing a chip
 * starts every band from its first batch again. A band left with nothing
 * after filtering is not drawn. Each band can be linked to as
 * `#category-{slug}`.
 */
export function CategoryPanels({ eyebrow, heading, tags, brands, columns, batchSize, categories }: CategoryPanelsProps) {
    const [tagId, setTagId] = useState<number | null>(null);
    const [brandId, setBrandId] = useState<number | null>(null);
    /** How many cards each band shows, by Category id, once "load more" has been pressed there. */
    const [shown, setShown] = useState<Record<number, number>>({});

    const filtered = categories
        .map((category) => ({
            ...category,
            products: category.products.filter((product) => (tagId === null || product.tagIds.includes(tagId)) && (brandId === null || product.brand.id === brandId)),
        }))
        .filter((category) => category.products.length > 0);

    const shownCount = filtered.reduce((sum, category) => sum + category.products.length, 0);

    const chooseTag = (id: number | null) => {
        setTagId(id);
        setShown({});
    };

    const chooseBrand = (id: number | null) => {
        setBrandId(id);
        setShown({});
    };

    if (categories.length === 0) {
        return null;
    }

    return (
        <section className="bg-white">
            <div className="border-y border-green-200/60 bg-green-50">
                <div className="shell-container flex flex-col gap-4 py-8 md:gap-5 md:py-12">
                    <div className="flex flex-wrap items-end justify-between gap-4">
                        <SectionHeading eyebrow={eyebrow} heading={heading} />
                        <span className="text-[14px] text-muted">{bn.blocks.productCount(toBanglaDigits(shownCount))}</span>
                    </div>

                    {tags.length > 0 && (
                        <ChipRow>
                            <Chip active={tagId === null} onClick={() => chooseTag(null)}>
                                {bn.blocks.allTeas}
                            </Chip>
                            {tags.map((tag) => (
                                <Chip key={tag.id} active={tagId === tag.id} onClick={() => chooseTag(tag.id)}>
                                    {tag.name}
                                </Chip>
                            ))}
                        </ChipRow>
                    )}

                    {brands.length > 0 && (
                        <ChipRow>
                            <Chip active={brandId === null} onClick={() => chooseBrand(null)}>
                                {bn.blocks.allBrands}
                            </Chip>
                            {brands.map((brand) => (
                                <Chip key={brand.id} active={brandId === brand.id} onClick={() => chooseBrand(brand.id)}>
                                    {brand.name}
                                </Chip>
                            ))}
                        </ChipRow>
                    )}

                    {filtered.length === 0 && <p className="m-0 py-6 text-center text-[15px] text-muted">{bn.blocks.noMatches}</p>}
                </div>
            </div>

            {filtered.map((category, index) => {
                const count = shown[category.id] ?? batchSize;
                const remaining = category.products.length - count;

                return (
                    <div
                        key={category.id}
                        id={`category-${category.slug}`}
                        className={cn('scroll-mt-[130px] py-7 md:py-10 lg:scroll-mt-[170px]', index % 2 === 0 ? 'bg-surface' : 'bg-white')}
                    >
                        <div className="shell-container flex flex-col gap-4 md:gap-5">
                            <BandHeader category={category} />
                            <div className={cn('grid grid-cols-2 gap-2 md:grid-cols-3 md:gap-3', GRID[columns])}>
                                {category.products.slice(0, count).map((product) => (
                                    <ProductCard key={product.id} product={product} />
                                ))}
                            </div>
                            {remaining > 0 && (
                                <button
                                    type="button"
                                    onClick={() => setShown((current) => ({ ...current, [category.id]: count + batchSize }))}
                                    className="mx-auto cursor-pointer rounded-full border border-primary bg-white px-6 py-[9px] text-[14px] font-semibold text-primary-dark hover:bg-green-50"
                                >
                                    {bn.blocks.loadMore(toBanglaDigits(remaining))}
                                </button>
                            )}
                        </div>
                    </div>
                );
            })}
        </section>
    );
}

/** The top of a band: its name, how many teas and what they cost, its quality headline, and the way to its own page. */
function BandHeader({ category }: { category: PanelCategory }) {
    const taka = useTaka();
    const range = category.priceRange;

    return (
        <div className="flex items-end justify-between gap-4 border-b border-border pb-3">
            <div className="flex min-w-0 flex-col gap-1">
                <h3 className="m-0 flex flex-wrap items-baseline gap-x-[10px] text-[18px] leading-[1.3] font-bold text-ink md:text-[21px]">
                    {category.name}
                    <span className="text-[12px] font-normal text-muted md:text-[13px]">
                        {bn.blocks.productCount(toBanglaDigits(category.productCount))}
                        {range && ` · ${taka(range.low)}–${taka(range.high).slice(1)}${bn.blocks.perKilogram}`}
                    </span>
                </h3>
                {category.qualityHeadline && <p className="m-0 text-[13px] leading-[1.5] text-body md:text-[14px]">{category.qualityHeadline}</p>}
            </div>
            <Link href={category.href} className="flex-none text-[13px] font-semibold whitespace-nowrap text-primary-dark no-underline hover:underline md:text-[14px]">
                {bn.blocks.seeCategory}
            </Link>
        </div>
    );
}

function ChipRow({ children }: { children: ReactNode }) {
    return <div className="snap-row !gap-2 py-[2px]">{children}</div>;
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={cn(
                'flex-none cursor-pointer snap-start rounded-full border px-4 py-[9px] text-[14px] font-semibold whitespace-nowrap',
                active ? 'border-ink bg-ink text-white' : 'border-border bg-white text-ink',
            )}
        >
            {children}
        </button>
    );
}
