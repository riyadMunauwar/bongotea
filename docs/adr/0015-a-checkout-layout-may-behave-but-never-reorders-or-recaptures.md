---
status: accepted
---

# A Checkout Layout may behave, but never reorders the document or changes what is captured

The ten Checkout Layouts drawn for the shortcode include two that are not arrangements alone:
Stepper shows one panel at a time behind Next and Back, and Accordion opens one section at a time
and writes a summary into each collapsed header. Neither can be drawn honestly with rules alone.
We decided a layout may carry behaviour, taken from the same inline script the Checkout already
ships for totals and submit and keyed off the layout's modifier class, on two conditions that do not
move: the document is written in the same order for every layout, and without script every panel
shows in that order and the form submits the same complete Order. ADR-0009 stands untouched on the
side it cares about — a layout still adds a block of rules to one shared base sheet, shipped only to
Pages that draw it, and still generates nothing from a Merchant's figures.

## Considered options

- Drop Stepper and Accordion and ship eight. Rejected: they are among the most distinctive of the
  ten, and the merchant asked for all ten as drawn.
- Fake them with hidden radio inputs and sibling selectors, no script. Rejected: fragile, breaks
  keyboard and screen-reader use, and the Checkout already runs script for the same visitors.

## Consequences

- The rule that "arrangement is CSS only" narrows to "arrangement never reorders the document and
  behaviour never changes capture". A test renders each behaving layout and asserts every panel is
  present in document order before any script runs.
- A layout's behaviour lives in the one inline script, behind its modifier class, so a Page arranged
  otherwise pays for nothing it does not draw beyond the shared script's bytes.
