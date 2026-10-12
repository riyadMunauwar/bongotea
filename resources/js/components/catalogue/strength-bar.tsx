import { cn } from '@/lib/utils';

const LEVELS = [1, 2, 3, 4, 5];

/**
 * The mockup's five short bars, filled to the grade's number alone. Hidden
 * from assistive technology: the grade itself ("৪+") is always written beside
 * it, which is where its shade reads.
 */
export function StrengthBar({ bars, size = 'card', className }: { bars: number; size?: 'card' | 'page'; className?: string }) {
    return (
        <span className={cn('flex', size === 'page' ? 'gap-[3px]' : 'gap-[2px]', className)} aria-hidden="true">
            {LEVELS.map((level) => (
                <span
                    key={level}
                    className={cn('block rounded-[2px]', size === 'page' ? 'h-[6px] w-4' : 'h-1 w-2 md:h-[5px] md:w-3', level <= bars ? 'bg-primary' : 'bg-border')}
                />
            ))}
        </span>
    );
}
