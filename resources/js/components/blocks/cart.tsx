import { useEffect } from 'react';
import { bn } from '@/lang/bn';
import { mindCart } from '@/lib/current-cart';
import type { CartBlockProps } from '@/types/blocks';
import { BulkCart } from './bulk-cart';
import { RetailCart } from './retail-cart';
import { SectionHeading } from './shared';

/**
 * One Cart as a page (ADR-0038): its assigned Products by Category with a
 * stepper for every Pack they are sold in that way, what the Contents hold
 * that is not assigned drawn apart above them, and the summary of that
 * Cart's Contents alone. A bulk Cart counts Kilograms, a retail one Packs.
 * A closed Cart says so instead; a Block naming no Cart draws nothing.
 */
export function CartBlock(props: CartBlockProps) {
    const { cart, heading, text } = props;

    /** Standing on a Cart's page makes it the Current Cart of its kind. */
    useEffect(() => {
        if (cart?.isOpen) {
            mindCart(cart.kind, cart.id);
        }
    }, [cart?.id, cart?.kind, cart?.isOpen]);

    if (cart === null) {
        return null;
    }

    if (!cart.isOpen) {
        return (
            <section className="pt-6 pb-10 md:pt-10 md:pb-14">
                <div className="shell-container flex flex-col gap-4 md:gap-6">
                    <SectionHeading heading={heading} text={text} />
                    <div className="flex flex-col items-center gap-3 rounded-[14px] border border-border bg-surface px-5 py-10 text-center">
                        <b className="text-[18px] font-semibold text-ink">{bn.cartPage.closed(cart.name)}</b>
                        <span className="max-w-[420px] text-[15px] leading-[1.6] text-muted">{bn.cartPage.closedHint}</span>
                    </div>
                </div>
            </section>
        );
    }

    return cart.kind === 'bulk' ? <BulkCart {...props} cart={cart} /> : <RetailCart {...props} cart={cart} />;
}
