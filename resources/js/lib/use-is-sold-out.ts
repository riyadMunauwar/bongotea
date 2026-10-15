import { useCart } from './use-cart';
import { useRetailCart } from './use-retail-cart';

/**
 * Whether a Product is স্টক শেষ every way it is sold: counted, with less free
 * to sell than the least anybody could order of it — a Kilogram where it is
 * sold পাইকারি, its lightest orderable Pack where it is sold খুচরা. Worked out from the
 * Carts' props on every draw and never stored, so it never touches Available,
 * and a Product nobody has counted never is.
 */
export function useIsSoldOut(product: { id: number; isSoldBulk: boolean }): boolean {
    const cart = useCart(null);
    const retail = useRetailCart(null);
    const packs = retail.packsOf(product.id).filter((pack) => pack.isOrderable);

    if (!product.isSoldBulk && packs.length === 0) {
        return false;
    }

    return (!product.isSoldBulk || cart.isSoldOut(product.id)) && packs.every((pack) => retail.isSoldOut(pack.id));
}
