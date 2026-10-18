import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { bn } from '@/lang/bn';
import { returnPath } from '@/lib/return-path';
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

/** "আরও চা যোগ করুন": back to the page the buyer was taken to the Cart from, or the home page. */
export function AddMoreLink() {
    const back = useReturnPath();

    return (
        <Link href={back} className="self-start py-1 text-[14px] font-semibold text-primary no-underline">
            + {bn.cartPage.addMore}
        </Link>
    );
}

/** Where "আরও চা যোগ করুন" goes; the home page until mounted, because the server has no address bar to read. */
function useReturnPath(): string {
    const [back, setBack] = useState('/');

    useEffect(() => setBack(returnPath(window.location.search)), []);

    return back;
}
