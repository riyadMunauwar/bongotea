import { ChevronDownIcon } from '@/components/storefront/icons';
import type { FaqProps } from '@/types/blocks';
import { SectionHeading } from './shared';

/**
 * Questions as soft cards that open to their answers. Native `<details>`, so
 * each opens without script and a crawler reads every answer.
 */
export function Faq({ eyebrow, heading, items }: FaqProps) {
    if (items.length === 0) {
        return null;
    }

    return (
        <div className="shell-container flex flex-col gap-6 py-5 md:gap-11 md:py-9">
            <SectionHeading eyebrow={eyebrow} heading={heading} />
            <div className="flex max-w-[860px] flex-col gap-[10px]">
                {items.map((item, index) => (
                    <details key={index} className="group rounded-[14px] border border-border bg-surface open:bg-white">
                        <summary className="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-[18px] text-[17px] font-semibold text-ink [&::-webkit-details-marker]:hidden">
                            {item.question}
                            <ChevronDownIcon size={18} strokeWidth={2} className="flex-none text-muted transition-transform group-open:rotate-180" />
                        </summary>
                        <p className="m-0 px-5 pb-5 text-[15px] leading-[1.75] whitespace-pre-line text-muted">{item.answer}</p>
                    </details>
                ))}
            </div>
        </div>
    );
}
