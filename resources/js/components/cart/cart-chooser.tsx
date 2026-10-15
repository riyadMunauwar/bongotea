import { useEffect, useRef, useState, type ReactNode } from 'react';
import { bn } from '@/lang/bn';
import type { AddRule } from '@/lib/add-rule';
import type { OpenCart } from '@/types';

type Pending = { carts: OpenCart[]; then: (cart: OpenCart) => void };

/**
 * Follows the add-rule (ADR-0038) for a button outside any Cart's page:
 * straight into the one Cart there is, or — given several — the
 * কোন কার্টে যোগ করবেন? popup, listing only those, in the Merchant's order.
 * `choose` runs `then` with the Cart picked; `chooser` is the popup, drawn
 * by whoever calls it.
 */
export function useCartChoice(): { choose: (rule: AddRule, then: (cart: OpenCart) => void) => void; chooser: ReactNode } {
    const [pending, setPending] = useState<Pending | null>(null);

    const choose = (rule: AddRule, then: (cart: OpenCart) => void) => {
        if (rule.type === 'straight') {
            then(rule.cart);
        } else if (rule.type === 'choose') {
            setPending({ carts: rule.carts, then });
        }
    };

    const chooser = (
        <CartChooser
            carts={pending?.carts ?? null}
            onChoose={(cart) => {
                pending?.then(cart);
                setPending(null);
            }}
            onClose={() => setPending(null)}
        />
    );

    return { choose, chooser };
}

/** The popup itself: each Cart offered as a button. A native `<dialog>`, so Escape closes it. */
function CartChooser({ carts, onChoose, onClose }: { carts: OpenCart[] | null; onChoose: (cart: OpenCart) => void; onClose: () => void }) {
    const dialog = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const element = dialog.current;

        if (!element) {
            return;
        }

        if (carts && !element.open) {
            element.showModal();
        }

        if (!carts && element.open) {
            element.close();
        }
    }, [carts]);

    return (
        <dialog
            ref={dialog}
            aria-label={bn.cartChooser.heading}
            onClose={onClose}
            onClick={(event) => event.target === event.currentTarget && onClose()}
            className="m-auto w-[calc(100%-32px)] max-w-[380px] rounded-[16px] border-0 bg-white p-0 text-ink backdrop:bg-scrim"
        >
            {carts && (
                <div className="flex flex-col gap-4 p-5">
                    <div className="flex items-center justify-between gap-3">
                        <b className="text-[18px] leading-[1.35] font-bold">{bn.cartChooser.heading}</b>
                        <button type="button" onClick={onClose} aria-label={bn.packs.close} className="flex size-9 flex-none cursor-pointer items-center justify-center rounded-full border-0 bg-surface text-[18px] text-ink">
                            ✕
                        </button>
                    </div>
                    <div className="flex flex-col gap-2">
                        {carts.map((cart) => (
                            <button
                                key={cart.id}
                                type="button"
                                onClick={() => onChoose(cart)}
                                className="cursor-pointer rounded-[10px] border border-border bg-white px-4 py-3 text-left text-[16px] font-semibold text-ink hover:border-primary hover:bg-green-50"
                            >
                                {cart.name}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </dialog>
    );
}
