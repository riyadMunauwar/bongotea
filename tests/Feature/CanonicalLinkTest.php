<?php

use App\Models\Page;
use App\Support\Pages\CanonicalLink;

beforeEach(function (): void {
    $this->link = app(CanonicalLink::class);
    $this->page = Page::factory()->published()->create(['slug' => 'promo']);
});

it('names the page its own slug, so both addresses agree', function () {
    $html = $this->link->injectedInto('<html><head><title>Promo</title></head><body>Hi</body></html>', $this->page);

    /**
     * Self-referential on every Page, which is what makes it correct at both
     * addresses at once: at /promo it points at itself, and the identical
     * bytes served at the Site Root defer to the Slug. Ranking accrues to the
     * permanent identity rather than to a dropdown a Merchant can repoint.
     */
    expect($html)->toContain('<link rel="canonical" href="'.route('landing.show', 'promo').'">')
        ->and($html)->toContain('</head>');
});

it('puts the link inside the head and nowhere else', function () {
    $html = $this->link->injectedInto('<html><head><title>Promo</title></head><body>Hi</body></html>', $this->page);

    expect(strpos($html, 'rel="canonical"'))->toBeLessThan(strpos($html, '</head>'));
});

it('stands down when the merchant wrote a canonical of their own', function () {
    $authored = '<html><head><link rel="canonical" href="https://elsewhere.test/x"></head><body>Hi</body></html>';

    /**
     * Two canonicals are worth less than none — a crawler discards both — and
     * the Merchant's own is the one that was chosen deliberately.
     */
    expect($this->link->injectedInto($authored, $this->page))->toBe($authored);
});

it('recognises an authored canonical however it was spelled', function () {
    foreach ([
        "<head><link rel='canonical' href='/x'></head>",
        '<head><LINK REL="CANONICAL" HREF="/x"></head>',
        '<head><link href="/x" rel="canonical" /></head>',
        '<head><link rel = "canonical" href="/x"></head>',
    ] as $authored) {
        expect($this->link->injectedInto($authored, $this->page))->toBe($authored);
    }
});

it('still names the page when the body merely talks about canonicals', function () {
    $writesAboutThem = '<html><head><title>Promo</title></head><body>'
        .'<code>&lt;link rel="canonical" href="/x"&gt;</code>'
        .'<p>A stray <link rel="canonical" href="/x"> in the body counts for nothing.</p>'
        .'</body></html>';

    /**
     * A canonical in the body is not a canonical — this class's own insertion
     * rule says so, and so does every crawler that reads one. Letting one
     * suppress the real link would leave the Front Page answering at two
     * addresses with nothing choosing between them, reached by a Merchant who
     * wrote a page about canonical tags.
     */
    expect($this->link->injectedInto($writesAboutThem, $this->page))
        ->toContain('<link rel="canonical" href="'.route('landing.show', 'promo').'">');
});

it('leaves a document with no head exactly as the merchant wrote it', function () {
    /**
     * A canonical outside the head is ignored by every crawler that reads one,
     * so appending it to a fragment would be pure intrusion into a document
     * the Merchant owns and would buy nothing.
     */
    $fragment = '<div>Just a fragment</div>';

    expect($this->link->injectedInto($fragment, $this->page))->toBe($fragment);
});
