import { bn } from '@/lang/bn';
import type { ProductCard as ProductCardProps } from '@/types/catalogue';
import { ProductCard } from './product-card';

/** Product cards in the mockup's grid: one column on a phone, two on a tablet, three from 1024px. */
export function ProductGrid({ products }: { products: ProductCardProps[] }) {
    if (products.length === 0) {
        return <p className="m-0 py-6 text-center text-[15px] text-muted">{bn.blocks.noMatches}</p>;
    }

    return (
        <div className="grid gap-[10px] sm:grid-cols-2 md:gap-5 lg:grid-cols-3">
            {products.map((product) => (
                <ProductCard key={product.id} product={product} />
            ))}
        </div>
    );
}
