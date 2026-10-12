/**
 * Products and Categories as `App\Support\Storefront\CatalogueProps` sends
 * them, key for key. Every price is in minor units, as the pricing module
 * reads it; the shared `minorUnitsPerTaka` turns one into taka for display.
 */

import type { Tier } from '@/lib/pricing';

/** A Category Theme's ten colours, as `App\Enums\CategoryTheme::tokens()` names them. */
export type CategoryTheme = {
    text: string;
    tint: string;
    panel: string;
    panelText: string;
    panelSubtext: string;
    accent: string;
    pillBackground: string;
    pillText: string;
    tickBackground: string;
    line: string;
};

export type CategoryReference = {
    id: number;
    name: string;
    slug: string;
    href: string;
    theme: CategoryTheme;
};

export type ProductCard = {
    id: number;
    name: string;
    href: string;
    shortDescription: string | null;
    imageUrl: string | null;
    badge: string | null;
    /** The grade as written ("৪+") and the bars of five it fills; null when the Merchant left it blank. */
    strength: Strength | null;
    isAvailable: boolean;
    brand: { id: number; name: string };
    category: CategoryReference;
    tagIds: number[];
    /** What every discount shown is measured from; no Tier Price exceeds it. */
    mrp: number;
    /** The Product's own Tiers, in order; the first's price is the most it charges. */
    tiers: Tier[];
};

export type Strength = { grade: string; bars: number };

export type ProductDetail = ProductCard & {
    descriptionHtml: string | null;
    /** In order; the first is the cover. */
    gallery: string[];
    teaType: string | null;
    particleSize: string | null;
    liquorColour: string | null;
    liquorBody: string | null;
    aroma: string | null;
    infusion: string | null;
    cupsPerKilogram: string | null;
    packageWeight: string | null;
    /** The Merchant's own, for this Product alone, drawn after the fixed ones. */
    customCharacteristics: { label: string; value: string }[];
    bestFor: string[];
    notBestFor: string | null;
    /** In order; numbered on the page. */
    brewingSteps: string[];
    businessTips: string[];
};

export type CategoryDetail = CategoryReference & {
    note: string | null;
    qualityHeadline: string | null;
    about: string | null;
    whyPoints: string[];
    productCount: number;
    /** From the cheapest last Tier to the dearest first Tier; null with no Products. */
    priceRange: { low: number; high: number } | null;
};

/** One of the live search dropdown's matches. */
export type SearchSuggestion = {
    name: string;
    href: string;
    imageUrl: string | null;
    categoryName: string;
    firstTierPrice: number;
};
