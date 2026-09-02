{{--
    The GioPages mark.

    Rendered through `SIDEBAR_LOGO_BEFORE` rather than as the panel's brand
    logo, because Filament hides the logo container the moment the sidebar
    collapses and this is the half of the branding that has to survive that.
    `sidebar.css` is where it is sized and where the rail hands it the slot.

    A page with a hero band and a call to action under it, which is the thing
    the application makes, drawn on the theme's 6px radius so it corners like
    every card and control beside it. The bands are a real knockout rather than
    a shape filled in the sidebar's colour, so the mark carries across any
    surface it is later dropped onto.

    Decorative: the wordmark beside it is the accessible name in the expanded
    sidebar, and in the rail the labelled expand button is the only thing there
    a screen reader has business announcing.
--}}
<svg
    class="gp-brand-mark"
    viewBox="0 0 24 24"
    fill="none"
    aria-hidden="true"
>
    <mask
        id="gp-brand-mark"
        maskUnits="userSpaceOnUse"
        x="0"
        y="0"
        width="24"
        height="24"
    >
        <rect width="24" height="24" rx="6" fill="#fff" />
        <rect x="6" y="6" width="12" height="5" rx="1.5" fill="#000" />
        <rect x="6" y="13" width="7" height="5" rx="1.5" fill="#000" />
    </mask>

    {{-- `currentColor`, so the mark inverts with the panel's text and dark mode stays one edit. --}}
    <rect
        width="24"
        height="24"
        rx="6"
        fill="currentColor"
        mask="url(#gp-brand-mark)"
    />
</svg>
