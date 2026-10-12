import type { ChipsRowProps } from '@/types/blocks';
import { ButtonLink } from './shared';

const CHIP = 'rounded-full border border-chip-line px-5 py-[10px] text-[clamp(15px,1.4vw,18px)] font-medium text-ink';

/** A centred row of outlined pills under a small heading; a linked pill leads on. */
export function ChipsRow({ heading, chips }: ChipsRowProps) {
    if (chips.length === 0) {
        return null;
    }

    return (
        <div className="shell-container flex flex-col items-center gap-[18px] py-5 text-center md:py-9">
            {heading && <span className="text-[14px] font-semibold text-eyebrow">{heading}</span>}
            <div className="flex max-w-[760px] flex-wrap justify-center gap-[10px]">
                {chips.map((chip, index) =>
                    chip.href ? (
                        <ButtonLink
                            key={index}
                            button={{ label: chip.label, href: chip.href }}
                            className={`${CHIP} transition-colors hover:border-primary hover:bg-green-50 hover:text-primary-dark`}
                        />
                    ) : (
                        <span key={index} className={CHIP}>
                            {chip.label}
                        </span>
                    ),
                )}
            </div>
        </div>
    );
}
