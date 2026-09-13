<?php

namespace App\Support\Couriers;

/**
 * What a User saw and confirmed before the parcel went: the shared details as
 * they corrected them, and whatever the chosen Courier asked of them.
 *
 * The dialog's whole answer in one value, so the booking takes one argument
 * rather than two loose arrays, and so a booking made with nobody in front of
 * it — a swept batch — is `none()` and reads as exactly that.
 *
 * A shared detail left blank is a detail the Checkout never captured, never an
 * instruction to erase one it did: it is dropped here, the Order keeps what it
 * had, and the booking falls back to what it has always done with a missing
 * name or address. The note is the exception and `noteOr()` says why.
 */
final class ParcelDetails
{
    /**
     * @param  array<string, mixed>  $courierFields  the chosen Courier's own answers, by name: a `ParcelField` each, and the delivery area where that Courier insists on one
     */
    private function __construct(
        public readonly ?string $recipientName,
        public readonly ?string $recipientAddress,
        public readonly ?string $recipientDistrict,
        public readonly ?string $note,
        private readonly array $courierFields,
        private readonly bool $wasAsked,
    ) {}

    /**
     * @param  array<string, mixed>  $courierFields
     */
    public static function confirmed(
        mixed $recipientName = null,
        mixed $recipientAddress = null,
        mixed $recipientDistrict = null,
        mixed $note = null,
        array $courierFields = [],
    ): self {
        return new self(
            self::given($recipientName),
            self::given($recipientAddress),
            self::given($recipientDistrict),
            self::given($note),
            $courierFields,
            wasAsked: true,
        );
    }

    /** Nothing was confirmed, because nobody was asked. The Order goes as it stands. */
    public static function none(): self
    {
        return self::unasked();
    }

    /**
     * Nobody was asked, and the system worked out for itself the one thing a
     * Courier will not take a parcel without.
     *
     * A batch is booked with nobody in front of it, so a Courier that insists
     * on naming the delivery area itself is answered from the address rather
     * than from a User (`DeliveryAreaMatcher`) — and a guess it cannot make is
     * a parcel that waits, never one sent to whichever depot an empty answer
     * resolves to. Everything else about the parcel is still the Merchant's
     * standing default, and the note is still the Customer's own, exactly as
     * for a booking nobody was asked about at all.
     *
     * @param  array<string, mixed>  $courierFields
     */
    public static function guessed(array $courierFields): self
    {
        return self::unasked($courierFields);
    }

    /**
     * @param  array<string, mixed>  $courierFields
     */
    private static function unasked(array $courierFields = []): self
    {
        return new self(null, null, null, null, $courierFields, wasAsked: false);
    }

    /**
     * What this rider is to be told, against the note the Customer left on the
     * Order.
     *
     * A dialog that was answered is the whole answer: a User who cleared the
     * box meant that this parcel carries no note, not that the Customer's note
     * should go out again. A booking nobody was asked about sends what the
     * Customer wrote.
     */
    public function noteOr(?string $captured): ?string
    {
        return $this->wasAsked ? $this->note : $captured;
    }

    /**
     * What the User answered to one of the chosen Courier's own questions, or
     * nothing where that question was never put to them.
     */
    public function answerTo(string $name): mixed
    {
        return $this->courierFields[$name] ?? null;
    }

    /**
     * Which of the Courier's own delivery areas the User confirmed this
     * parcel is going to, or nothing where they were not asked or left it
     * unanswered.
     *
     * An answer like any other and so kept with the rest, rather than a
     * property of its own: only a Courier that says it `needsAreaResolution()`
     * ever asks, and what it is asked under is that Courier's section of the
     * dialog.
     *
     * Read as any scalar and not only as a string, unlike the shared details
     * above: a chooser hands an id made of digits back as a number, and an id
     * is a word a Courier chose rather than a figure.
     */
    public function deliveryArea(): ?string
    {
        $chosen = $this->answerTo(DeliveryArea::ANSWER);

        return is_scalar($chosen) ? self::given((string) $chosen) : null;
    }

    /**
     * The Order's own columns as the User corrected them, ready to be filled
     * onto it.
     *
     * Corrections to a shared detail are saved onto the Order as well as being
     * sent, so the next person to open it sees the address we now know is
     * right rather than the one the Customer mistyped. The phone is
     * deliberately not among them: a Customer *is* their phone number, so
     * changing it moves the Order to a different Customer, which is the
     * editor's decision to make and not a booking's.
     *
     * @return array<string, string>
     */
    public function corrections(): array
    {
        return array_filter([
            'customer_name' => $this->recipientName,
            'customer_address' => $this->recipientAddress,
            'customer_district' => $this->recipientDistrict,
        ], fn (?string $value): bool => $value !== null);
    }

    private static function given(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
