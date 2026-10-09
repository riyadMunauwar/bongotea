import { bn } from '@/lang/bn';
import { parseWholeNumber, toBanglaDigits } from '@/lib/bangla';
import { cn } from '@/lib/utils';

type StepperProps = {
    kilograms: number;
    onChange: (kilograms: number) => void;
    /** A typed quantity between −/+, in Bangla or English digits; otherwise the Kilograms are only shown. */
    editable?: boolean;
    className?: string;
    buttonClassName?: string;
};

/**
 * −/+ around a Product's Kilograms in the Cart. The Cart clamps whatever is
 * asked for to 0–999, so this only says what was pressed or typed; a typed
 * box cleared to nothing means nought.
 */
export function Stepper({ kilograms, onChange, editable = false, className, buttonClassName }: StepperProps) {
    const button = cn('flex flex-none cursor-pointer items-center justify-center border-0 bg-transparent p-0 text-[20px]', buttonClassName);

    return (
        <div className={cn('flex items-center', className)}>
            <button type="button" aria-label={bn.cart.decrease} onClick={() => onChange(kilograms - 1)} className={button}>
                −
            </button>
            {editable ? (
                <label className="flex h-full items-center gap-[2px] border-x border-divider px-[6px]">
                    <input
                        type="text"
                        inputMode="numeric"
                        aria-label={bn.cart.kilograms}
                        value={toBanglaDigits(kilograms)}
                        onChange={(event) => onChange(event.target.value.trim() === '' ? 0 : (parseWholeNumber(event.target.value) ?? kilograms))}
                        onFocus={(event) => event.target.select()}
                        className="w-[38px] border-0 bg-transparent p-0 text-center text-[16px] font-bold text-ink outline-0 md:text-[18px]"
                    />
                    <span className="text-[12px] text-muted max-md:hidden">{bn.cart.kilograms}</span>
                </label>
            ) : (
                <span className="flex-1 text-center font-bold">{bn.cart.kilogramsOf(toBanglaDigits(kilograms))}</span>
            )}
            <button type="button" aria-label={bn.cart.increase} onClick={() => onChange(kilograms + 1)} className={button}>
                +
            </button>
        </div>
    );
}
