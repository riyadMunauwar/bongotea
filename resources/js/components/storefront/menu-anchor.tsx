import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

type MenuAnchorProps = {
    href: string;
    external?: boolean;
    className?: string;
    'aria-label'?: string;
    children: ReactNode;
};

/**
 * A link to wherever a Menu entry or a fixed button points: an Inertia visit
 * for an address on this site, a new tab for another site, and a plain link
 * for `tel:` and `mailto:`.
 */
export function MenuAnchor({ href, external = false, children, ...props }: MenuAnchorProps) {
    if (external) {
        return (
            <a href={href} target="_blank" rel="noopener noreferrer" {...props}>
                {children}
            </a>
        );
    }

    if (href.startsWith('/')) {
        return (
            <Link href={href} {...props}>
                {children}
            </Link>
        );
    }

    return (
        <a href={href} {...props}>
            {children}
        </a>
    );
}
