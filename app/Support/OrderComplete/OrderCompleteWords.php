<?php

namespace App\Support\OrderComplete;

/**
 * An Order Complete Config's words for one Order: what the page actually
 * draws, with every box the Merchant left empty at the built-in words and the
 * Order's own facts in place of the placeholders.
 *
 * The one place the substitution happens, so a placeholder can never reach a
 * Customer in one string and be replaced in another.
 */
final class OrderCompleteWords
{
    public function __construct(
        private readonly OrderCompleteConfig $config,
        private readonly string $orderNumber,
        private readonly string $total,
        private readonly string $pageUrl,
    ) {}

    public function statusLine(): string
    {
        return $this->fill($this->config->statusLine());
    }

    public function headline(): string
    {
        return $this->fill($this->config->headline());
    }

    public function message(): string
    {
        return $this->fill($this->config->message());
    }

    public function note(): string
    {
        return $this->fill($this->config->note());
    }

    public function primaryButtonLabel(): string
    {
        return $this->fill($this->config->primaryButton->label ?? OrderCompleteConfig::DEFAULT_PRIMARY_BUTTON_LABEL);
    }

    /** Where the Merchant said, else the Page the Order was placed on. */
    public function primaryButtonLink(): string
    {
        return $this->link($this->config->primaryButton->link);
    }

    /** An empty secondary label is the Merchant saying there is no second button. */
    public function drawsSecondaryButton(): bool
    {
        return $this->config->secondaryButton->label !== null;
    }

    public function secondaryButtonLabel(): string
    {
        return $this->fill((string) $this->config->secondaryButton->label);
    }

    public function secondaryButtonLink(): string
    {
        return $this->link($this->config->secondaryButton->link);
    }

    /**
     * @return list<array{label: string, timing: string}>
     */
    public function steps(): array
    {
        $steps = [];

        foreach ($this->config->steps as $position => $step) {
            $default = OrderCompleteConfig::DEFAULT_STEPS[$position];

            $steps[] = [
                'label' => $this->fill($step->label ?? $default['label']),
                'timing' => $this->fill($step->timing ?? $default['timing']),
            ];
        }

        return $steps;
    }

    /**
     * @return list<array{title: string, line: string}>
     */
    public function tiles(): array
    {
        $tiles = [];

        foreach ($this->config->tiles as $position => $tile) {
            $default = OrderCompleteConfig::DEFAULT_TILES[$position];

            $tiles[] = [
                'title' => $this->fill($tile->title ?? $default['title']),
                'line' => $this->fill($tile->line ?? $default['line']),
            ];
        }

        return $tiles;
    }

    private function fill(string $text): string
    {
        return OrderCompleteCopy::fill($text, $this->orderNumber, $this->total);
    }

    private function link(?string $link): string
    {
        return $link === null
            ? $this->pageUrl
            : OrderCompleteCopy::fillLink($link, $this->orderNumber, $this->total);
    }
}
