import { Link } from '@inertiajs/react';
import { bn } from '@/lang/bn';
import { cn } from '@/lib/utils';
import type { ProductCard } from '@/types/catalogue';

/** The mockup's "অন্য চা-ও দেখুন": a small picture, the name and a line about each. */
export function OtherTeas({ products }: { products: ProductCard[] }) {
    if (products.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-5">
            <h2 className="m-0 text-[clamp(20px,2.4vw,28px)] font-semibold text-ink">{bn.product.others}</h2>
            <div className="grid gap-3 md:grid-cols-3">
                {products.map((product) => (
                    <Link
                        key={product.id}
                        href={product.href}
                        className={cn('flex items-center gap-[14px] rounded-[12px] border border-border p-3 text-ink no-underline hover:border-primary hover:text-ink', !product.isAvailable && 'opacity-70')}
                    >
                        <span className={cn('relative block size-14 flex-none overflow-hidden rounded-[8px]', !product.imageUrl && 'stripes-light')}>
                            {product.imageUrl && <img src={product.imageUrl} alt="" loading="lazy" className="absolute inset-0 size-full object-cover" />}
                        </span>
                        <span className="flex min-w-0 flex-col gap-[2px]">
                            <b className="text-[16px] font-semibold">{product.name}</b>
                            <span className="truncate text-[13px] text-muted">{product.isAvailable ? product.shortDescription : bn.catalogue.unavailable}</span>
                        </span>
                    </Link>
                ))}
            </div>
        </section>
    );
}
