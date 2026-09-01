# A Shortcode and a Block are one definition reached two ways

The block builder is the product, but v1 ships only shortcodes. To stop the two becoming parallel
systems that drift, they share a **node** — a `{name, attributes}` pair — rather than sharing a
renderer. A Shortcode Definition declares its name, an attribute schema, and a single `render()`
that takes a node and returns visitor-independent HTML. A registry maps name to definition. The
parser's only job is turning `[checkout]` in authored HTML into a node.

The trap this avoids is assuming the shared thing is the renderer — writing `[checkout]`'s renderer
now and teaching a block builder to call it later. That quietly produces two systems, because a
block builder needs something a shortcode never had: a form for the merchant to edit attributes in.
The attribute schema is what closes that gap. It is what the parser validates against today, and
what a Filament repeater generates its form fields from later.

## Consequences

- A block builder is additive: a second producer of nodes, plus a form generator over schemas that
  already exist. No definition needs rework.
- A Block and a Shortcode cannot render differently, because there is literally one `render()`.
- v1 pays for a schema mechanism it does not exercise. `[checkout]` is the only registered
  definition and declares no attributes, so the schema ships unproven until a second one lands.
  Accepted deliberately: the schema is the part that makes the block builder possible, and adding
  it after definitions exist means revisiting every one.
