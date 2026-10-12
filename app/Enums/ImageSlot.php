<?php

namespace App\Enums;

use Filament\Forms\Components\FileUpload;

/**
 * Where on the storefront an uploaded image is drawn, and so the shape and
 * size it should arrive in.
 *
 * Nothing is stored at a shape: every image is kept whole and trimmed by the
 * storefront's `object-cover` box. A wrongly shaped photo therefore loses its
 * edges silently on a page nobody is watching, so each upload says the size
 * that fits and, where the box has a fixed shape, opens the crop editor at it.
 * The editor also offers Free, and nothing refuses an image of another shape:
 * a Merchant with a correctly sized file is never made to crop it.
 *
 * A logo or banner whose width follows the image (the header logo, a payment
 * method's mark, the footer banner) has no shape to crop to, so it only says
 * what draws well.
 */
enum ImageSlot
{
    /** The Product page's square; cards trim it to 16:10 and 5:4. */
    case ProductPhoto;

    case BrandLogo;

    /** A Hero carousel slide, about 2.4:1 on a desktop and nearly upright on a phone. */
    case HeroSlide;

    /** The 4:5 cards of the Journey and Video Reviews Blocks. */
    case PortraitCard;

    /** What a shared link unfurls as. */
    case ShareImage;

    case StoreLogo;

    case PaymentLogo;

    case PaymentBanner;

    /**
     * Say what fits, draw a single upload at the slot's shape, and open the
     * crop editor at it where the slot has one.
     */
    public function fit(FileUpload $upload): FileUpload
    {
        $upload->helperText($this->advice());

        $crop = $this->crop();

        if ($crop === null) {
            return $upload;
        }

        [$width, $height, $ratio] = $crop;

        $upload
            ->imageEditor()
            ->imageEditorAspectRatioOptions([$ratio, null])
            ->imageEditorViewportWidth($width)
            ->imageEditorViewportHeight($height);

        /** Read when drawn rather than now, so it holds whether `multiple()` is called before `fit()` or after. */
        return $upload
            ->panelAspectRatio(fn (FileUpload $component): ?string => $component->isMultiple() ? null : $ratio)
            ->panelLayout(fn (FileUpload $component): ?string => $component->isMultiple() ? 'grid' : 'integrated');
    }

    /**
     * What draws well here, in the words the upload shows under itself. A
     * field with something of its own to say puts this after it, since a
     * second `helperText()` replaces the first.
     */
    public function advice(): string
    {
        return match ($this) {
            self::ProductPhoto => 'Square, about 1200 × 1200 px, with the tea in the middle: cards trim the edges.',
            self::BrandLogo => 'Square, about 256 × 256 px, on a transparent background.',
            self::HeroSlide => 'Wide, about 2400 × 1000 px. Keep the subject centre-right: the words sit bottom-left, and phones show only the middle.',
            self::PortraitCard => 'Upright, about 800 × 1000 px.',
            self::ShareImage => '1200 × 630 px, the shape Facebook and WhatsApp show a shared link in.',
            self::StoreLogo => 'A transparent PNG or SVG about 168 px tall; the header draws it 42 px high.',
            self::PaymentLogo => 'A transparent PNG or SVG about 288 × 96 px; checkout draws it 32 px high.',
            self::PaymentBanner => 'Any shape, up to 2400 px wide; drawn at its own size, never wider than the page.',
        };
    }

    /**
     * The pixel size and shape a fixed-shape slot is cropped to, or null
     * where the image's own width decides.
     *
     * @return array{int, int, string}|null
     */
    private function crop(): ?array
    {
        return match ($this) {
            self::ProductPhoto => [1200, 1200, '1:1'],
            self::BrandLogo => [256, 256, '1:1'],
            self::HeroSlide => [2400, 1000, '12:5'],
            self::PortraitCard => [800, 1000, '4:5'],
            self::ShareImage => [1200, 630, '1.91:1'],
            self::StoreLogo, self::PaymentLogo, self::PaymentBanner => null,
        };
    }
}
