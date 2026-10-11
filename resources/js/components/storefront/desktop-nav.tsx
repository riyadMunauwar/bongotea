import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { HeaderItem } from '@/types';
import { ChevronDownIcon } from './icons';
import { MenuAnchor } from './menu-anchor';
import { cn } from '@/lib/utils';

/** How long a dropdown stays open after the pointer leaves it, as the mockup has it. */
const CLOSE_DELAY_MS = 150;

/**
 * The full header's Menu: plain entries are links, and one with children opens on
 * hover — closing a moment after the pointer leaves, so a diagonal move onto
 * the panel does not drop it — or on click, for touch screens and keyboards.
 * A click elsewhere, Escape or a visit closes it. A second-level link holding
 * a third level opens it as a flyout.
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
                                <DropdownPanel items={item.href ? [{ ...item, note: null, children: [] }, ...item.children] : item.children} />
                            </div>
                        )}
                    </div>
                );
            })}
        </nav>
    );
}

const DROPDOWN_ROW = 'flex flex-col gap-[2px] rounded-[8px] px-3 py-[10px] text-[15px] font-medium whitespace-normal text-ink no-underline hover:bg-green-50 hover:text-ink';

/**
 * The second level, under a top-level entry: each link with its note, and a
 * link holding a third level opening it as a flyout to its right, on hover
 * or when focus moves into it.
 */
function DropdownPanel({ items }: { items: HeaderItem[] }) {
    return (
        <div className="flex min-w-[230px] flex-col rounded-[12px] border border-border bg-white p-[6px] shadow-dropdown">
            {items.map((child, index) => {
                const row = (
                    <>
                        <span className="flex items-center justify-between gap-3">
                            {child.label}
                            {child.children.length > 0 && <ChevronDownIcon className="-rotate-90 text-muted" />}
                        </span>
                        {child.note && <span className="text-[13px] font-normal text-muted">{child.note}</span>}
                    </>
                );

                if (child.children.length === 0) {
                    return (
                        <MenuAnchor key={index} href={child.href ?? '/'} external={child.external} className={DROPDOWN_ROW}>
                            {row}
                        </MenuAnchor>
                    );
                }

                return (
                    <div key={index} className="group/flyout relative">
                        {child.href ? (
                            <MenuAnchor href={child.href} external={child.external} className={DROPDOWN_ROW}>
                                {row}
                            </MenuAnchor>
                        ) : (
                            <button type="button" aria-haspopup="true" className={cn(DROPDOWN_ROW, 'w-full cursor-pointer border-0 bg-transparent text-left')}>
                                {row}
                            </button>
                        )}
                        <div className="absolute top-[-6px] left-full z-50 hidden pl-[6px] group-focus-within/flyout:block group-hover/flyout:block">
                            <div className="flex min-w-[210px] flex-col rounded-[12px] border border-border bg-white p-[6px] shadow-dropdown">
                                {child.children.map((grandchild, grandchildIndex) => (
                                    <MenuAnchor key={grandchildIndex} href={grandchild.href ?? '/'} external={grandchild.external} className={DROPDOWN_ROW}>
                                        {grandchild.label}
                                        {grandchild.note && <span className="text-[13px] font-normal text-muted">{grandchild.note}</span>}
                                    </MenuAnchor>
                                ))}
                            </div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
