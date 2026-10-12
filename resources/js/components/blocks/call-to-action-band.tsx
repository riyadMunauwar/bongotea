import type { CallToActionBandProps } from '@/types/blocks';
import { ButtonLink } from './shared';

/** The dark rounded band asking for the order, its buttons beside the words. */
export function CallToActionBand({ heading, text, primary, secondary, more }: CallToActionBandProps) {
    return (
        <div className="shell-container py-5 md:py-9">
            <div className="flex flex-wrap items-center justify-between gap-5 rounded-[22px] bg-night px-5 py-6 text-white md:px-10 md:py-9">
                <div className="flex max-w-[520px] flex-col gap-[6px]">
                    {heading && <b className="text-[clamp(22px,2.6vw,32px)] leading-[1.35] font-semibold">{heading}</b>}
                    {text && <span className="text-[15px] text-on-dark-sub">{text}</span>}
                </div>
                {(primary || secondary || more.length > 0) && (
                    <div className="flex flex-wrap gap-[10px]">
                        {primary && (
                            <ButtonLink
                                button={primary}
                                className="rounded-[10px] bg-gold-accent px-[22px] py-[13px] text-[15px] font-bold whitespace-nowrap text-night hover:bg-gold-hover hover:text-night"
                            />
                        )}
                        {[secondary, ...more].map((button, index) =>
                            button ? (
                                <ButtonLink
                                    key={index}
                                    button={button}
                                    className="rounded-[10px] border border-white/30 px-5 py-3 text-[15px] font-semibold whitespace-nowrap text-white hover:bg-white/8 hover:text-white"
                                />
                            ) : null,
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
