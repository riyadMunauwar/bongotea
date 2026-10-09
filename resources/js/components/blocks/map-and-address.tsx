import { usePage } from '@inertiajs/react';
import { bn } from '@/lang/bn';
import type { MapAndAddressProps } from '@/types/blocks';

/**
 * The map beside the address card. The address is the Store settings'; the
 * hours are the Block's rows, or the Store settings' single line when it has
 * none. With no embed yet, the mockup's striped placeholder stands in.
 */
export function MapAndAddress({ embedUrl, hours }: MapAndAddressProps) {
    const { store } = usePage().props;

    return (
        <div className="shell-container grid grid-cols-1 items-stretch gap-[14px] py-5 md:grid-cols-[1.3fr_1fr] md:gap-5 md:py-9">
            <div className="relative min-h-[220px] overflow-hidden rounded-[20px]">
                {embedUrl ? (
                    <iframe src={embedUrl} title={bn.blocks.map.title} loading="lazy" referrerPolicy="no-referrer-when-downgrade" className="absolute inset-0 size-full border-0" />
                ) : (
                    <div className="box-border flex size-full min-h-[220px] items-end p-[14px] stripes-light">
                        <span className="rounded-[4px] bg-white/90 px-2 py-1 font-mono text-[11px] font-medium text-muted">{bn.blocks.map.title}</span>
                    </div>
                )}
            </div>
            <div className="flex flex-col gap-[14px] rounded-[20px] bg-surface p-[22px]">
                {store.address && (
                    <div className="flex flex-col gap-1">
                        <span className="text-[13px] font-semibold text-eyebrow">{bn.blocks.map.address}</span>
                        <span className="text-[15px] leading-[1.7]">{store.address}</span>
                    </div>
                )}
                {(hours.length > 0 || store.hours) && (
                    <div className="flex flex-col gap-2 border-t border-border pt-[14px] first:border-t-0 first:pt-0">
                        <span className="text-[13px] font-semibold text-eyebrow">{bn.blocks.map.hours}</span>
                        {hours.length > 0 ? (
                            hours.map((row, index) => (
                                <div key={index} className="flex justify-between gap-4 text-[14px]">
                                    <span>{row.days}</span>
                                    <b className="font-semibold">{row.hours}</b>
                                </div>
                            ))
                        ) : (
                            <b className="text-[14px] font-semibold">{store.hours}</b>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
