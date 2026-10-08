import type { Announcement, Store } from '@/types';
import { bn } from '@/lang/bn';
import { formatPhone } from '@/lib/bangla';

type AnnouncementBarProps = {
    announcement: Announcement;
    store: Store;
};

/**
 * The dark strip above the header: the Merchant's pill and offer, centred on
 * a phone; on the full header, the delivery promises and the hotline beside
 * it.
 */
export function AnnouncementBar({ announcement, store }: AnnouncementBarProps) {
    return (
        <div className="bg-night text-[13px] text-night-text">
            <div className="shell-container flex h-[34px] items-center justify-center gap-4 overflow-hidden whitespace-nowrap lg:justify-between">
                <span className="flex items-center gap-2">
                    {announcement.pill && <span className="rounded-[4px] bg-gold-accent px-[7px] py-px text-[11px] font-bold text-night">{announcement.pill}</span>}
                    {announcement.message}
                </span>
                <div className="hidden gap-[22px] text-night-muted lg:flex">
                    <span>{bn.topBar.nationwideDelivery}</span>
                    <span>{bn.topBar.cashOnDelivery}</span>
                    {store.hotline && (
                        <span className="font-semibold text-white">
                            {bn.topBar.hotline} {formatPhone(store.hotline.number)}
                        </span>
                    )}
                </div>
            </div>
        </div>
    );
}
