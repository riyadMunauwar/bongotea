# The fingerprint is split: what the courier collects, and nothing else

> **Superseded by [ADR-0029](0029-the-cart-lives-in-the-browser-and-capture-checks-a-quote.md).** The Pricing Fingerprint is replaced by a Quote checked at Capture.

Everything that determines the figure handed over at the door — the linked Product ids, their
prices, and the Delivery Option amounts — is fingerprinted into the rendered form and re-checked at
Capture. A mismatch is refused outright, with a message telling the Customer to reload. Nothing else
about the Checkout Config is in that fingerprint. A field that was added, relabelled or restyled
since the visitor loaded the page is validated against the configuration as it stands now, with a
page-level fallback so an error the older form has no slot to show is never dropped in silence.

The reason there is a check at all is that a visitor is very often holding HTML written to disk days
ago. Pages are served from the static cache (ADR-0004), the body keeps its shortcode until a render
(ADR-0001), and an invalidation only clears the file — it cannot reach the tab already open on
someone's phone. That gap is a live defect today, not one this work introduces: repricing a Product
clears the cache, and a visitor holding the old HTML is still captured at the new price. It is the
refused-parcel scenario the Order Line snapshot was built to prevent, arriving through a different
door.

This narrows a claim ADR-0001 makes in passing. "Invalidation alone is enough" holds for what is
*stored* — no HTML has to be rewritten, and the next render picks up the new price — but it was
never enough for HTML already delivered, and that ADR should be read as saying only the first
thing.

The split itself is a deliberate asymmetry between the two ways of being wrong. Collecting an amount
the Customer never agreed to is a refused parcel, courier fees in both directions, and a Merchant
paying to be told no. Capturing an Order against a form missing a colour change is nothing.

## Consequences

- The rejection is the only failure a Customer cannot fix by editing a field, so it gets a message
  of its own rather than an error hung on a field.
- A Merchant editing prices or delivery amounts mid-campaign knowingly rejects the submissions of
  visitors mid-form. That is the intended outcome — those are precisely the submissions that would
  otherwise be captured at a price nobody saw.
- Adding, reordering, relabelling or restyling a field never rejects anything. This is what makes
  the Checkout tab safe to touch on a Page that is spending money, and it is most of what a Merchant
  actually edits.
- Live validation means the server may receive an answer to a field that no longer exists, or none
  for a field that has just appeared and is required. Ignoring the first and failing the second is
  the current configuration being authoritative, which is what "validated live" means.
- The rendered form has to carry the fingerprint where the check can read it back, which is what
  makes "the form and the server agree" a property anything can observe from outside.

## The alternatives, and why not

Fingerprinting the whole Config. It is simpler to state, simpler to compute, and it is the version
that will occur to the next reader. It also means every save on the Checkout tab invalidates every
form currently open, so a Merchant nudging a colour at 9pm rejects the Orders of everyone mid-form
on traffic they are paying for, and the refusal reads to the Customer exactly like a price change.
The blast radius has no relation to the harm being prevented.

Fingerprinting nothing and trusting the submission against current configuration. That is today's
behaviour, and today's defect: it captures at the new price against HTML showing the old one. What
is priced can never be accepted from the request, and re-reading it from the Page is not enough
either — the Page is what changed.
