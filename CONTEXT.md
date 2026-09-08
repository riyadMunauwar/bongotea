# GioPages

A performance-first landing-page ecommerce system. A merchant authors landing pages, attaches products to them, and takes orders from paid traffic, paid at the door or online.

This glossary is the shared vocabulary for that domain. It says what each term means, not how anything is built.

## Language

### The selling surface

**Page**:
A publicly reachable landing page, authored as a complete HTML document and served at its own slug. A Page is either a draft or published; only a published Page is reachable.
_Avoid_: post, article, template, funnel step

**Slug**:
The flat path a Page is served at, and its permanent public identity. A slug is claimed for good once used, because it may be baked into ad creatives that outlive the Page itself.
_Avoid_: permalink, path, URL

**Went live**:
The moment a Page first became reachable at its slug. A Page can be pulled back to draft and published again, but it went live only once — the date is what a Merchant matches a Page against ad spend, so it is the first one, never the most recent.
_Avoid_: launched, activated, last published

**Product**:
Something a Customer can order, carrying a single price and no variations. Products have no public address of their own; they are only ever reached through a Page.
_Avoid_: item, SKU, variant, listing

**Checkout**:
The form on a Page that captures an Order, and asks how it will be paid when the Page offers more than one way. There is no cart and no multi-step flow: a Checkout captures one Order in one submission; an online payment happens after Capture, never before it. Every Page has its own Checkout, defined by its own Checkout Config.
_Avoid_: cart, basket, funnel

**Checkout Config**:
Everything one Page decides about its own Checkout: what it asks for, what it charges for delivery, which Products it offers, which Payment Methods it offers and in what order, and how it looks. A Config belongs to a single Page and is complete on its own, so changing it changes that Page and nothing else. Anything shared by every Page is a Setting, not part of a Config.
_Avoid_: settings, preset, theme, template

**Checkout Layout**:
How a Checkout is arranged: what it draws, and nothing about what it asks for or what it charges. Five of them — stacked, split, compact, summary-first, and a form-only one with no product panel, for a Page whose own markup has already sold the Product above the fold. Every question, the delivery choice, the summary and the failure slots are drawn by all five, and a form-only Checkout captures the same complete Order: it sells everything the Page sells, one of each.
_Avoid_: template, theme, skin, variant

**Field Width**:
How much of a row one Checkout Field takes: the whole of it, or half, so that two short questions sit side by side. A width is a choice between those two and never a figure, and it is a promise about wide screens only — below the breakpoint every Field fills the row, with nothing a Merchant can say to change it, because the traffic these Pages answer is overwhelmingly phone-sized. A compact Checkout Layout fills the row at every width, because a Merchant choosing compact has already said what they want the form to do and paired questions work against it. A width is kept either way, so a Page moved off compact asks its questions the way it always did.
_Avoid_: column, span, size, grid

**Style Token**:
One thing about a Checkout a Merchant can restyle — a colour, a length, a size. Every token is set once for the whole Checkout and may be overridden by a single Checkout Field, so a form is styled in one place and a question that must look different says only how it differs. Tokens belong to one Page's Checkout Config like everything else in it: nothing a Merchant sets reaches another Page.
_Avoid_: theme, variable, style setting, CSS variable

**Order Button**:
What a Checkout's button says, and the line beneath it, in the Merchant's own words. The wording may carry a total placeholder, filled in with what the courier will collect so the price sits on the button at the moment a Customer taps it. Both belong to one Page's Checkout Config; a Page that says nothing keeps the words the button has always had. Copy and not a figure, so neither is in the Pricing Fingerprint: a Merchant editing a word on a live Page never turns away an Order somebody has half-typed.
_Avoid_: CTA, call to action, submit label, microcopy

**Product Picker**:
Said of a Checkout that asks the Customer which of its Page's Products to buy, rather than selling all of them together. What it opens with is the Page's Default Selection. A Page down to a single Product asks nothing and sells it: a choice of one is not a choice, and neither is one on a Checkout Layout that draws no Products to pick between.
_Avoid_: cart, add-on, bundle, upsell toggle

**Default Selection**:
Which of a Page's Products its Product Picker opens ticked. None unless the Merchant says otherwise. One is a default the Customer sees a price from and then changes; several is a bundle the Merchant is asserting, and carries the risk the empty default exists to avoid — on a cash-on-delivery Page, an extra nobody chose is not a larger sale, it is a refused parcel. It settles nothing about what an Order costs; what was ticked at Capture does.
_Avoid_: preselected, default cart, bundle, preset

**Checkout Field**:
One thing a Checkout asks a Customer for. Built-in fields, which the system knows by name, and the Merchant's own sit in one ordered list, presented and validated alike.
_Avoid_: input, form field, custom field, attribute

**Field Constraints**:
What a Merchant has said an answer to one of their own Checkout Fields must be, chosen from a fixed vocabulary: a shortest and a longest for writing, a smallest and a largest for a number, whole numbers, and a shape picked from a named list. There is no rule a Merchant writes themselves anywhere, because a malformed one shows up as a conversion rate rather than as an error. One definition compiles into what the browser enforces and what the server checks, so the two can never disagree. A built-in field has none: what its column will hold is the built-in's to say.
_Avoid_: validation rules, rule string, regex, pattern string, constraints list

**Switched off**:
Said of a Checkout Field its Page has stopped asking for. It is absent from the form and from what the server checks, and an Order taken without it records nothing rather than a blank. It keeps its wording and its rules meanwhile, so switching it back on restores the question as it was — switched off and not compulsory are separate things, and a compulsory field that is switched off is simply inert. Phone is the one field that cannot be switched off, because a Customer is their phone number.
_Avoid_: disabled, hidden, removed, deleted, inactive, optional

**Shortcode**:
A placeholder a Merchant writes into a Page's HTML that the system replaces with generated content when the Page is rendered. It is the only way a Page gets content the Merchant did not type themselves.
_Avoid_: tag, macro, snippet, token, embed

**Block**:
A Shortcode's content chosen from an editor rather than typed as text. A Block and a Shortcode are the same definition reached two ways, never two systems: whatever a Shortcode can produce, a Block produces identically.
_Avoid_: widget, component, element, section

### Orders

**Order**:
A Customer's committed request for products, captured on a Page at an agreed price. An Order records everything it needs to be fulfilled without consulting anything else.
_Avoid_: purchase, transaction, sale, lead

**Order Line**:
One Product's worth of an Order, recording what was sold, at what unit price, and in what quantity, as agreed at Capture. A Product is a reference from an Order Line, never a source of truth for it.
_Avoid_: line item, order item, cart item

**Answer**:
What a Customer said to one of the Checkout Fields a Merchant added of their own, as an Order records it: the field's key, the wording it was asked in, the control it was asked with, the value, and for a choice the wording of the option picked. A built-in field's answer is not this — it goes to the column that has always held it. A question asked and left alone is recorded with nothing for a value, which is a different thing from a question that was never asked.
_Avoid_: custom field, meta, extra data, attribute

**Capture**:
The moment a visitor submits the Checkout and an Order comes into existence. What was agreed at Capture — the products, their prices, and the totals — is fixed from then on.
_Avoid_: conversion, submission

**Pricing Fingerprint**:
What a Checkout quoted, reduced to a value the rendered form carries and a Capture checks back: the Products the Page sells, their prices, and what each Delivery Option costs. A submission whose fingerprint is not the Page's current one is refused outright and the Customer told to reload, because a visitor may be holding a Page rendered days ago and the figures on their screen would otherwise not be the figures collected at the door. Nothing else about a Checkout is in it — a question added, reworded or restyled since leaves it alone, so editing those never turns away an Order somebody has half-typed.
_Avoid_: hash, checksum, nonce, version, signature

**Customer**:
A person identified by their phone number, and nothing else. A Customer holds no name and no address; those belong to each Order they placed, because a phone number is an identity, not a person.
_Avoid_: user, account, buyer, client, lead

**District**:
The delivery region agreed with the Customer at Capture, distinct from the free-text address that tells a courier where the door is. It is optional fulfilment data, captured because couriers want a structured region that cannot be recovered from an address afterwards, and it settles nothing about what an Order costs.
_Avoid_: zone, area, thana, city

**Delivery Option**:
One delivery arrangement a Page offers, named and priced by the Merchant — "Inside Dhaka", "Outside Dhaka", "Free". A Page may offer none, one or several; which one is agreed at Capture is what a Delivery Charge comes from.
_Avoid_: shipping method, delivery zone, tier, rate

**Delivery Charge**:
The amount added to an Order for getting the parcel to the door, taken from the Delivery Option agreed at Capture. It is part of what the courier collects, and it is agreed at Capture like every other figure on the Order.
_Avoid_: shipping cost, courier fee, postage

**Cancelled**:
An Order that genuinely happened but will not be fulfilled — an unreachable number, a changed mind, a refused parcel. Distinct from a deleted Order, which is one that should never have existed at all. Cancelling says nothing about money: a Payment that completes against a Cancelled Order is recorded Paid, and the Order is both at once until somebody refunds it.
_Avoid_: rejected, void, failed, refunded

### Paying

**Payment Method**:
One of the named ways an Order can be paid, known to the system by name: Cash on Delivery and the online gateways. The Merchant switches each on for the installation and supplies what the gateway needs; a Page then chooses which of the switched-on ones it offers, and in what order. A method the installation has not switched on, or cannot reach, is offered nowhere, whatever a Page says.
_Avoid_: gateway, payment option, payment provider, driver

**Cash on Delivery**:
The Payment Method where the courier collects the total at the door. The one method every Page starts with, the one a Page falls back to when nothing else it lists can be offered, and the one that needs no Payment: an Order paid this way is simply due.
_Avoid_: COD-only, offline, manual

**Payment**:
One attempt to collect an Order's total through one online Payment Method, from the moment the Customer is sent to the gateway until the gateway's answer is verified. An Order may hold several; one is meant to end Paid, and a second that does is recorded Paid all the same and flags the Order as paid twice, because money moved and somebody must refund it. A Payment records the amount it was for, which is the Order's total today and need not always be. Its outcome is its own axis, separate from whether the Order is New, Confirmed or Cancelled: neither ever overwrites the other.
_Avoid_: transaction, charge, payment attempt, payment status (of an Order)

**Paid**:
Said of a Payment the gateway has been asked about and has confirmed, and by extension of the Order it belongs to. Only a Verification the system made itself can say it; what the gateway wrote into the Customer's return URL is never enough.
_Avoid_: successful, completed, settled

**Pending**:
Said of a Payment the Customer has been sent to the gateway for and nothing has been verified about yet. A Pending Payment older than a day that still cannot be verified is Expired, and an Expired or Failed Payment can be retried like any other.
_Avoid_: processing, in progress, awaiting

**Unpaid**:
Said of an Order that chose an online Payment Method and has no Paid Payment: none started, or every one Pending, Failed, Cancelled by the Customer, or Expired. An Unpaid Order sits in the fulfilment queue like any other, drawn distinctly, because the call an operator makes about it is a different call.
_Avoid_: abandoned, incomplete, outstanding

**Verification**:
The system asking a gateway, in a call of its own, whether a Payment is Paid. It is the only thing that can mark one so, and it runs from three places that all say the same thing: the Processing Page, a Notification, and the sweep over Pending Payments.
_Avoid_: callback, validation, IPN check

**Notification**:
A gateway telling the system on its own that something happened to a Payment. Treated as a hint and never as an answer: it triggers a Verification and records nothing by itself.
_Avoid_: webhook, IPN, callback

**Processing Page**:
Where a gateway sends a Customer back. It says the Payment is being verified, runs the Verification, then sends the Customer to the Thank-you Page or the Retry Page. It prints nothing about the Customer, because it is the one address a gateway's own logs hold.
_Avoid_: callback page, return URL, success page

**Thank-you Page**:
Where a Customer lands once their Order is settled as far as the Checkout is concerned: straight after a Cash on Delivery Capture, or once a Payment is Paid. Addressed by order number alone, and shows the Order and not the Customer.
_Avoid_: confirmation page, success page, receipt

**Retry Page**:
Where a Customer lands with an Unpaid Order: after a Failed, Cancelled or Expired Payment, or after a Capture that chose a method the Page no longer offers. It shows the Order and offers the Page's Payment Methods again, Cash on Delivery among them if the Page offers it. Addressed by order number alone, like the Thank-you Page.
_Avoid_: failure page, error page, payment page

### People

**User**:
Someone who signs into the admin to author Pages and work Orders. A User is never a Customer; the two never overlap.
_Avoid_: admin, operator, staff

**Merchant**:
The business running an installation of GioPages. Relevant because the system is self-hosted by many merchants, so anything a merchant would reasonably differ on — currency, the list of Districts — belongs in configuration rather than baked into the system.
_Avoid_: tenant, client, store owner

### The installation

**Settings**:
The values a Merchant decides once for the whole installation rather than per Page: how the Merchant is reached by payment providers and couriers, and anything else that would be the same on every Page. A Setting is never part of a Checkout Config; if a Page could reasonably differ on it, it is not a Setting.
_Avoid_: config, options, preferences, site settings, global settings
