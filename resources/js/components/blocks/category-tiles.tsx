import { Link } from '@inertiajs/react';
import { themeStyle } from '@/components/catalogue/theme';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { useTaka } from '@/lib/use-taka';
import type { CategoryTilesProps } from '@/types/blocks';
import { ButtonLink } from './shared';

/**
 * "মান অনুযায়ী কিনুন": a tile per Category in its own colours, with how many
 * teas it has and the lowest price per kilogram any of them reaches, each
 * leading to the Category's page.
 */
export function CategoryTiles({ heading, allLink, tiles }: CategoryTilesProps) {
    const taka = useTaka();

    if (tiles.length === 0) {
        return null;
    }

    return (
        <section className="shell-container flex flex-col gap-4 py-6 md:py-10">
            {(heading || allLink) && (
                <div className="flex items-center justify-between gap-3">
                    {heading && <h2 className="m-0 text-[clamp(20px,2.2vw,26px)] font-bold text-ink">{heading}</h2>}
                    {allLink && <ButtonLink button={allLink} className="text-[14px] font-semibold text-primary" />}
                </div>
            )}
            <div className="grid gap-[14px] sm:grid-cols-3">
                {tiles.map((tile) => (
                    <Link
                        key={tile.id}
                        href={tile.href}
                        style={themeStyle(tile.theme)}
                        className="relative box-border flex min-h-[120px] flex-col justify-between gap-3 overflow-hidden rounded-[16px] bg-(--cat-tint) p-5 no-underline transition-[transform,box-shadow] duration-200 hover:-translate-y-0.5 hover:shadow-tile md:min-h-[150px]"
                    >
                        <span className="absolute -right-6 -bottom-6 block size-[120px] rounded-[50%_0_50%_0] bg-(--cat-text) opacity-[.12]" aria-hidden="true" />
                        <span className="relative flex flex-col gap-1">
                            <span className="text-[12px] font-bold text-(--cat-text)">{bn.blocks.productCount(toBanglaDigits(tile.productCount))}</span>
                            <b className="text-[22px] font-bold text-ink">{tile.name}</b>
                            {tile.note && <span className="text-[13px] text-body">{tile.note}</span>}
                        </span>
                        <span className="relative flex items-center justify-between">
                            {tile.lowestPrice === null ? (
                                <span />
                            ) : (
                                <span className="text-[13px] text-ink">
                                    {bn.blocks.startsAt} <b className="text-[16px]">{taka(tile.lowestPrice)}</b>
                                    {bn.blocks.perKilogram}
                                </span>
                            )}
                            <span className="flex size-[34px] items-center justify-center rounded-full bg-white font-bold text-(--cat-text)" aria-hidden="true">
                                →
                            </span>
                        </span>
                    </Link>
                ))}
            </div>
        </section>
    );
}
