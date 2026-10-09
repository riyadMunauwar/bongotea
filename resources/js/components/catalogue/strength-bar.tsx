import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { cn } from '@/lib/utils';

const LEVELS = [1, 2, 3, 4, 5];

/** The mockup's five short bars, as many filled as the tea is strong. */
export function StrengthBar({ strength, size = 'card', className }: { strength: number; size?: 'card' | 'page'; className?: string }) {
    return (
        <span className={cn('flex', size === 'page' ? 'gap-[3px]' : 'gap-[2px]', className)} role="img" aria-label={`${bn.catalogue.strength} ${bn.catalogue.strengthOutOf(toBanglaDigits(strength))}`}>
            {LEVELS.map((level) => (
                <span
                    key={level}
                    className={cn(
                        'block rounded-[2px]',
                        size === 'page' ? 'h-[6px] w-4' : 'h-1 w-2 md:h-[5px] md:w-3',
                        level <= strength ? 'bg-primary' : 'bg-border',
                    )}
                />
            ))}
        </span>
    );
}
