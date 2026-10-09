import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { bn } from '@/lang/bn';
import { formatPhone } from '@/lib/bangla';
import { cn } from '@/lib/utils';
import type { ContactCardsProps } from '@/types/blocks';

/**
 * Call, WhatsApp and email as cards, from the Store settings. A way the
 * Merchant left blank is not drawn. Raised, the row lifts over the banner
 * above it as on the Contact page.
 */
export function ContactCards({ whatsappNote, emailNote, raised }: ContactCardsProps) {
    const { store } = usePage().props;

    if (!store.hotline && !store.whatsapp && !store.email) {
        return null;
    }

    return (
        <div className={cn('shell-container pb-5 md:pb-9', raised ? 'relative -mt-9' : 'pt-5 md:pt-9')}>
            <div className="grid grid-cols-1 gap-[14px] md:grid-cols-3">
                {store.hotline && (
                    <ContactCard href={store.hotline.href} badge={bn.blocks.contact.callBadge} badgeClass="bg-primary text-white text-[14px]" label={bn.blocks.contact.call} hoverClass="hover:border-primary">
                        <b className="text-[20px] font-bold">{formatPhone(store.hotline.number)}</b>
                        {store.hours && <span className="text-[12px] font-semibold text-primary">{store.hours}</span>}
                    </ContactCard>
                )}
                {store.whatsapp && (
                    <ContactCard href={store.whatsapp.href} external badge={bn.blocks.contact.whatsappBadge} badgeClass="bg-whatsapp text-white text-[13px]" label={bn.blocks.contact.whatsapp} hoverClass="hover:border-whatsapp">
                        <b className="text-[20px] font-bold">{formatPhone(store.whatsapp.number)}</b>
                        {whatsappNote && <span className="text-[12px] font-semibold text-whatsapp-text">{whatsappNote}</span>}
                    </ContactCard>
                )}
                {store.email && (
                    <ContactCard href={`mailto:${store.email}`} badge={bn.blocks.contact.emailBadge} badgeClass="bg-mail-tint text-mail-text text-[18px]" label={bn.blocks.contact.email} hoverClass="hover:border-primary">
                        <b className="truncate text-[17px] font-bold">{store.email}</b>
                        {emailNote && <span className="text-[12px] text-muted">{emailNote}</span>}
                    </ContactCard>
                )}
            </div>
        </div>
    );
}

type ContactCardProps = {
    href: string;
    external?: boolean;
    badge: string;
    badgeClass: string;
    label: string;
    hoverClass: string;
    children: ReactNode;
};

function ContactCard({ href, external = false, badge, badgeClass, label, hoverClass, children }: ContactCardProps) {
    return (
        <a
            href={href}
            {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
            className={cn('flex items-center gap-4 rounded-2xl border border-border bg-white p-[22px] text-ink no-underline shadow-raised hover:text-ink', hoverClass)}
        >
            <span className={cn('flex size-[52px] flex-none items-center justify-center rounded-[14px] font-bold', badgeClass)}>{badge}</span>
            <span className="flex min-w-0 flex-col gap-[2px]">
                <span className="text-[13px] text-muted">{label}</span>
                {children}
            </span>
        </a>
    );
}
