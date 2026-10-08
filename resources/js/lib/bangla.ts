/**
 * Numbers as a Bangla reader reads them, and inputs as a Bangla keyboard
 * types them.
 *
 * Keep raw numbers in state and format only for display. Every numeric or
 * phone input runs what was typed through `toLatinDigits` or
 * `parseWholeNumber`, so `১২` and `12` are the same answer. Order numbers stay
 * Latin and never come through here.
 */

const BANGLA_DIGITS = '০১২৩৪৫৬৭৮৯';

/** Every ASCII digit in the value as its Bangla digit; everything else as it was. */
export function toBanglaDigits(value: string | number): string {
    return String(value).replace(/\d/g, (digit) => BANGLA_DIGITS[Number(digit)]);
}

/** Every Bangla digit in the value as its ASCII digit; everything else as it was. */
export function toLatinDigits(value: string): string {
    return value.replace(/[০-৯]/g, (digit) => String(BANGLA_DIGITS.indexOf(digit)));
}

/** A whole number in Bangla digits with Indian grouping: ১২,৩৪,৫৬৭. */
export function formatNumber(value: number): string {
    return toBanglaDigits(Math.round(value).toLocaleString('en-IN'));
}

/** Taka, rounded to the whole taka: ৳৬,৩৪৪. */
export function formatTaka(value: number): string {
    return '৳' + formatNumber(value);
}

/**
 * A phone number as typed by the Merchant, drawn the way the mockup draws
 * the hotline: a Bangladeshi mobile number as ০১৭০০-০০০০০০, whatever was
 * typed in front of it; anything else in Bangla digits as it was typed.
 */
export function formatPhone(value: string): string {
    const digits = toLatinDigits(value).replace(/\D/g, '');
    const national = digits.startsWith('880') ? digits.slice(2) : digits;

    if (/^01\d{9}$/.test(national)) {
        return toBanglaDigits(`${national.slice(0, 5)}-${national.slice(5)}`);
    }

    return toBanglaDigits(value);
}

/** A whole number typed in Bangla or English digits, or null when it is not one. */
export function parseWholeNumber(value: string): number | null {
    const latin = toLatinDigits(value).trim();

    return /^\d+$/.test(latin) ? Number(latin) : null;
}
