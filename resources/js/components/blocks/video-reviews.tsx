import { useRef, useState } from 'react';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { cn } from '@/lib/utils';
import type { VideoReviewsProps } from '@/types/blocks';
import { initial, PlayTriangle } from './shared';
import { type Video, VideoDialog } from './video-dialog';

/**
 * Buyers on camera: tall cards in a row that swipes, or steps with the
 * arrows, each playing its video in a modal.
 */
export function VideoReviews({ eyebrow, heading, hint, reviews }: VideoReviewsProps) {
    const track = useRef<HTMLDivElement>(null);
    const [video, setVideo] = useState<Video | null>(null);

    const scroll = (direction: 1 | -1) => track.current?.scrollBy({ left: direction * track.current.clientWidth * 0.8, behavior: 'smooth' });

    if (reviews.length === 0) {
        return null;
    }

    return (
        <section className="bg-white">
            <div className="shell-container flex flex-col gap-4 pt-10 md:gap-7 md:pt-[72px]">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="flex flex-col gap-[6px]">
                        {eyebrow && (
                            <span className="flex items-center gap-2 text-[13px] font-bold text-cart-badge">
                                <span className="block size-2 rounded-full bg-cart-badge" />
                                {eyebrow}
                            </span>
                        )}
                        {heading && <h2 className="m-0 text-[clamp(22px,2.4vw,30px)] leading-[1.3] font-bold text-ink">{heading}</h2>}
                    </div>
                    <div className="flex items-center gap-3">
                        <span className="text-[14px] text-muted">
                            <span className="sm:hidden">{bn.blocks.swipe}</span>
                            {hint && <span className="max-sm:hidden">{hint}</span>}
                        </span>
                        <div className="flex gap-2">
                            <SlideArrow label={bn.blocks.previous} onClick={() => scroll(-1)}>
                                ←
                            </SlideArrow>
                            <SlideArrow label={bn.blocks.next} onClick={() => scroll(1)}>
                                →
                            </SlideArrow>
                        </div>
                    </div>
                </div>

                <div ref={track} className="snap-row">
                    {reviews.map((review, index) => {
                        const title = [review.name, review.place].filter(Boolean).join(' · ');

                        return (
                            <button
                                key={index}
                                type="button"
                                disabled={!review.videoId}
                                onClick={() => review.videoId && setVideo({ id: review.videoId, title })}
                                className={cn(
                                    'relative block aspect-[4/5] flex-[0_0_68%] cursor-pointer snap-start overflow-hidden rounded-2xl border-0 p-0 text-left transition-transform hover:-translate-y-[3px] sm:flex-[0_0_38%] min-[1100px]:flex-[0_0_calc(30%-10px)]',
                                    !review.imageUrl && 'stripes-video',
                                )}
                            >
                                {review.imageUrl && <img src={review.imageUrl} alt="" loading="lazy" className="absolute inset-0 size-full object-cover" />}
                                <span className="absolute top-3 left-3 max-w-[calc(100%-90px)] truncate rounded-[4px] border border-white/30 px-[7px] py-[3px] font-mono text-[11px] font-medium text-white/75">
                                    {bn.blocks.videoTag(toBanglaDigits(index + 1))}
                                </span>
                                {review.duration && <span className="absolute top-3 right-3 rounded-[6px] bg-black/55 px-2 py-[3px] text-[12px] font-semibold text-white">{review.duration}</span>}
                                <span className="absolute top-[42%] left-1/2 flex size-16 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white/95 shadow-play">
                                    <PlayTriangle size="large" />
                                </span>
                                <span className="absolute inset-x-0 bottom-0 flex flex-col gap-2 px-4 pt-12 pb-4 text-white card-shade">
                                    {review.quote && <b className="text-[17px] leading-[1.35] font-bold">“{review.quote}”</b>}
                                    <span className="flex items-center gap-[10px]">
                                        <span className="flex size-8 flex-none items-center justify-center rounded-full bg-gold-accent text-[13px] font-bold text-night">{initial(review.name)}</span>
                                        <span className="flex min-w-0 flex-col leading-[1.35]">
                                            <span className="text-[13px] font-semibold">{review.name}</span>
                                            <span className="truncate text-[11px] text-on-dark-sub">{[review.place, review.product].filter(Boolean).join(' · ')}</span>
                                        </span>
                                    </span>
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>

            <VideoDialog video={video} onClose={() => setVideo(null)} />
        </section>
    );
}

function SlideArrow({ label, onClick, children }: { label: string; onClick: () => void; children: string }) {
    return (
        <button
            type="button"
            aria-label={label}
            onClick={onClick}
            className="flex size-10 cursor-pointer items-center justify-center rounded-full border border-input bg-white text-[18px] text-ink shadow-arrow hover:bg-green-50"
        >
            {children}
        </button>
    );
}
