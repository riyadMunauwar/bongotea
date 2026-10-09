import { usePage } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import { useState } from 'react';
import { bn } from '@/lang/bn';
import { formatPhone, toBanglaDigits } from '@/lib/bangla';
import type { HowToOrderProps } from '@/types/blocks';
import { ButtonLink, PlayTriangle, SectionHeading } from './shared';
import { type Video, VideoDialog } from './video-dialog';

/**
 * How to order: numbered steps — side by side and joined by a dashed line
 * on a wider screen, stacked down a dashed spine on a phone — then a button
 * to start, the how-to video in a modal, and the hotline.
 */
export function HowToOrder({ eyebrow, heading, text, steps, cta, videoId, showHotline }: HowToOrderProps) {
    const { store } = usePage().props;
    const [video, setVideo] = useState<Video | null>(null);

    /** The dashed line runs from the centre of the first circle to the centre of the last. */
    const line: CSSProperties = { insetInline: `${50 / Math.max(steps.length, 1)}%` };

    return (
        <section className="bg-white">
            <div className="shell-container flex flex-col gap-6 py-10 md:gap-11 md:py-[72px]">
                <SectionHeading eyebrow={eyebrow} heading={heading} text={text} className="items-start text-left md:items-center md:text-center" />

                {steps.length > 0 && (
                    <>
                        <div className="relative hidden gap-[clamp(12px,2vw,24px)] md:grid" style={{ gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))` }}>
                            {steps.length > 1 && <span className="absolute top-9 block border-t-2 border-dashed border-step-line" style={line} />}
                            {steps.map((step, index) => (
                                <div key={index} className="relative flex flex-col items-center gap-4 text-center">
                                    <span className="flex size-[72px] items-center justify-center rounded-full border-2 border-primary bg-white ring-8 ring-white">
                                        <span className="flex size-[54px] items-center justify-center rounded-full bg-primary text-[24px] font-bold text-white">{toBanglaDigits(index + 1)}</span>
                                    </span>
                                    <div className="box-border flex w-full flex-1 flex-col items-center gap-[10px] rounded-2xl bg-surface px-[22px] pt-6 pb-[26px]">
                                        <b className="text-[19px] font-semibold text-ink">{step.title}</b>
                                        {step.text && <span className="text-[15px] leading-[1.75] text-pretty text-muted">{step.text}</span>}
                                        {step.chip && <StepChip>{step.chip}</StepChip>}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <div className="flex flex-col md:hidden">
                            {steps.map((step, index) => (
                                <div key={index} className="flex gap-[14px]">
                                    <div className="flex flex-col items-center">
                                        <span className="flex size-11 flex-none items-center justify-center rounded-full bg-primary text-[18px] font-bold text-white">{toBanglaDigits(index + 1)}</span>
                                        {index < steps.length - 1 && <span className="my-[6px] block flex-1 border-l-2 border-dashed border-step-line" />}
                                    </div>
                                    <div className="mb-[14px] flex flex-1 flex-col items-start gap-[6px] rounded-[14px] bg-surface p-4">
                                        <b className="text-[17px] font-semibold text-ink">{step.title}</b>
                                        {step.text && <span className="text-[14px] leading-[1.7] text-muted">{step.text}</span>}
                                        {step.chip && <StepChip compact>{step.chip}</StepChip>}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </>
                )}

                {(cta || videoId || (showHotline && store.hotline)) && (
                    <div className="flex flex-col items-center gap-3 text-center">
                        <div className="flex flex-wrap justify-center gap-[10px]">
                            {cta && <ButtonLink button={cta} className="rounded-[10px] bg-primary px-[30px] py-[15px] text-[17px] font-semibold text-white hover:bg-primary-dark hover:text-white" />}
                            {videoId && (
                                <button
                                    type="button"
                                    onClick={() => setVideo({ id: videoId, title: bn.blocks.howToVideoTitle })}
                                    className="flex cursor-pointer items-center gap-[10px] rounded-[10px] border border-primary bg-transparent py-[10px] pr-[22px] pl-[10px] text-[17px] font-semibold text-primary-dark hover:bg-green-50"
                                >
                                    <span className="flex size-[34px] items-center justify-center rounded-full bg-eyebrow">
                                        <PlayTriangle />
                                    </span>
                                    {bn.blocks.watchHowTo}
                                </button>
                            )}
                        </div>
                        {showHotline && store.hotline && (
                            <span className="text-[14px] text-muted">
                                {bn.blocks.phoneHelp}{' '}
                                <a href={store.hotline.href} className="font-semibold text-primary no-underline">
                                    {formatPhone(store.hotline.number)}
                                </a>
                            </span>
                        )}
                    </div>
                )}
            </div>

            <VideoDialog video={video} onClose={() => setVideo(null)} />
        </section>
    );
}

function StepChip({ compact = false, children }: { compact?: boolean; children: string }) {
    return (
        <span className={compact ? 'mt-1 rounded-full border border-step-chip-line bg-white px-[10px] py-1 text-[12px] font-semibold text-primary-dark' : 'mt-[6px] rounded-full border border-step-chip-line bg-white px-3 py-[5px] text-[13px] font-semibold text-primary-dark'}>
            {children}
        </span>
    );
}
