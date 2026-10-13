/**
 * A page of the store, carrying the page the buyer came from so "আরও চা যোগ
 * করুন" on it can go back there — the খুচরা কার্ট, opened straight from the
 * pack chooser.
 */
export function withReturnPath(href: string, from: string): string {
    return `${href}?${new URLSearchParams({ from })}`;
}

/**
 * Where "আরও চা যোগ করুন" goes: the page named in the address, so long as it
 * is a path on this store, else the home page. Anything that could leave the
 * store — another origin, `//host`, `/\host` — is refused.
 */
export function returnPath(search: string): string {
    const from = new URLSearchParams(search).get('from');

    if (from === null || !from.startsWith('/') || from.startsWith('//') || from.startsWith('/\\')) {
        return '/';
    }

    return from;
}
