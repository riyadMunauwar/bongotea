import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import type { BlockPropsByType, PageBlock } from '@/types/blocks';
import { BulkOrderForm } from './bulk-order-form';
import { CallToActionBand } from './call-to-action-band';
import { CategoryPanels } from './category-panels';
import { CategoryTiles } from './category-tiles';
import { ChipsRow } from './chips-row';
import { ContactCards } from './contact-cards';
import { DeliveryChart } from './delivery-chart';
import { Faq } from './faq';
import { HeroCarousel } from './hero-carousel';
import { HowToOrder } from './how-to-order';
import { Journey } from './journey';
import { MapAndAddress } from './map-and-address';
import { PriceCalculator } from './price-calculator';
import { PriceMatrix } from './price-matrix';
import { ProductRail } from './product-rail';
import { PromiseStrip } from './promise-strip';
import { Story } from './story';
import { TextReviews } from './text-reviews';
import { ValueCards } from './value-cards';
import { VideoReviews } from './video-reviews';

/** Each Block kind's component, under the name its PHP definition stores it by. */
const COMPONENTS: { [Type in keyof BlockPropsByType]: ComponentType<BlockPropsByType[Type]> } = {
    hero_carousel: HeroCarousel,
    promise_strip: PromiseStrip,
    how_to_order: HowToOrder,
    video_reviews: VideoReviews,
    text_reviews: TextReviews,
    story: Story,
    journey: Journey,
    value_cards: ValueCards,
    chips_row: ChipsRow,
    cta_band: CallToActionBand,
    contact_cards: ContactCards,
    map: MapAndAddress,
    faq: Faq,
    category_tiles: CategoryTiles,
    category_panels: CategoryPanels,
    product_rail: ProductRail,
    bulk_order_form: BulkOrderForm,
    price_calculator: PriceCalculator,
    price_matrix: PriceMatrix,
    delivery_chart: DeliveryChart,
};

/**
 * A Page's Blocks top to bottom. Each sits in a frame carrying its anchor —
 * offset so the sticky header never covers what a Menu link scrolls to — and
 * its phone/wider-screen switches, at the mockup's 860px mobile break.
 */
export function Blocks({ blocks }: { blocks: PageBlock[] }) {
    return blocks.map((block, index) => {
        const Component = COMPONENTS[block.type] as ComponentType<typeof block.props> | undefined;

        if (!Component) {
            return null;
        }

        return (
            <div
                key={index}
                id={block.anchor ?? undefined}
                className={cn(block.anchor && 'scroll-mt-[130px] lg:scroll-mt-[170px]', block.hideOnMobile && 'max-md:hidden', block.hideOnDesktop && 'md:hidden')}
            >
                <Component {...block.props} />
            </div>
        );
    });
}
