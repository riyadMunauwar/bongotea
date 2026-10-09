import { useState } from 'react';
import type { ReactNode } from 'react';
import { CardRow } from '@/components/catalogue/card-row';
import { CategoryHeader } from '@/components/catalogue/category-header';
import { themeStyle } from '@/components/catalogue/theme';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { cn } from '@/lib/utils';
import type { CategoryPanelsProps } from '@/types/blocks';
import { SectionHeading } from './shared';

/**
 * "যে চা দরকার, বেছে নিন": every published Product in a panel per Category,
 * each panel in its Category Theme, narrowed by Tag chips and — once more
 * than one Brand sells — by Brand chips. A panel left with nothing after
 * filtering is not drawn. Each panel can be linked to as `#category-{slug}`.
 */
export function CategoryPanels({ eyebrow, heading, tags, brands, categories }: CategoryPanelsProps) {
    const [tagId, setTagId] = useState<number | null>(null);
    const [brandId, setBrandId] = useState<number | null>(null);

    const filtered = categories
        .map((category) => ({
            ...category,
            products: category.products.filter((product) => (tagId === null || product.tagIds.includes(tagId)) && (brandId === null || product.brand.id === brandId)),
        }))
        .filter((category) => category.products.length > 0);

    const shownCount = filtered.reduce((sum, category) => sum + category.products.length, 0);

    if (categories.length === 0) {
        return null;
    }

    return (
        <section className="bg-surface">
            <div className="shell-container flex flex-col gap-4 py-10 md:gap-5 md:py-[72px]">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <SectionHeading eyebrow={eyebrow} heading={heading} />
                    <span className="text-[14px] text-muted">{bn.blocks.productCount(toBanglaDigits(shownCount))}</span>
                </div>

                {tags.length > 0 && (
                    <ChipRow>
                        <Chip active={tagId === null} onClick={() => setTagId(null)}>
                            {bn.blocks.allTeas}
                        </Chip>
                        {tags.map((tag) => (
                            <Chip key={tag.id} active={tagId === tag.id} onClick={() => setTagId(tag.id)}>
                                {tag.name}
                            </Chip>
                        ))}
                    </ChipRow>
                )}

                {brands.length > 0 && (
                    <ChipRow>
                        <Chip active={brandId === null} onClick={() => setBrandId(null)}>
                            {bn.blocks.allBrands}
                        </Chip>
                        {brands.map((brand) => (
                            <Chip key={brand.id} active={brandId === brand.id} onClick={() => setBrandId(brand.id)}>
                                {brand.name}
                            </Chip>
                        ))}
                    </ChipRow>
                )}

                {filtered.length === 0 && <p className="m-0 py-6 text-center text-[15px] text-muted">{bn.blocks.noMatches}</p>}

                {filtered.map((category) => (
                    <div
                        key={category.id}
                        id={`category-${category.slug}`}
                        style={themeStyle(category.theme)}
                        className="mt-3 flex scroll-mt-[130px] flex-col gap-[14px] rounded-[18px] border border-(--cat-line) bg-(--cat-panel) px-3 py-4 md:mt-5 md:gap-6 md:rounded-[24px] md:p-7 lg:scroll-mt-[170px]"
                    >
                        <CategoryHeader category={category} />
                        <CardRow products={category.products} />
                    </div>
                ))}
            </div>
        </section>
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
