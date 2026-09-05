# GioPages

A performance-first landing-page ecommerce system. A merchant authors landing pages, attaches products to them, and takes cash-on-delivery orders from paid traffic.

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
The form on a Page that captures a cash-on-delivery Order. There is no cart and no multi-step flow: a Checkout captures one Order in one submission. Every Page has its own Checkout, defined by its own Checkout Config.
_Avoid_: cart, basket, funnel

**Checkout Config**:
Everything one Page decides about its own Checkout: what it asks for, what it charges for delivery, which Products it offers and how it looks. A Config belongs to a single Page and is complete on its own, so changing it changes that Page and nothing else.
_Avoid_: settings, preset, theme, template

**Checkout Field**:
One thing a Checkout asks a Customer for. Built-in fields, which the system knows by name, and the Merchant's own sit in one ordered list, presented and validated alike.
_Avoid_: input, form field, custom field, attribute

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

**Capture**:
The moment a visitor submits the Checkout and an Order comes into existence. What was agreed at Capture — the products, their prices, and the totals — is fixed from then on.
_Avoid_: conversion, submission

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
An Order that genuinely happened but will not be fulfilled — an unreachable number, a changed mind, a refused parcel. Distinct from a deleted Order, which is one that should never have existed at all.
_Avoid_: rejected, void, failed, refunded

### People

**User**:
Someone who signs into the admin to author Pages and work Orders. A User is never a Customer; the two never overlap.
_Avoid_: admin, operator, staff

**Merchant**:
The business running an installation of GioPages. Relevant because the system is self-hosted by many merchants, so anything a merchant would reasonably differ on — currency, the list of Districts — belongs in configuration rather than baked into the system.
_Avoid_: tenant, client, store owner
