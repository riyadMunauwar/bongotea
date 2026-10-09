import { bn } from '@/lang/bn';
import type { TextReviewsProps } from '@/types/blocks';
import { initial } from './shared';

/**
 * What shopkeepers say, as cards in a row that swipes below 1024px and sits
 * three across above it.
 */
export function TextReviews({ heading, ratingSummary, note, reviews }: TextReviewsProps) {
    if (reviews.length === 0) {
        return null;
    }

    return (
        <section className="bg-surface">
            <div className="shell-container flex flex-col gap-4 py-10 md:gap-7 md:py-[72px]">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="flex flex-col gap-[6px]">
                        {heading && <h2 className="m-0 text-[clamp(22px,2.4vw,30px)] leading-[1.3] font-bold text-ink">{heading}</h2>}
                        {ratingSummary && (
                            <div className="flex items-center gap-2 text-[14px] text-muted">
                                <span className="tracking-[1px] text-star" aria-hidden="true">
                                    {bn.blocks.stars}
                                </span>
                                <b className="text-ink">{ratingSummary}</b>
                            </div>
                        )}
                    </div>
                    {note && (
                        <span className="text-[14px] text-muted">
                            {note}
                            <b className="font-semibold text-primary lg:hidden">{bn.blocks.swipeMore}</b>
                        </span>
                    )}
                </div>

                <div className="snap-row">
                    {reviews.map((review, index) => (
                        <div key={index} className="box-border flex flex-[0_0_84%] snap-start flex-col gap-3 rounded-[14px] border border-border bg-white p-5 sm:flex-[0_0_46%] lg:flex-[0_0_calc(33.333%-10px)]">
                            <span className="text-[14px] tracking-[1px] text-star" aria-hidden="true">
                                {bn.blocks.stars}
                            </span>
                            <p className="m-0 text-[15px] leading-[1.7] text-pretty text-ink">{review.text}</p>
                            <div className="mt-auto flex items-center gap-[10px] border-t border-divider pt-3">
                                <span className="flex size-9 flex-none items-center justify-center rounded-full bg-green-50 text-[14px] font-bold text-primary">{initial(review.name)}</span>
                                <span className="flex min-w-0 flex-col leading-[1.35]">
                                    <b className="text-[14px] font-semibold text-ink">{review.name}</b>
                                    <span className="text-[12px] text-muted">{[review.place, review.product && bn.blocks.bought(review.product)].filter(Boolean).join(' · ')}</span>
                                </span>
                                {review.verified && <span className="ml-auto rounded-full bg-green-50 px-2 py-[2px] text-[11px] font-semibold whitespace-nowrap text-primary">{bn.blocks.verifiedBuyer}</span>}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}
