import { toBanglaDigits } from '@/lib/bangla';
import type { JourneyProps } from '@/types/blocks';
import { Picture, SectionHeading } from './shared';

/**
 * The tea's journey as numbered pictures: two across on a phone, four on a
 * wider screen.
 */
export function Journey({ eyebrow, heading, steps }: JourneyProps) {
    if (steps.length === 0) {
        return null;
    }

    return (
        <div className="shell-container flex flex-col gap-6 py-5 md:gap-11 md:py-9">
            <SectionHeading eyebrow={eyebrow} heading={heading} />
            <div className="grid grid-cols-2 gap-x-3 gap-y-5 md:grid-cols-4 md:gap-5">
                {steps.map((step, index) => (
                    <div key={index} className="flex flex-col gap-3">
                        <Picture src={step.imageUrl} alt={step.title} label={step.title} stripes="stripes-light" className="box-border flex aspect-[4/5] items-end rounded-2xl p-[10px]">
                            <span className="absolute top-3 left-3 flex size-9 items-center justify-center rounded-full bg-primary text-[16px] font-bold text-white">{toBanglaDigits(index + 1)}</span>
                        </Picture>
                        <b className="text-[clamp(16px,1.5vw,19px)] font-semibold text-ink">{step.title}</b>
                        {step.text && <span className="-mt-[6px] text-[clamp(13px,1.2vw,15px)] leading-[1.7] text-muted">{step.text}</span>}
                    </div>
                ))}
            </div>
        </div>
    );
}
