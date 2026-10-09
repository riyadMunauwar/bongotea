import { useRef } from 'react';
import { bn } from '@/lang/bn';
import type { ProductCard as ProductCardProps } from '@/types/catalogue';
import { ProductCard } from './product-card';

/**
 * Product cards in a row that slides sideways, three to a view from 860px
 * with the mockup's round arrows; on a phone they stack in one column.
 */
export function CardRow({ products }: { products: ProductCardProps[] }) {
    const track = useRef<HTMLDivElement>(null);

    const slide = (direction: 1 | -1) => track.current?.scrollBy({ left: direction * track.current.clientWidth, behavior: 'smooth' });

    return (
        <div className="relative">
            {products.length > 3 && (
                <div className="mb-3 hidden justify-end gap-2 md:flex">
                    <SlideButton label={bn.blocks.previous} onClick={() => slide(-1)}>
                        ←
                    </SlideButton>
                    <SlideButton label={bn.blocks.next} onClick={() => slide(1)}>
                        →
                    </SlideButton>
                </div>
            )}
            <div
                ref={track}
                className="grid gap-[10px] md:-mx-[2px] md:-mt-1 md:flex md:snap-x md:snap-mandatory md:gap-5 md:overflow-x-auto md:scroll-smooth md:px-[2px] md:pt-1 md:pb-2 md:[scrollbar-width:none]"
            >
                {products.map((product) => (
                    <ProductCard key={product.id} product={product} className="md:flex-[0_0_calc((100%-40px)/3)] md:snap-start" />
                ))}
            </div>
        </div>
    );
}

function SlideButton({ label, onClick, children }: { label: string; onClick: () => void; children: string }) {
    return (
        <button
            type="button"
            aria-label={label}
            onClick={onClick}
            className="flex size-10 cursor-pointer items-center justify-center rounded-full border border-input bg-white text-[18px] text-ink shadow-arrow hover:bg-green-50"
        >
            {children}
        </button>
    );
}
