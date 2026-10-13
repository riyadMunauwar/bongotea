import { useState } from 'react';
import { bn } from '@/lang/bn';
import type { UseCart } from '@/lib/use-cart';

/** "সব মুছুন", which empties the whole bulk Cart only once the buyer says yes; drawn on the price calculator and the bulk order form, and nowhere while the Cart is empty. */
export function ClearAll({ cart }: { cart: UseCart }) {
    const [confirming, setConfirming] = useState(false);

    if (cart.isEmpty) {
        return null;
    }

    if (!confirming) {
        return (
            <button type="button" onClick={() => setConfirming(true)} className="cursor-pointer border-0 bg-transparent px-0 py-[6px] text-[13px] font-semibold text-primary">
                {bn.calculator.clearAll}
            </button>
        );
    }

    return (
        <span role="alertdialog" aria-label={bn.calculator.confirmClear} className="flex flex-wrap items-center justify-end gap-2 text-[13px]">
            <span className="text-ink">{bn.calculator.confirmClear}</span>
            <button
                type="button"
                onClick={() => {
                    cart.clear();
                    setConfirming(false);
                }}
                className="cursor-pointer rounded-[8px] border-0 bg-error px-3 py-[6px] font-semibold text-white"
            >
                {bn.calculator.confirmYes}
            </button>
            <button type="button" onClick={() => setConfirming(false)} className="cursor-pointer rounded-[8px] border border-input bg-white px-3 py-[6px] font-semibold text-ink">
                {bn.calculator.confirmNo}
            </button>
        </span>
    );
}
