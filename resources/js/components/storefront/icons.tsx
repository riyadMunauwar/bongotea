/**
 * The mockup's line icons, drawn exactly as it draws them.
 */

type IconProps = { size?: number; className?: string };

export function SearchIcon({ size = 18, className }: IconProps) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" aria-hidden="true" className={className}>
            <circle cx="11" cy="11" r="7" />
            <path d="M20 20l-3.5-3.5" />
        </svg>
    );
}

export function ParcelIcon({ size = 24, className, withFlap = false }: IconProps & { withFlap?: boolean }) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={className}>
            <path d="M21 8l-9-5-9 5v8l9 5 9-5V8z" />
            <path d="M3 8l9 5 9-5" />
            <path d="M12 13v8" />
            {withFlap && <path d="M7.5 5.5l9 5" />}
        </svg>
    );
}

export function CartIcon({ size = 24, className }: IconProps) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={className}>
            <path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6.2" />
            <circle cx="10" cy="20.5" r="1.2" />
            <circle cx="17" cy="20.5" r="1.2" />
        </svg>
    );
}

export function TruckIcon({ size = 26, className }: IconProps) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={className}>
            <path d="M1 6h13v10H1z" />
            <path d="M14 9h4l3 3v4h-7z" />
            <circle cx="5.5" cy="18" r="1.8" />
            <circle cx="17.5" cy="18" r="1.8" />
        </svg>
    );
}

export function ChevronDownIcon({ size = 12, strokeWidth = 2.4, className }: IconProps & { strokeWidth?: number }) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={strokeWidth} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={className}>
            <path d="M6 9l6 6 6-6" />
        </svg>
    );
}

/** The packet basket's bag, beside the bulk Cart's trolley. */
export function BagIcon({ size = 24, className }: IconProps) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className={className}>
            <path d="M5 8h14l-1 12a1.5 1.5 0 0 1-1.5 1.4h-9A1.5 1.5 0 0 1 6 20L5 8z" />
            <path d="M9 10V6.5a3 3 0 0 1 6 0V10" />
        </svg>
    );
}
