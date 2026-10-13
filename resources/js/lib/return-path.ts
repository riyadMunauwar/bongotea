/**
 * The Checkout opened for the retail Cart straight from the pack chooser,
 * carrying the page the Pack was bought from so "আরও চা যোগ করুন" can go
 * back there.
 */
export function retailCheckoutUrl(checkout: string, from: string): string {
    return `${checkout}?${new URLSearchParams({ cart: 'retail', from })}`;
}

/**
 * Where "আরও চা যোগ করুন" goes: the page named in the Checkout's address,
 * so long as it is a path on this store, else the home page. Anything that
 * could leave the store — another origin, `//host`, `/\host` — is refused.
 */
export function returnPath(search: string): string {
    const from = new URLSearchParams(search).get('from');

    if (from === null || !from.startsWith('/') || from.startsWith('//') || from.startsWith('/\\')) {
        return '/';
    }

    return from;
}
