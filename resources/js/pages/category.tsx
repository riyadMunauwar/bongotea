import { usePage } from '@inertiajs/react';
import { CategoryHeader } from '@/components/catalogue/category-header';
import { ProductGrid } from '@/components/catalogue/product-grid';
import { themeStyle } from '@/components/catalogue/theme';
import type { CategoryDetail, ProductCard } from '@/types/catalogue';

type CategoryPageProps = {
    category: CategoryDetail;
    products: ProductCard[];
};

/** A Category at its own address: its panel, in its own colours, over all its teas. */
export default function CategoryPage() {
    const { category, products } = usePage<CategoryPageProps>().props;

    return (
        <div className="bg-surface">
            <div className="shell-container pt-5 pb-10 md:pt-8 md:pb-[72px]">
                <div style={themeStyle(category.theme)} className="flex flex-col gap-[14px] rounded-[18px] border border-(--cat-line) bg-(--cat-panel) px-3 py-4 md:gap-6 md:rounded-[24px] md:p-7">
                    <CategoryHeader category={category} />
                    <ProductGrid products={products} />
                </div>
            </div>
        </div>
    );
}
