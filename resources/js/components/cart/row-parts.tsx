import { Link } from '@inertiajs/react';
import { bn } from '@/lang/bn';
import { cn } from '@/lib/utils';

/** On to the tea's own page, in the same tab, so Back returns to the Cart. */
export function DetailsLink({ href, className }: { href: string; className?: string }) {
    return (
        <Link href={href} className={cn('text-[12px] font-semibold text-primary underline-offset-2 hover:underline md:text-[13px]', className)}>
            {bn.cartPage.details}
        </Link>
    );
}

/** The heading over what the Contents hold that the Cart does not offer. */
export function ElsewhereHeading() {
    return (
        <div className="flex flex-wrap items-baseline gap-2 px-[2px] pt-[6px]">
            <span className="rounded-full bg-surface px-[10px] py-[3px] text-[13px] font-bold text-ink">{bn.cartPage.addedElsewhere}</span>
            <span className="text-[13px] text-muted">{bn.cartPage.addedElsewhereNote}</span>
        </div>
    );
}
