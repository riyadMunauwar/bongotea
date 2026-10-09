import type { ReactNode } from 'react';
import { MenuAnchor } from '@/components/storefront/menu-anchor';
import { bn } from '@/lang/bn';
import { cn } from '@/lib/utils';
import type { BlockButton } from '@/types/blocks';

/** The mockup's section heading: a small coloured line above a bold title, and a paragraph under it. */
export function SectionHeading({ eyebrow, heading, text, className }: { eyebrow?: string | null; heading?: string | null; text?: string | null; className?: string }) {
    if (!eyebrow && !heading && !text) {
        return null;
    }

    return (
        <div className={cn('flex flex-col gap-[10px]', className)}>
            {eyebrow && <span className="text-[14px] font-semibold text-eyebrow">{eyebrow}</span>}
            {heading && <h2 className="m-0 text-[clamp(22px,2.4vw,30px)] leading-[1.3] font-bold text-ink">{heading}</h2>}
            {text && <p className="m-0 max-w-[520px] text-[clamp(15px,1.3vw,17px)] leading-[1.7] text-muted">{text}</p>}
        </div>
    );
}

/** A Block's button, wherever the Merchant pointed it. */
export function ButtonLink({ button, className }: { button: BlockButton; className: string }) {
    return (
        <MenuAnchor href={button.href} external={/^https?:\/\//i.test(button.href)} className={cn('no-underline', className)}>
            {button.label}
        </MenuAnchor>
    );
}

/** The Merchant's picture, or the mockup's striped stand-in labelled with what belongs there. */
export function Picture({ src, alt, stripes, label, className, children }: { src: string | null; alt: string; stripes: string; label?: string; className?: string; children?: ReactNode }) {
    return (
        <div className={cn('relative overflow-hidden', !src && stripes, className)}>
            {src && <img src={src} alt={alt} loading="lazy" className="absolute inset-0 size-full object-cover" />}
            {!src && label && (
                <span className="relative rounded-[4px] bg-white/90 px-[7px] py-[3px] font-mono text-[10px] font-medium text-muted">
                    {bn.blocks.imagePlaceholder}: {label}
                </span>
            )}
            {children}
        </div>
    );
}

/** The first letter of a name, as the mockup's round avatars show it. */
export function initial(name: string): string {
    return Array.from(name.trim())[0] ?? '';
}

/** The white-on-dark play triangle, drawn with borders as the mockup draws it. */
export function PlayTriangle({ size = 'small' }: { size?: 'small' | 'large' }) {
    return size === 'large' ? (
        <span className="ml-[5px] block h-0 w-0 border-y-[11px] border-l-[18px] border-y-transparent border-l-primary" />
    ) : (
        <span className="ml-[3px] block h-0 w-0 border-y-[7px] border-l-[11px] border-y-transparent border-l-white" />
    );
}
