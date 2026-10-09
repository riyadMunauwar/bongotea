import { useEffect, useRef, useState } from 'react';
import { bn } from '@/lang/bn';
import { cn } from '@/lib/utils';
import type { HeroCarouselProps } from '@/types/blocks';
import { ButtonLink } from './shared';

/** How long a slide stays before the next, and how far a finger must travel to change it. */
const ADVANCE_MS = 6500;
const SWIPE_PX = 40;

/**
 * The home hero: slides that fade and zoom in, advance on their own, swipe
 * on a phone, and carry dots (and arrows on a wider screen). Choosing a slide
 * restarts the clock. Laid out as a banner, it is the About and Contact
 * pages' dark header instead.
 */
export function HeroCarousel({ layout, slides }: HeroCarouselProps) {
    if (slides.length === 0) {
        return null;
    }

    if (layout === 'banner') {
        return <HeroBanner slide={slides[0]} />;
    }

    return <Carousel slides={slides} />;
}

function Carousel({ slides }: { slides: HeroCarouselProps['slides'] }) {
    const [active, setActive] = useState(0);
    const touchStartX = useRef<number | null>(null);
    const count = slides.length;

    useEffect(() => {
        if (count < 2) {
            return;
        }

        const timer = setTimeout(() => setActive((current) => (current + 1) % count), ADVANCE_MS);

        return () => clearTimeout(timer);
    }, [active, count]);

    const go = (index: number) => setActive((index + count) % count);

    return (
        <section
            onTouchStart={(event) => (touchStartX.current = event.touches[0].clientX)}
            onTouchEnd={(event) => {
                const travelled = event.changedTouches[0].clientX - (touchStartX.current ?? event.changedTouches[0].clientX);

                if (Math.abs(travelled) > SWIPE_PX) {
                    go(active + (travelled < 0 ? 1 : -1));
                }
            }}
            className="relative mx-auto h-[440px] max-w-[1240px] overflow-hidden bg-ink md:mt-5 md:h-[500px] md:rounded-[18px]"
        >
            {slides.map((slide, index) => {
                const isActive = index === active;
                const Heading = index === 0 ? 'h1' : 'h2';

                return (
                    <div
                        key={index}
                        aria-hidden={!isActive}
                        className={cn('absolute inset-0 transition-opacity duration-1000 ease-in-out', isActive ? 'opacity-100' : 'pointer-events-none opacity-0')}
                    >
                        <div className={cn('absolute inset-0 transition-transform duration-[7000ms] ease-out', isActive ? 'scale-100' : 'scale-[1.08]', !slide.imageUrl && 'stripes-hero')}>
                            {slide.imageUrl && <img src={slide.imageUrl} alt="" className="size-full object-cover" />}
                        </div>
                        <div className="absolute inset-0 hero-shade" />
                        <div className="absolute inset-x-0 bottom-0 mx-auto flex max-w-[1240px] flex-col gap-[14px] px-[clamp(16px,4vw,32px)] pb-16 text-white md:pb-20">
                            {slide.eyebrow && <span className="text-[14px] font-medium text-hero-eyebrow">{slide.eyebrow}</span>}
                            <Heading className="m-0 max-w-[760px] text-[clamp(26px,5vw,58px)] leading-[1.3] font-semibold text-balance">{slide.title}</Heading>
                            {slide.text && <p className="m-0 max-w-[540px] text-[clamp(15px,1.4vw,18px)] leading-[1.75] text-pretty text-night-text">{slide.text}</p>}
                            {(slide.primary || slide.secondary) && (
                                <div className="mt-[10px] flex flex-wrap gap-[10px]">
                                    {slide.primary && (
                                        <ButtonLink button={slide.primary} className="rounded-[8px] bg-white px-6 py-[14px] text-[16px] font-semibold text-primary-dark hover:bg-green-50 hover:text-primary-dark" />
                                    )}
                                    {slide.secondary && (
                                        <ButtonLink button={slide.secondary} className="rounded-[8px] border border-white/50 px-[22px] py-[13px] text-[16px] font-medium text-white hover:bg-white/10 hover:text-white" />
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                );
            })}

            {count > 1 && (
                <div className="absolute inset-x-0 bottom-6 mx-auto flex max-w-[1240px] items-center gap-4 px-[clamp(16px,4vw,32px)] text-white">
                    <div className="flex gap-[6px]">
                        {slides.map((_, index) => (
                            <button key={index} type="button" onClick={() => go(index)} aria-label={bn.blocks.slide} aria-current={index === active} className="cursor-pointer border-0 bg-transparent px-0 py-[10px]">
                                <span className={cn('block h-[3px] rounded-[3px] transition-all duration-400', index === active ? 'w-7 bg-white' : 'w-3 bg-white/40')} />
                            </button>
                        ))}
                    </div>
                    <div className="ml-auto hidden gap-2 md:flex">
                        <ArrowButton label={bn.blocks.previous} onClick={() => go(active - 1)}>
                            ←
                        </ArrowButton>
                        <ArrowButton label={bn.blocks.next} onClick={() => go(active + 1)}>
                            →
                        </ArrowButton>
                    </div>
                </div>
            )}
        </section>
    );
}

function ArrowButton({ label, onClick, children }: { label: string; onClick: () => void; children: string }) {
    return (
        <button
            type="button"
            aria-label={label}
            onClick={onClick}
            className="flex size-11 cursor-pointer items-center justify-center rounded-full border border-white/40 bg-transparent text-[18px] text-white hover:bg-white/12"
        >
            {children}
        </button>
    );
}

/** The About and Contact pages' header: one dark band with a gold-ruled eyebrow over the title. */
function HeroBanner({ slide }: { slide: HeroCarouselProps['slides'][number] }) {
    return (
        <section className="relative overflow-hidden bg-night text-white">
            <div className={cn('absolute inset-0', !slide.imageUrl && 'stripes-banner')}>{slide.imageUrl && <img src={slide.imageUrl} alt="" className="size-full object-cover" />}</div>
            <div className="absolute inset-0 banner-shade" />
            <div className="relative shell-container flex flex-col gap-4 py-14 md:py-28">
                {slide.eyebrow && (
                    <span className="flex items-center gap-[10px] text-[13px] font-semibold text-gold-accent">
                        <span className="block h-px w-6 bg-gold-accent" />
                        {slide.eyebrow}
                    </span>
                )}
                <h1 className="m-0 max-w-[720px] text-[clamp(30px,4.6vw,58px)] leading-[1.25] font-semibold text-balance">{slide.title}</h1>
                {slide.text && <p className="m-0 max-w-[560px] text-[clamp(15px,1.3vw,18px)] leading-[1.8] text-on-dark-sub">{slide.text}</p>}
                {(slide.primary || slide.secondary) && (
                    <div className="mt-2 flex flex-wrap gap-[10px]">
                        {slide.primary && (
                            <ButtonLink button={slide.primary} className="rounded-[10px] bg-gold-accent px-[22px] py-[13px] text-[15px] font-bold whitespace-nowrap text-night hover:bg-gold-hover hover:text-night" />
                        )}
                        {slide.secondary && (
                            <ButtonLink button={slide.secondary} className="rounded-[10px] border border-white/30 px-5 py-3 text-[15px] font-semibold whitespace-nowrap text-white hover:bg-white/8 hover:text-white" />
                        )}
                    </div>
                )}
            </div>
        </section>
    );
}
