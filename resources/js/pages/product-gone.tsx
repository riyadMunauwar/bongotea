import { Link, usePage } from '@inertiajs/react';
import { OtherTeas } from '@/components/catalogue/other-teas';
import { bn } from '@/lang/bn';
import { formatPhone } from '@/lib/bangla';
import type { ProductCard } from '@/types/catalogue';

type ProductGoneProps = {
    product: { name: string };
    others: ProductCard[];
};

/**
 * Where an old link to a Product taken out of the catalogue lands: what
 * happened to it, the hotline, and other teas to go on to — never an error.
 */
export default function ProductGone() {
    const { product, others } = usePage<ProductGoneProps>().props;
    const { store } = usePage().props;

    return (
        <div className="shell-container flex flex-col gap-10 pt-8 pb-10 md:gap-14 md:pt-14 md:pb-[72px]">
            <div className="flex max-w-[640px] flex-col gap-4">
                <h1 className="m-0 text-[clamp(26px,3.4vw,40px)] leading-[1.3] font-semibold text-ink">{bn.productGone.heading}</h1>
                <p className="m-0 text-[16px] leading-[1.7] text-muted">{bn.productGone.text(product.name)}</p>
                <div className="flex flex-wrap items-center gap-3">
                    <Link href="/" className="rounded-[10px] bg-primary px-5 py-3 text-[15px] font-semibold text-white no-underline hover:bg-primary-dark hover:text-white">
                        {bn.productGone.browse}
                    </Link>
                    {store.hotline && (
                        <a href={store.hotline.href} className="text-[15px] font-semibold">
                            {bn.footer.call} {formatPhone(store.hotline.number)}
                        </a>
                    )}
                </div>
            </div>
            <OtherTeas products={others} />
        </div>
    );
}
