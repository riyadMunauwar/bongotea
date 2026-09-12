# A courier webhook is trusted for narration and never for state

A webhook claiming a Shipment's status changed is read only far enough to find our Shipment by our
own invoice reference; the system then asks the Courier itself and records what it learned from its
own call, exactly as a Payment Notification triggers a Verification and records nothing by itself
(ADR-0013). A webhook carrying only a **message** — Pathao's tracking update, "Package arrived at
the sorting center" — is written to the timeline as text and **can never change a canonical status,
a filter, a figure or a Shipment's Active-ness.** Forged input can add a noise line to one
timeline; it can move nothing.

This is a deliberate, bounded exception to the standing rule that nothing inbound is believed, and
it exists because Pathao's message has no verification endpoint at all. Pathao offers no tracking
history: the message exists only in the webhook that carried it, and there is nowhere to ask
whether it was real. Applying the rule strictly would discard the only narration Pathao provides
and leave its timeline a list of bare status words while RedEx's read like a courier's own tracking
page — a difference no User could account for. Conceding one cosmetic line of text, while keeping
every byte of state behind our own outbound call, is the honest price.

**Considered options.** Trusting webhooks outright was rejected on RedEx's authentication alone:
its only credential is a token in a URL query string, which lands in access logs, proxies and
referrer headers, so anyone who has ever seen the URL could write whatever status they liked onto
Shipments and move the Merchant's reports. Verifying signatures instead was rejected because no
Courier documents a signing scheme — Pathao suggests a bearer header "if authentication is
required" and Steadfast has no webhook at all — so there is nothing to verify against, and the
defence is the same one payments already use: find our own record, then go and ask. Polling only
and ignoring webhooks entirely was rejected because it throws away the one signal that arrives
promptly, and would have to poll hard enough to compensate.

## Consequences

- **Steadfast is polled because it has no webhook**, so polling is its only channel and not a
  backstop. Pathao and RedEx are polled too, at a longer interval, because a webhook that never
  arrives would otherwise stall a parcel silently forever.
- The re-ask interval is the driver's own property, not one global constant, for the same reason a
  Provider owns its batch limit and acceptance window: a Courier that announces needs asking far
  less often than one that does not.
- A Shipment Event's idempotence is a unique database constraint, not a check at the call site.
  RedEx returns its whole tracking history on every poll and re-imports it each time, and a webhook
  may arrive twice; the constraint carries that weight in one place.
- A transport failure or a 5xx is never terminal. The Shipment is left as it was and asked again
  next sweep; only a Courier's own refusal writes a refused outcome, matching how Tracking treats
  an unreachable Provider (ADR-0016).
- A webhook naming no Shipment we hold is ignored and still answered 200, and what is logged is
  named fields — reference, courier, claimed status — never the whole body.
- Because narration cannot move state, a Courier that sends only messages and never a status still
  produces a readable timeline, and one that sends neither still produces a correct Shipment. The
  two channels degrade independently.
