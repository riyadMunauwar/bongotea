import { usePage } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import { bn } from '@/lang/bn';
import { formatPhone, toBanglaDigits } from '@/lib/bangla';
import { cn } from '@/lib/utils';
import { MenuAnchor } from './menu-anchor';

/** How each switched-on method's badge is drawn on the payment line. */
const PAYMENT_BADGE: Record<string, string> = {
    bkash: 'bg-bkash text-white font-bold',
    cash_on_delivery: 'bg-gold-accent text-night font-semibold',
};

/**
 * The dark footer: the store's blurb and the two ways to reach it, the
 * Merchant's link columns, the address, and a bottom line naming only the
 * payment methods actually switched on.
 */
export function SiteFooter() {
    const { store, menus, paymentMethods } = usePage().props;
    const { blurb, columns, bottomLinks } = menus.footer;

    /** The brand block, then each column, then the address. */
    const desktopColumns: CSSProperties = { ['--footer-columns' as string]: `1.5fr repeat(${columns.length + 1}, minmax(0, 1fr))` };

    return (
        <footer id="footer" className="bg-night text-on-dark-sub">
            <div
                style={desktopColumns}
                className="shell-container grid grid-cols-2 gap-x-7 gap-y-9 pt-10 md:grid-cols-3 md:pt-16 lg:grid-cols-[var(--footer-columns)]"
            >
                <div className="col-span-full flex max-w-[420px] flex-col gap-[18px] lg:col-auto">
                    <span className="flex items-center gap-[9px] text-white">
                        <span className="block size-5 rounded-[50%_0_50%_0] bg-gold-accent" aria-hidden="true" />
                        <span className="text-[22px] font-bold">{store.name}</span>
                    </span>
                    {blurb && <span className="text-[15px] leading-[1.75] text-night-muted">{blurb}</span>}
                    <div className="flex flex-col gap-[10px]">
                        {store.hotline && (
                            <ContactCard href={store.hotline.href} badge={bn.footer.call} badgeClass="bg-gold-accent text-night text-[13px]" caption={[bn.footer.phoneOrder, store.hours].filter(Boolean).join(' · ')} number={store.hotline.number} />
                        )}
                        {store.whatsapp && (
                            <ContactCard href={store.whatsapp.href} external badge={bn.footer.whatsappBadge} badgeClass="bg-whatsapp text-white text-[11px]" caption={bn.footer.whatsapp} number={store.whatsapp.number} />
                        )}
                    </div>
                </div>

                {columns.map((column, index) => (
                    <div key={index} className="flex flex-col gap-3">
                        {column.heading && <span className="text-[13px] font-semibold text-gold-accent">{column.heading}</span>}
                        {column.links.map((link, linkIndex) => (
                            <MenuAnchor key={linkIndex} href={link.href} external={link.external} className="text-[15px] text-on-dark-sub no-underline hover:text-white">
                                {link.label}
                            </MenuAnchor>
                        ))}
                    </div>
                ))}

                <div className="flex flex-col gap-3 text-[15px] leading-[1.6]">
                    <span className="text-[13px] font-semibold text-gold-accent">{bn.footer.address}</span>
                    {store.address && <span>{store.address}</span>}
                    {store.email && (
                        <a href={`mailto:${store.email}`} className="text-on-dark-sub no-underline hover:text-white">
                            {store.email}
                        </a>
                    )}
                    <span className="text-[14px] text-night-faint">{bn.footer.deliveryNote}</span>
                </div>
            </div>

            <div className="mx-auto mt-10 max-w-[1200px] px-[clamp(16px,4vw,32px)]">
                <div className="flex flex-wrap justify-between gap-3 border-t border-white/10 pt-[18px] pb-[22px] text-[13px] text-night-faint">
                    <span>{bn.footer.rights(toBanglaDigits(new Date().getFullYear()), store.name)}</span>
                    {paymentMethods.length > 0 && (
                        <div className="flex flex-wrap items-center gap-[6px]">
                            <span>{bn.footer.payment}</span>
                            {paymentMethods.map((method) => (
                                <span key={method} className={cn('rounded-[4px] px-2 py-[2px] text-[12px]', PAYMENT_BADGE[method] ?? 'bg-night-raised font-semibold text-white')}>
                                    {bn.paymentMethods[method] ?? method}
                                </span>
                            ))}
                        </div>
                    )}
                    {bottomLinks.length > 0 && (
                        <div className="flex gap-[18px]">
                            {bottomLinks.map((link, index) => (
                                <MenuAnchor key={index} href={link.href} external={link.external} className="text-night-faint no-underline hover:text-white">
                                    {link.label}
                                </MenuAnchor>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </footer>
    );
}

type ContactCardProps = {
    href: string;
    external?: boolean;
    badge: string;
    badgeClass: string;
    caption: string;
    number: string;
};

function ContactCard({ href, external = false, badge, badgeClass, caption, number }: ContactCardProps) {
    return (
        <a
            href={href}
            {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
            className="flex items-center gap-[14px] rounded-[12px] bg-night-raised px-4 py-3 no-underline hover:bg-night-hover"
        >
            <span className={cn('flex size-10 flex-none items-center justify-center rounded-full font-bold', badgeClass)}>{badge}</span>
            <span className="flex flex-col">
                <span className="text-[12px] text-night-faint">{caption}</span>
                <b className="text-[19px] font-semibold tracking-[.02em] text-white">{formatPhone(number)}</b>
            </span>
        </a>
    );
}
