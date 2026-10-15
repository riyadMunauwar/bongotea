import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import type { BulkCartLine } from '@/lib/cart';
import { wholeKilogramsIn, workableKilograms } from '@/lib/grams';
import { cn } from '@/lib/utils';
import { packName } from './figures';

/**
 * What a bulk line's Pack makes (ADR-0036): the packet count as the Kilograms
 * change — "২ কেজি = ৮ প্যাকেট × ২৫০ গ্রাম" — or, where the Pack cannot make
 * the Kilograms exactly, the weights that it can. A tea with no পাইকারি Pack
 * on offer says so, since it holds up the Checkout. Unless a tea in several
 * Packs says them once elsewhere, so does its whole weight being below its
 * পাইকারি minimum or above its maximum, or more than is free to sell — with
 * the whole Kilograms that are, or স্টক শেষ where not one is.
 */
export function BulkPackLine({ line, showTeaNotices = true, className }: { line: BulkCartLine; showTeaNotices?: boolean; className?: string }) {
    const { kilograms, pack, packets } = line;

    if (pack === null) {
        return (
            <span role="alert" className={cn('text-[12px] font-semibold text-eyebrow md:text-[13px]', className)}>
                {bn.cart.noPack}
            </span>
        );
    }

    return (
        <div className={cn('flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px] leading-[1.5] md:text-[13px]', className)}>
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
            {showTeaNotices && <TeaNotices line={line} />}
        </div>
    );
}

/** What holds a tea up across all its Packs: its whole weight outside its পাইকারি Order Limit, or more than is free to sell. */
export function TeaNotices({ line, className }: { line: BulkCartLine; className?: string }) {
    const { product } = line;

    return (
        <>
            {line.limit !== null && (
                <span role="alert" className={cn('basis-full font-semibold text-eyebrow', className)}>
                    {line.limit === 'below'
                        ? bn.cart.belowMinimum(toBanglaDigits(product.minKilograms ?? 0))
                        : bn.cart.aboveMaximum(toBanglaDigits(product.maxKilograms ?? 0))}
                </span>
            )}
            {line.isShort && <ShortNotice freeGrams={product.freeGrams ?? 0} className={className} />}
        </>
    );
}

function ShortNotice({ freeGrams, className }: { freeGrams: number; className?: string }) {
    const free = wholeKilogramsIn(freeGrams);

    return (
        <span role="alert" className={cn('basis-full font-semibold text-eyebrow', className)}>
            {free > 0 ? bn.cart.short(toBanglaDigits(free)) : bn.cart.soldOutLine}
        </span>
    );
}
