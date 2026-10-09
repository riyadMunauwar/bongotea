import { CardRow } from '@/components/catalogue/card-row';
import type { ProductRailProps } from '@/types/blocks';
import { ButtonLink, SectionHeading } from './shared';

/** A row of product cards the Merchant chose by Category, Tag, Brand or by hand. */
export function ProductRail({ eyebrow, heading, link, products }: ProductRailProps) {
    if (products.length === 0) {
        return null;
    }

    return (
        <section className="shell-container flex flex-col gap-5 py-10 md:py-[72px]">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <SectionHeading eyebrow={eyebrow} heading={heading} />
                {link && <ButtonLink button={link} className="text-[14px] font-semibold text-primary" />}
            </div>
            <CardRow products={products} />
        </section>
    );
}
