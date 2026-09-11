<?php

namespace App\Filament\Resources\Pages\Schemas\Tracking;

use App\Enums\TrackingMoment;
use App\Models\Destination;
use App\Models\Page;
use App\Support\Tracking\TrackingPreview;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontFamily;

/**
 * Exactly what this Page will send, before it sends anything.
 *
 * Built by asking each Provider's own driver what it would put on the wire
 * for a sample Order — the same method the sweep calls — so this is not a
 * description of the payload that could drift from the payload. What is drawn
 * is the real parameter names, real figures from a sample Order, and the
 * match keys as they leave: hashed.
 *
 * Entries and not fields: nothing here is saved, and it is redrawn as the
 * switches above are set, because the whole point of a preview is that it
 * answers for the choices being made rather than for the last ones saved.
 */
class Preview
{
    public static function section(): Section
    {
        return Section::make('Preview')
            ->description('Every event this Page will report, as the Provider receives it. The figures are from a sample Order; the match keys are shown hashed, which is how they leave this server.')
            ->columns(1)
            ->schema(fn (Get $get, ?Page $record): array => self::events($get, $record));
    }

    /**
     * One block per event, in the order the Moments produce them.
     *
     * @return array<Text|TextEntry>
     */
    private static function events(Get $get, ?Page $record): array
    {
        $previews = app(TrackingPreview::class)->forConfig(Events::asConfig($get), $record);

        if ($previews === []) {
            return [
                Text::make('This Page reports nothing. It carries no tracking script at all and serves exactly the document you wrote.'),
            ];
        }

        return array_map(self::event(...), $previews);
    }

    /**
     * @param  array{destination: Destination, moments: list<TrackingMoment>, event: string, payload: array<string, mixed>}  $preview
     */
    private static function event(array $preview): TextEntry
    {
        /** "or", never "and": an event several Moments produce is reported at one of them and never at both. */
        $moments = implode(' or ', array_map(
            fn (TrackingMoment $moment): string => $moment->getLabel(),
            $preview['moments'],
        ));

        /**
         * The encoded document is handed to `state()` rather than formatted
         * from it: a `TextEntry` reads an array state as a list of values and
         * would print the payload comma-separated with its keys gone — see
         * .ai/rules/resources.md.
         */
        return TextEntry::make('tracking_preview.'.$preview['destination']->getKey().'.'.$preview['event'])
            ->label($preview['event'].' — '.$preview['destination']->label)
            ->state((string) json_encode($preview['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->fontFamily(FontFamily::Mono)
            ->helperText('Reported at '.$moments.'.');
    }
}
