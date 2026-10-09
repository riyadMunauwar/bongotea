import { usePage } from '@inertiajs/react';
import { ProductGrid } from '@/components/catalogue/product-grid';
import { bn } from '@/lang/bn';
import { formatPhone, toBanglaDigits } from '@/lib/bangla';
import type { ProductCard } from '@/types/catalogue';

type SearchPageProps = {
    query: string;
    products: ProductCard[];
};

/**
 * Every Product matching the buyer's words. Finding nothing still leaves
 * somewhere to go: the hotline, where somebody will pick the right tea.
 */
export default function SearchPage() {
    const { query, products } = usePage<SearchPageProps>().props;
    const { store } = usePage().props;

    return (
        <div className="bg-surface">
            <div className="shell-container flex flex-col gap-5 pt-6 pb-10 md:gap-7 md:pt-10 md:pb-[72px]">
                {query === '' ? (
                    <h1 className="m-0 text-[clamp(20px,2.4vw,28px)] font-semibold text-ink">{bn.search.prompt}</h1>
                ) : products.length > 0 ? (
                    <div className="flex flex-col gap-2">
                        <h1 className="m-0 text-[clamp(20px,2.4vw,28px)] font-semibold text-ink">{bn.search.heading(query)}</h1>
                        <span className="text-[14px] text-muted">{bn.search.count(toBanglaDigits(products.length))}</span>
                    </div>
                ) : (
                    <div className="flex max-w-[560px] flex-col gap-3 rounded-[16px] border border-border bg-white p-6">
                        <h1 className="m-0 text-[clamp(20px,2.4vw,26px)] font-semibold text-ink">{bn.search.none(query)}</h1>
                        <p className="m-0 text-[15px] leading-[1.7] text-muted">{bn.search.noneHint}</p>
                        {store.hotline && (
                            <a
                                href={store.hotline.href}
                                className="self-start rounded-[10px] bg-primary px-5 py-3 text-[16px] font-bold text-white no-underline hover:bg-primary-dark hover:text-white"
                            >
                                {bn.footer.call} {formatPhone(store.hotline.number)}
                            </a>
                        )}
                    </div>
                )}

                {products.length > 0 && <ProductGrid products={products} />}
            </div>
        </div>
    );
}
