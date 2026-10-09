<?php

namespace App\Models;

use App\Enums\EnquiryStatus;
use App\Observers\EnquiryAlertObserver;
use Database\Factories\EnquiryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question somebody sent through the contact form, kept until a User has
 * called them back and closed it. Not an Order and not a Customer: asking
 * commits nobody to anything.
 *
 * @property EnquiryStatus $status
 */
#[Fillable(['name', 'phone', 'topic', 'message'])]
#[ObservedBy(EnquiryAlertObserver::class)]
class Enquiry extends Model
{
    /** @use HasFactory<EnquiryFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => EnquiryStatus::New->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => EnquiryStatus::class,
            'handled_at' => 'datetime',
        ];
    }

    /**
     * The User who last moved it on from New. Read through filed-away Users,
     * so the name a call was made under outlives the account.
     *
     * @return BelongsTo<User, $this>
     */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_id')->withTrashed();
    }

    /**
     * Whether a User may move it there from where it stands: Replied only
     * from New, Closed from anything not yet Closed, and New — reopening —
     * only from Closed.
     */
    public function canMoveTo(EnquiryStatus $status): bool
    {
        return match ($status) {
            EnquiryStatus::Replied => $this->status === EnquiryStatus::New,
            EnquiryStatus::Closed => $this->status !== EnquiryStatus::Closed,
            EnquiryStatus::New => $this->status === EnquiryStatus::Closed,
        };
    }

    /**
     * Move it to Replied or Closed in the User's name, or back to New, which
     * forgets who handled it: a reopened Enquiry is one nobody has called yet.
     */
    public function moveTo(EnquiryStatus $status, User $user): void
    {
        $handled = $status !== EnquiryStatus::New;

        $this->forceFill([
            'status' => $status,
            'handled_by_id' => $handled ? $user->getKey() : null,
            'handled_at' => $handled ? now() : null,
        ])->save();
    }
}
