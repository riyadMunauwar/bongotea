<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Radio;
use Illuminate\Contracts\Support\Arrayable;

/**
 * A radio drawn as a grid of cards, each with a thumbnail, a name and a line
 * of description — for choosing a layout by what it looks like rather than by
 * what it is called.
 *
 * Filament's own radio with descriptions, and the same state, validation and
 * wire:model handling; what is added is a thumbnail slot above the words on
 * every card, filled from `thumbnails()` where the option has a picture and
 * drawn empty where it does not yet. The markup keeps Filament's radio
 * classes so the panel's own styling still applies, and adds `gp-layout-cards`
 * for the grid the theme draws (`resources/css/filament/admin/forms.css`).
 */
class LayoutCards extends Radio
{
    /**
     * @var array<string, ?string> | Arrayable | Closure
     */
    protected array|Arrayable|Closure $thumbnails = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->columns(['default' => 1, 'sm' => 2, 'xl' => 3]);
    }

    /**
     * Where each option's picture is, under `public/`, keyed by option value;
     * null for an option that has none yet.
     *
     * @param  array<string, ?string> | Arrayable | Closure  $thumbnails
     */
    public function thumbnails(array|Arrayable|Closure $thumbnails): static
    {
        $this->thumbnails = $thumbnails;

        return $this;
    }

    /**
     * @return array<string, ?string>
     */
    public function getThumbnails(): array
    {
        $thumbnails = $this->evaluate($this->thumbnails);

        if ($thumbnails instanceof Arrayable) {
            $thumbnails = $thumbnails->toArray();
        }

        return $thumbnails;
    }

    public function getThumbnail(string|int $value): ?string
    {
        return $this->getThumbnails()[$value] ?? null;
    }

    public function toEmbeddedHtml(): string
    {
        $extraInputAttributeBag = $this->getExtraInputAttributeBag();
        $id = $this->getId();
        $isDisabled = $this->isDisabled();
        $statePath = $this->getStatePath();
        $wireModelAttribute = $this->applyStateBindingModifiers('wire:model');
        $isAutofocused = $this->isAutofocused();
        $hasError = $this->hasErrorForPath($statePath);

        $containerAttributes = $this->getExtraAttributeBag()
            ->grid($this->getColumns())
            ->merge([
                'aria-labelledby' => "{$id}-label",
                'role' => 'radiogroup',
            ], escape: false)
            ->class(['fi-fo-radio', 'gp-layout-cards']);

        ob_start(); ?>

        <div <?= $containerAttributes->toHtml() ?>>
            <?php $first = true; ?>
            <?php foreach ($this->getOptions() as $value => $label) { ?>
                <?php
                    $inputAttributes = $extraInputAttributeBag
                        ->merge([
                            'autofocus' => $first && $isAutofocused,
                            'disabled' => $isDisabled || $this->isOptionDisabled($value, $label),
                            'id' => e($id.'-'.$value),
                            'name' => $id,
                            'value' => e($value),
                            $wireModelAttribute => $statePath,
                        ], escape: false)
                        ->class([
                            'fi-radio-input',
                            'fi-valid' => ! $hasError,
                            'fi-invalid' => $hasError,
                        ]);
                $first = false;
                $thumbnail = $this->getThumbnail($value);
                ?>

                <label class="fi-fo-radio-label gp-layout-card">
                    <span class="gp-layout-card-thumbnail">
                        <?php if ($thumbnail !== null) { ?>
                            <img src="<?= e(asset($thumbnail)) ?>" alt="" loading="lazy">
                        <?php } ?>
                    </span>

                    <input type="radio" <?= $inputAttributes->toHtml() ?> />

                    <div class="fi-fo-radio-label-text">
                        <p><?= e($label) ?></p>

                        <?php if ($this->hasDescription($value)) { ?>
                            <p class="fi-fo-radio-label-description">
                                <?= e($this->getDescription($value)) ?>
                            </p>
                        <?php } ?>
                    </div>
                </label>
            <?php } ?>
        </div>

        <?php return $this->wrapEmbeddedHtml(ob_get_clean(), labelTag: 'div');
    }
}
