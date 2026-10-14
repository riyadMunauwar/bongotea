import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import type { BulkCartLine } from '@/lib/cart';
import { workableKilograms } from '@/lib/grams';
import type { UseCart } from '@/lib/use-cart';
import { cn } from '@/lib/utils';
import { packName } from './figures';

/**
 * A bulk line's Pack and what it makes (ADR-0036): a choice among the tea's
 * পাইকারি Packs when it has more than one, and the packet count as the
 * Kilograms or the Pack change — "২ কেজি = ৮ প্যাকেট × ২৫০ গ্রাম" — or, where
 * the Pack cannot make the Kilograms exactly, the weights that it can. A tea
 * with no পাইকারি Pack on offer says so, since it holds up the Checkout, and
 * so does a line below the tea's পাইকারি minimum or above its maximum.
 */
export function BulkPackLine({ line, cart, className }: { line: BulkCartLine; cart: UseCart; className?: string }) {
    const { product, kilograms, pack, packets } = line;

    if (pack === null) {
        return (
            <span role="alert" className={cn('text-[12px] font-semibold text-eyebrow md:text-[13px]', className)}>
                {bn.cart.noPack}
            </span>
        );
    }

    return (
        <div className={cn('flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px] leading-[1.5] md:text-[13px]', className)}>
            {product.packs.length > 1 && (
                <label className="flex items-center gap-1 text-muted">
                    {bn.cart.packLabel}
                    <select
                        value={pack.id}
                        onChange={(event) => cart.setPack(product.id, Number(event.target.value))}
                        className="cursor-pointer rounded-[6px] border border-input bg-white px-[6px] py-[2px] text-[13px] font-semibold text-ink"
                    >
                        {product.packs.map((option) => (
                            <option key={option.id} value={option.id}>
                                {packName(option.grams)}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            {packets === null ? (
                <span role="alert" className="font-semibold text-eyebrow">
                    {bn.cart.unpackable(
                        packName(pack.grams),
                        toBanglaDigits(kilograms),
                        workableKilograms(kilograms, pack.grams).map((workable) => toBanglaDigits(workable)).join(bn.cart.or),
                    )}
                </span>
            ) : (
                <span className="text-muted">{bn.cart.packetCount(toBanglaDigits(kilograms), toBanglaDigits(packets), packName(pack.grams))}</span>
            )}
            {line.limit !== null && (
                <span role="alert" className="basis-full font-semibold text-eyebrow">
                    {line.limit === 'below'
                        ? bn.cart.belowMinimum(toBanglaDigits(product.minKilograms ?? 0))
                        : bn.cart.aboveMaximum(toBanglaDigits(product.maxKilograms ?? 0))}
                </span>
            )}
        </div>
    );
}
