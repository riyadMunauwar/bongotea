import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { HeaderItem } from '@/types';
import { ChevronDownIcon } from './icons';
import { MenuAnchor } from './menu-anchor';
import { cn } from '@/lib/utils';

/** How long a dropdown stays open after the pointer leaves it, as the mockup has it. */
const CLOSE_DELAY_MS = 150;

/**
 * The full header's Menu: plain entries are links, and a Dropdown opens on
 * hover — closing a moment after the pointer leaves, so a diagonal move onto
 * the panel does not drop it — or on click, for touch screens and keyboards.
 * A click elsewhere, Escape or a visit closes it.
 */
export function DesktopNav({ items }: { items: HeaderItem[] }) {
    const [open, setOpen] = useState<number | null>(null);
    const closeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const nav = useRef<HTMLElement>(null);

    const cancelClose = () => {
        if (closeTimer.current) {
            clearTimeout(closeTimer.current);
            closeTimer.current = null;
        }
    };

    useEffect(() => {
        const closeOnOutsideClick = (event: MouseEvent) => {
            if (nav.current && !nav.current.contains(event.target as Node)) {
                setOpen(null);
            }
        };
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(null);
            }
        };
        const stopListeningForVisits = router.on('navigate', () => setOpen(null));

        document.addEventListener('mousedown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            cancelClose();
            stopListeningForVisits();
            document.removeEventListener('mousedown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, []);

    return (
        <nav ref={nav} className="flex items-center gap-[clamp(14px,2.2vw,28px)] whitespace-nowrap text-[14px] font-semibold">
            {items.map((item, index) => {
                const hasChildren = item.children.length > 0;
                const isOpen = open === index;

                if (!hasChildren) {
                    return (
                        <MenuAnchor key={index} href={item.href ?? '/'} external={item.external} className="flex items-center gap-[5px] py-2 text-ink no-underline hover:text-primary">
                            {item.label}
                        </MenuAnchor>
                    );
                }

                return (
                    <div
                        key={index}
                        className="relative flex items-center"
                        onMouseEnter={() => {
                            cancelClose();
                            setOpen(index);
                        }}
                        onMouseLeave={() => {
                            cancelClose();
                            closeTimer.current = setTimeout(() => setOpen((current) => (current === index ? null : current)), CLOSE_DELAY_MS);
                        }}
                    >
                        <button
                            type="button"
                            aria-expanded={isOpen}
                            aria-haspopup="true"
                            onClick={() => setOpen(isOpen ? null : index)}
                            className="flex cursor-pointer items-center gap-[5px] border-0 bg-transparent p-0 py-2 text-[14px] font-semibold whitespace-nowrap text-ink"
                        >
                            {item.label}
                            <ChevronDownIcon className={cn('transition-transform duration-200', isOpen && 'rotate-180')} />
                        </button>
                        {isOpen && (
                            <div className="absolute top-full -left-[14px] z-50 pt-[10px]">
                                <div className="flex min-w-[230px] flex-col rounded-[12px] border border-border bg-white p-[6px] shadow-dropdown">
                                    {item.children.map((child, childIndex) => (
                                        <MenuAnchor
                                            key={childIndex}
                                            href={child.href}
                                            external={child.external}
                                            className="flex flex-col gap-[2px] rounded-[8px] px-3 py-[10px] text-[15px] font-medium whitespace-normal text-ink no-underline hover:bg-green-50 hover:text-ink"
                                        >
                                            {child.label}
                                            {child.note && <span className="text-[13px] font-normal text-muted">{child.note}</span>}
                                        </MenuAnchor>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                );
            })}
        </nav>
    );
}
