{{--
    The GioPages mark. `AdminPanelProvider` has the reason it is rendered here
    rather than as the panel's brand logo, and `sidebar.css` sizes it and hands
    it the rail's slot.

    A page with a hero band and a call to action under it, which is the thing
    the application makes. The 24-unit viewBox is rendered at 1.5rem, so a unit
    is a pixel and `rx="6"` is literally the `--gp-radius` every card and
    control beside it corners on. The bands are a real knockout rather than
    shapes filled in the sidebar's colour, so the mark carries onto any surface
    it is later dropped onto.

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

    {{-- `currentColor`, so the mark inverts with the panel and dark mode stays one edit. --}}
    <rect
        width="24"
        height="24"
        rx="6"
        fill="currentColor"
        mask="url(#gp-brand-mark)"
    />
</svg>
