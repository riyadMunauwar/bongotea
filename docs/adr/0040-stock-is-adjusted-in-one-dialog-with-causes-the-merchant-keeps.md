# Stock is adjusted in one dialog, with causes the Merchant keeps

Supersedes the three entry paths of #174 (Set exact count, Increase, Decrease) and the fixed list of
causes on `AdjustmentCause`. Every Adjustment is still exactly one Stock Movement of the difference,
written by `StockLedger` and nothing else (ADR-0021); only how a Merchant arrives at it, and the
words it carries, changed.

**One dialog.** With On Hand in front of them, the Merchant picks what happened — **Add**,
**Remove**, or **I counted it** (the total now) — each explained in a line under it, then a unit and
a quantity. The unit is packets of one of the Product's Packs, or kilograms for unpacked tea or a
shelf of mixed sizes; only the grams are kept (ADR-0035), since a Pack holds no stock of its own. It
is worked out against the shelf at the moment of saving: an Add or Remove is applied as a change, a
count is squared as a total. A Product nobody has counted offers only I counted it. The three
buttons it replaced were the same act from three sides with names Merchants could not tell apart;
one form with the three plainly explained was what they could use. (A first cut with two linked
rows — change and total — each broken down into every Pack plus loose tea was judged too much to
take in, and replaced before release.)

**No blind count.** Set exact count hid On Hand so a counter could not just agree with it. That
earns its keep where one person counts and another keeps the books; a tea Merchant counting their
own shelf gained nothing from it, and it was the reason the act needed a button of its own.

**Causes are records the Merchant keeps.** Each has a name and says whether it adds stock, removes
it, or may do either, and the dialog offers only those that fit the change. A cause is added from
the dialog's own picker, renamed or retired on a screen of its own, and deleted only while nothing
carries it. Opening count (a Product's first figure) and Checked (a count that agreed) are not
causes at all: they are facts the ledger can see, so the Stock Count records them and the Merchant
never picks them.

## Considered options

- **Keep the fixed list in code.** Rejected: every shelf has a reason the list did not foresee
  (tasting samples, tea taken for the house), and they all ended up as "Lost" with a note.
- **Free text.** Rejected: one reason spelled five ways cannot answer "what am I losing to damage
  this year", which is the question the cause exists for.
- **Add and Remove only.** Rejected: a Merchant who counted the shelf would have to work out the
  difference themselves, and a Product nobody has counted has nothing to add to.

## Consequences

- `stock_counts.cause` became `adjustment_cause_id` (nullable) and `is_opening`. A Count whose
  difference is nothing carries no cause; an opening count carries none either, and the ledger
  refuses one given, as it refuses a cause that does not fit the change's direction or a non-zero
  change without one.
- A change against a Product nobody has counted is refused: an unknown has nothing to change from,
  so a first figure is always what is there now.
- The existing Counts were carried over onto seeded causes of the same names, and Opening count
  onto `is_opening`.
