import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { bn } from '@/lang/bn';
import { formatPhone } from '@/lib/bangla';
import { cn } from '@/lib/utils';
import { useTaka } from '@/lib/use-taka';
import type { SearchSuggestion } from '@/types/catalogue';
import { SearchIcon } from './icons';

/** How long typing must pause before the dropdown asks for matches. */
const PAUSE_MS = 250;

/** How long the dropdown stays after focus leaves, so a click on a match lands before it closes. */
const BLUR_GRACE_MS = 150;

/**
 * The header's search: a form that opens the results page, and, while the
 * buyer types, a dropdown of up to five matching teas to jump straight to.
 *
 * Without script the form still submits to the results page. The query on
 * the results page stays in the box, so it can be corrected.
 */
export function SearchBox({ compact = false, className }: { compact?: boolean; className?: string }) {
    const page = usePage();
    const { links, store } = page.props;
    const pageUrl = page.url;
    const [query, setQuery] = useState(() => currentQuery(pageUrl));
    const [answer, setAnswer] = useState<{ words: string; products: SearchSuggestion[] } | null>(null);
    const [open, setOpen] = useState(false);
    const listId = useId();
    const closeTimer = useRef<number | undefined>(undefined);
    const taka = useTaka();

    useEffect(() => setQuery(currentQuery(pageUrl)), [pageUrl]);

    useEffect(() => router.on('navigate', () => setOpen(false)), []);

    useEffect(() => {
        const words = query.trim();

        if (words === '') {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`${links.searchSuggestions}?q=${encodeURIComponent(words)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : { products: [] }))
                .then((body: { products: SearchSuggestion[] }) => setAnswer({ words, products: body.products }))
                .catch(() => {});
        }, PAUSE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, links.searchSuggestions]);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setOpen(false);
        router.get(links.search, query.trim() === '' ? {} : { q: query.trim() });
    }

    const words = query.trim();
    const suggestions = answer !== null && answer.words === words ? answer.products : null;
    const showList = open && words !== '' && suggestions !== null;

    return (
        <div
            className={cn('relative min-w-0', className)}
            onFocus={() => {
                window.clearTimeout(closeTimer.current);
                setOpen(true);
            }}
            onBlur={() => {
                closeTimer.current = window.setTimeout(() => setOpen(false), BLUR_GRACE_MS);
            }}
            onKeyDown={(event) => event.key === 'Escape' && setOpen(false)}
        >
            <form
                action={links.search}
                method="get"
                role="search"
                onSubmit={submit}
                className="m-0 flex h-full min-w-0 items-center overflow-hidden rounded-[10px] border-[1.5px] border-primary bg-white"
            >
                <input
                    type="search"
                    name="q"
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setOpen(true);
                    }}
                    autoComplete="off"
                    placeholder={bn.header.searchPlaceholder}
                    aria-label={bn.header.search}
                    aria-controls={listId}
                    aria-expanded={showList}
                    className={cn('h-full min-w-0 flex-1 border-0 bg-transparent px-[14px] text-ink outline-0', compact ? 'text-[14px]' : 'text-[15px]')}
                />
                <button
                    type="submit"
                    aria-label={bn.header.search}
                    className={cn('flex h-full cursor-pointer items-center gap-[6px] border-0 bg-primary text-[14px] font-semibold text-white', compact ? 'px-[14px]' : 'px-[18px]')}
                >
                    <SearchIcon />
                    {!compact && bn.header.search}
                </button>
            </form>

            {showList && suggestions.length === 0 && (
                <div id={listId} className="absolute inset-x-0 top-[calc(100%+6px)] z-50 flex flex-col gap-1 rounded-[12px] border border-border bg-white px-[14px] py-3 shadow-dropdown">
                    <b className="text-[15px] font-semibold text-ink">{bn.search.none(words)}</b>
                    <span className="text-[13px] leading-[1.6] text-muted">{bn.search.noneHint}</span>
                    {store.hotline && (
                        <a href={store.hotline.href} className="self-start text-[15px] font-bold">
                            {bn.footer.call} {formatPhone(store.hotline.number)}
                        </a>
                    )}
                </div>
            )}

            {showList && suggestions.length > 0 && (
                <div id={listId} className="absolute inset-x-0 top-[calc(100%+6px)] z-50 overflow-hidden rounded-[12px] border border-border bg-white shadow-dropdown">
                    <span className="block px-[14px] pt-[10px] pb-1 text-[12px] font-semibold text-muted">{bn.search.suggestions}</span>
                    <ul className="m-0 list-none p-0">
                        {suggestions.map((suggestion) => (
                            <li key={suggestion.href}>
                                <Link href={suggestion.href} className="flex items-center gap-3 px-[14px] py-2 text-ink no-underline hover:bg-green-50 hover:text-ink">
                                    <span className={cn('relative block size-10 flex-none overflow-hidden rounded-[8px]', !suggestion.imageUrl && 'stripes-light')}>
                                        {suggestion.imageUrl && <img src={suggestion.imageUrl} alt="" className="absolute inset-0 size-full object-cover" />}
                                    </span>
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <b className="truncate text-[15px] font-semibold">{suggestion.name}</b>
                                        <span className="text-[12px] text-muted">{suggestion.categoryName}</span>
                                    </span>
                                    <span className="text-[14px] font-bold whitespace-nowrap">
                                        {taka(suggestion.firstTierPrice)}
                                        {bn.blocks.perKilogram}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <Link
                        href={`${links.search}?q=${encodeURIComponent(words)}`}
                        className="block border-t border-divider px-[14px] py-[10px] text-[14px] font-semibold text-primary no-underline"
                    >
                        {bn.search.seeAll(words)}
                    </Link>
                </div>
            )}
        </div>
    );
}

/** The `q` of the address the page was visited at, so the results page keeps its query in the box. */
function currentQuery(pageUrl: string): string {
    const search = pageUrl.split('?')[1] ?? '';

    return new URLSearchParams(search).get('q') ?? '';
}
