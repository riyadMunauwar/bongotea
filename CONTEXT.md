# Bongo Tea

A wholesale tea store. Bongo Tea sells tea by the kilogram to businesses — tea stalls, restaurants, hotels, offices — and takes their orders online. Some of its teas are also sold in Packs, to people buying for themselves.

> This glossary is being rewritten from GioPages, the landing-page system this codebase began as. Terms below not yet revisited still describe GioPages.

This glossary is the shared vocabulary for that domain. It says what each term means, not how anything is built.

## Language

### The selling surface

**Page**:
A publicly reachable page of the store, made of Blocks arranged in the editor and served at its own Slug. A Page is either a draft or published; only a published Page is reachable. A Product, a Category, the Checkout and an Order are never Pages: what they show comes from the thing they are about, so nobody authors them.
_Avoid_: post, article, template, landing page, screen

**Block**:
One section of a Page, chosen from a fixed set of kinds and filled in from the editor — a hero carousel, a strip of promises, a row of reviews, the bulk order form, the price matrix. Some kinds only say what the Merchant typed; others do something, and those take only what to call things and what to show, never figures: Products, prices, Tiers and Delivery Bands are always read as they are now, so no Block can quote a price that has since changed. Any kind can be placed on any Page.
_Avoid_: shortcode, widget, component, element, section

**System Page**:
A Page the store cannot do without — the home page, the bulk order page, the customer's orders page — created when the store is installed and known by the job it does rather than by its title or Slug. It is an ordinary Page in every other way, its Blocks rearranged and reworded like any other's, but it can be neither deleted nor given another job, so that whatever links to "the bulk order page" always finds one. There are three: the home page, which is what the bare domain serves; the bulk order page, which is where the Cart is seen and changed; and the orders page, where a Customer Signs in. Every other Page the store opens with — pricing, about, contact — is an ordinary one that happens to be written already.
_Avoid_: core page, fixed page, default page, built-in page, front page

**Slug**:
The part of an address that names one Page, Product or Category, and its permanent public identity. A slug is claimed for good once used, because it may be baked into ads, posts and chats that outlive the thing itself; renaming changes what something is called, never where it is.
_Avoid_: permalink, path, URL

**Product**:
Something a Customer can order, always sold by the Kilogram and carrying a price in every Tier, and sold in Packs as well once the Merchant gives it any — a Product with at least one Pack is open to retail, and there is no retail-only Product. Every Product has a public address of its own at its Slug, where it is described in full. A Product taken out of the catalogue keeps its address and says it is no longer sold there, because links to it in old posts and chats outlive it.
_Avoid_: item, SKU, variant, listing

**Pack**:
A sealed retail size of one Product — 100 g, 200 g, 250 g — sold whole and counted, never weighed out. A Product may come in several Packs, each with a weight, an MRP and a Pack Price of its own; only a Product the Merchant has opened to retail has any. A Pack Price never exceeds its Pack's MRP, and the saving is read off the two, never typed. A Pack is known by its weight and nothing else — no two Packs of one Product weigh the same, and its name is its weight, never typed — and its weights are set on its Product, from no store-wide list of sizes. There is no quantity break on Packs: five cost five times one. A Pack has no stock of its own: it is weighed out of its Product's stock when the order is packed, so a Pack shipped takes its weight off the same shelf a Kilogram does. A Pack is not a Product of its own and not a Kilogram: it is how the same tea is sold to somebody buying for their own cup rather than for a business. A Customer reads it as প্যাকেট. Wherever a Customer chooses between the two ways of buying, bulk reads as পাইকারি and retail as খুচরা — the same pair on every card, page and button, so the word that sends them somewhere is the word they find when they get there.
_Avoid_: variant, size option, SKU, unit, sachet

**Available**:
Said of a Product that can be ordered right now — a decision the Merchant makes by hand, and never one the stock ledger makes for them, because a shelf nobody has counted is not an empty one. An unavailable Product is still shown wherever it would be, greyed and saying it cannot be had at the moment, because a regular buyer looks for the tea they always buy. Not the same as taken out of the catalogue, which is for good; unavailable is for now. A Pack is Available too, by its own switch, and only while its Product is: a Product made unavailable takes every Pack with it, but a Pack switched off — the pouches ran out — leaves the Product and its bulk sale untouched.
_Avoid_: in stock, out of stock, enabled, active, sold out

**Category**:
The one line a Product is sold under, named for who it is made for — Tea Stall Premium, Tea Stall Classic, Family Blend — and the way the store is organised around it: what a Customer browses by, what the order form groups by, and what carries its own description and its own colours wherever it is drawn. Every Product is in exactly one, and Categories do not nest. Its colours are a Category Theme chosen for it, never colours typed in one by one. Each has a public address of its own at its Slug, listing its Products under its own description.
_Avoid_: segment, grade, series, collection, department, type

**Category Theme**:
One designed set of colours a Category wears wherever it is drawn — its pill, its panel, its accent, its ticks — chosen by name from the few the store ships with, Premium, Gold and Silver among them. A theme is designed as a whole and never assembled colour by colour, so a Category can look only as the design meant one to; a new theme is a design decision, not a setting.
_Avoid_: palette, colour scheme, skin, style, colours

**Tag**:
A use a Product is good for — milk tea, plain tea, strong liquor, hotels — that a Customer can filter by. A Product may carry any number, or none. A Tag says nothing about price, grouping or appearance; it only narrows a list. Not the "best for" phrases on a Product's own page, which are that Product's copy and narrow nothing.
_Avoid_: label, attribute, use case, category, filter

**Characteristic**:
One fact about what a Product's tea is like — how it is packed, its tea type, its Particle size, how many cups a Kilogram makes, its strength, its Grade, its liquor colour, aroma and body, its infusion — shown on the Product's own page and nowhere a list is narrowed, the first four under মৌলিক তথ্য and the rest under চায়ের গুণগত মানের বিবরণ. Ten are fixed: every Product has room for each, under the same name and in the same order, so the same fact always reads the same way from one tea to the next. Beside them a Merchant may write any number of custom Characteristics on one Product alone, each a name and a value of their own, drawn after the fixed ones under অন্যান্য; one that turns out to belong on every Product is made a fixed one rather than retyped. A Characteristic left blank is simply not shown. Not a Tag: a Tag narrows a list, a Characteristic only describes.
_Avoid_: attribute, spec, property, feature, custom field

**Storage tip**:
One piece of advice on how a Customer should keep a Product's tea once it arrives — a short heading and a line explaining it, such as শুষ্ক স্থানে রাখুন: ঠান্ডা ও আর্দ্রতামুক্ত স্থানে রাখুন। Each Product holds its own, in its own order; a new Product starts with the four common ones already written, and the Merchant changes them only where a tea needs keeping differently. Not a Characteristic: a Characteristic says what the tea is like, a Storage tip tells the Customer what to do with it.
_Avoid_: storage instruction, care note, attribute

**Grade**:
The size of a tea's particles as the trade names it — Leaf, BOP, PD, Dust, CD — which decides how fast it brews, how quickly its colour comes and what it is good for. It is a fixed Characteristic read by its trade name, and not the Particle size: a Grade is the trade's name for the cut, a Particle size is how big the grain looks to a Customer, and the two sit side by side. Never a Category: a Category is who a tea is made for, a Grade is what its leaf is like, and one Category may hold teas of several Grades.
_Avoid_: particle size, segment, quality, category

**Particle size**:
How big a tea's grain looks, in the plain words a Customer uses — ছোট, মাঝারি, বড় — shown as দানার আকার. A fixed Characteristic beside the Grade, never instead of it: "Leaf" and "মাঝারি" answer different questions about the same tea.
_Avoid_: grade, cut, granule

**Strength**:
How strong a Product's liquor is, as one of ten grades read the way a tea trader says them: ৫+, ৫, ৫-, ৪+, ৪, ৪-, ৩+, ৩, ৩-, ২+, strongest first. A plus or minus is a shade either side of the number and never arithmetic, so ৪- is just short of ৪, not less than it by anything. Wherever it is drawn it shows the grade as written beside a bar of five filled to the number alone. A fixed Characteristic, and the only one with a set of answers rather than words. On the Product's own page it is labelled সামগ্রিক রেটিং, and elsewhere কড়া ভাব; the label differs, the meaning never does — it is how strong the liquor is, not how good the tea is.
_Avoid_: strength score, rating, level, intensity

**Brand**:
Whose tea a Product is — Bongo Tea's own, or another company's that Bongo Tea sells alongside it. Every Product has exactly one, Bongo Tea's own teas included, so that "no brand" never quietly means "ours". A Customer sees it on the Product wherever the Product is drawn, and can narrow a list by it once the store sells more than one. Not a Supplier: a Brand is whose name is on the tea, a Supplier is who Bongo Tea bought it from, and one Supplier may carry several Brands.
_Avoid_: manufacturer, vendor, supplier, maker, label

**Tier**:
One of a Product's ranges of the cart's total Kilograms — across every Product in the cart — and the price that Product charges within it. Each Product has its own Tiers, covering every whole kilogram from one upwards without gap or overlap, so the same cart can fall in a different range for each Product: four kilograms of one tea and three of another are seven kilograms, and each tea charges whatever its own Tiers say for seven. It is always the whole cart's Kilograms that are measured, never one line's, because a buyer who mixes teas is buying in bulk. A Product may have a single Tier, which is a flat price, and no Tier may cost more per Kilogram than one before it, because a heavier cart is never charged more. There is no store-wide table of Tiers: a Product's ranges and its prices are set together, on the Product, and nowhere else. The Tiers owe nothing to the Delivery Bands.
_Avoid_: slab, step, level, bracket, price break, quantity discount, default tiers

**MRP**:
A Product's maximum retail price for one Kilogram — or a Pack's, for that Pack — the figure a shopkeeper knows from the pack — that every discount a Customer is shown is measured against. Every Product has one, and no Tier Price may exceed it; a first Tier priced at the MRP simply offers nothing off until a cheaper Tier is reached. It is not a price anything is charged at; the Tier Prices are. A discount is only ever read off the two — the MRP less a Tier Price — and never typed or kept, so moving the MRP changes what a Customer is told they save, never what they pay.
_Avoid_: Regular Price, base price, list price, compare-at price, original price

**Saving**:
What a Customer is told they save: for each line, the MRP less the Tier Price in force, times its Kilograms, added up. Always measured from the MRP, so even a one-kilogram Cart has one when its first Tier sits below the MRP. Not the amount more Kilograms would knock off, which compares one Tier with the next.
_Avoid_: discount total, bulk saving, you-save, markdown

**Tier Price**:
What one Kilogram of a Product costs in one of its Tiers, typed by the Merchant in whole taka — never worked out from a discount, because wholesale tea is quoted as a price per kilogram at a weight, not as money off. The Tier Price in force at Capture is what the Order Line records, so a price changed afterwards moves no Order.
_Avoid_: unit price (as a stored figure), bulk price, wholesale price, rate, tier discount

**Checkout**:
The step that turns a Cart into an Order: it asks where to send it — a name, a mobile number, a District and an address, and optionally a shop's name and a note — and how it will be paid, then captures it in one submission. There is one Checkout for the whole store and it is the same form for everybody, opened for one Cart at a time — bulk or retail, never both at once, so each Capture empties only the Cart it took; a Customer is never asked to sign in to use it. A retail buyer is taken to it the moment they add a Pack, so for retail it is also where the Cart is changed — counts raised, lowered or removed — rather than only read. An online payment happens after Capture, never before it.
_Avoid_: cart, basket, funnel, order form

### Orders

**Order**:
A Customer's committed request for Products, captured from their Cart at an agreed price. An Order records everything it needs to be fulfilled without consulting anything else. It is known by its order number — BT- and six characters drawn at random from ones that cannot be misheard or misread for each other — short enough to say down a phone and impossible to guess the next one of, because an order number alone is enough to open the Order Complete Page. An Order is either bulk — Kilograms, priced by Tiers — or retail — Packs — and never both, because each comes from a Cart of its own; everything after Capture, from Payment to Shipment, treats the two alike. Which it is is settled at Capture and never changes, even by a User editing it: the two are priced by different rules, and Repricing a retail Order reads today's Pack Prices and the retail Delivery Charge. A Price Override on a Pack line follows the same rules as any other, and never exceeds that Pack's MRP.
_Avoid_: purchase, transaction, sale, lead

**Order Line**:
One Product's worth of an Order — or, on a retail Order, one Pack's, recording the Pack's weight as it stood at Capture — recording what was sold, at what unit price, against what MRP, in what quantity and at what unit cost, as agreed at Capture — and which of its Product's Tiers that price came from, as the range stood then, because the Product's ranges may move and "why did they pay that?" must still have an answer. A line under a Price Override records no Tier; its reason says why instead. A Product is a reference from an Order Line, never a source of truth for it. The cost is the weighted average the stock ledger held at that instant, stamped beside the price because **Sales** is counted at Capture too — a margin whose two halves were counted at different moments would swing for reasons that have nothing to do with the business. It never moves afterwards, whatever is bought later, and a Product nothing has been recorded buying stamps none: unknown, never nothing. A line captured before costs were recorded at all keeps none, because a cost invented from today's average would be a figure tracing to no delivery.
_Avoid_: line item, order item, cart item

**Capture**:
The moment a visitor submits the Checkout, or a User saves a new Order taken by phone, and an Order comes into existence. What was agreed at Capture — the Products, their Tier Prices and MRPs, the Category each was sold under, the Saving, and the totals — is fixed from then on, until a User changes what the Order holds.
_Avoid_: conversion, submission

**Repricing**:
What happens when a User changes what an unsent Order holds — a quantity, a line, a Product: the whole Order is priced again as if captured that moment, at today's Tier Prices, for its new total Kilograms, with its Delivery Charge read afresh. The User sees the old total beside the new one before saving, because the next thing they do is tell the Customer. An Order with any Shipment is not changed at all, because what the courier will collect has already been handed to them.
_Avoid_: recalculation, update, edit, adjustment

**Price Override**:
A Tier Price a User sets by hand on one line of an Order — a deal agreed with a buyer — instead of the one the store works out. It needs a Permission of its own and a reason, and both the reason and who gave it stay on the record, because a price nobody can explain is a margin nobody can trust. A line overridden keeps its price through any later Repricing of the Order. It may never exceed the line's MRP, for the same reason no Tier Price may: nothing is sold above it, whoever sets the price.
_Avoid_: discount, manual price, custom price, negotiated price

**Cart**:
The Products and Kilograms a Customer is gathering before they order, kept by their own browser and nowhere else. It belongs to the browser and not to the Customer: signing in elsewhere does not bring it along, and nothing about it is known to the store until Capture. Every figure it shows is worked out afresh as it changes, so adding a Kilogram that crosses into another Tier reprices every line in it at once. A browser holds two, which never meet: the bulk Cart of Kilograms and the retail Cart of Packs. There is only ever one bulk Cart: the bulk order form, the price calculator and every product card's stepper are views of it, never separate quantities that could disagree. The retail Cart owes nothing to the Tiers. A Customer reads them as the পাইকারি কার্ট and the খুচরা কার্ট — one noun for both, never ঝুড়ি.
_Avoid_: basket, bag, order (before Capture), quote

**Quote**:
The figures a Customer was shown when they placed an Order — each line's Tier Price, the Delivery Charge and the total — sent with it and checked at Capture against what the store works out itself. The store's own figures are the only ones that count; the Quote exists to be compared. The MRP is no part of it: it moves nothing the Customer owes, so a Saving that shifted while they checked out is recorded as it stands at Capture rather than refused over. One that disagrees — a price moved, or a Product no longer Available — is refused, never silently corrected, and the Customer shown the figures as they are now, because the total on their screen is the total the courier collects and a surprise at the door is a refused parcel.
_Avoid_: pricing fingerprint, hash, checksum, estimate, preview

**Customer**:
A person identified by their phone number, and nothing else. A Customer holds no name, no shop and no address; those belong to each Order they placed, because a phone number is an identity, not a person.
_Avoid_: user, account, buyer, client, lead

**Signing in**:
A Customer proving, with a One-Time Code sent to their phone, that the number is theirs — and so seeing every Order ever placed with it, those placed before they first signed in among them. Nothing is created by it and nothing is kept on the Customer afterwards: there is no account beside the number, no profile, no saved address. It is never asked of anybody to order; it exists so a Customer can follow their Orders.
_Avoid_: login, register, sign up, account, authentication

**One-Time Code**:
Four digits sent by SMS to a phone number, good once and only for a few minutes, whose only use is Signing in. How often one may be sent to a number is limited, because every one is a text message the store pays for.
_Avoid_: OTP, PIN, password, verification code, token

**SMS Gateway**:
A company the store sends text messages through, known to the system by name — GioSMS to begin with. Like a Courier or a Payment Method, the Merchant supplies what it needs once in Settings, and one gateway at a time is the one that sends. Which one is sending is never something a Customer can tell.
_Avoid_: SMS provider, driver, SMS service, messaging vendor

**Order Progress**:
Where an Order stands as its Customer sees it after Signing in: Received, Confirmed, Sent or Delivered — or Cancelled, drawn instead of the steps. Worked out from the Order and its Shipments and never kept or set by anybody, so it cannot disagree with them: an Active Shipment is Sent, a Delivered or Partly Delivered one is Delivered. A parcel that came back, or was Abandoned, reads as Confirmed again, because to the Customer the Order is waiting to be sent, and whether it will be is a question a User has not answered yet. It never names a Courier's Consignment.
_Avoid_: order status, tracking, delivery status, timeline

**Order Text**:
A text message telling a Customer that something happened to their Order — it was placed, confirmed, sent with a Courier, or Cancelled. Each kind is switched on or off in Settings and worded there by the Merchant. One is sent after the fact and never on the way to it: a text that cannot go out never stops an Order being captured or moved along.
_Avoid_: notification, SMS alert, message, update

**Enquiry**:
A question somebody sent through the contact form — a name, a phone number, what it is about and what they said — kept in the admin until a User has called them back and closed it. Not an Order and not a Customer: asking commits nobody to anything, and a caller who then orders has their Order taken by phone like anybody else's.
_Avoid_: lead, ticket, message, contact, inquiry

**Staff Alert**:
A text message sent to the Merchant's own numbers, set in Settings, the moment a new Order is captured or a new Enquiry arrives, so that nobody has to watch the admin to know there is somebody to call. Switched on or off like an Order Text, and never sent to a Customer.
_Avoid_: admin notification, new order alert, ping

**District**:
The district an Order is delivered to, one of the country's sixty-four, chosen from a list at Capture and distinct from the free-text address that tells a courier where the door is. Every Order has one, because the store delivers nationwide and couriers want a structured region that cannot be recovered from an address afterwards. It settles nothing about what an Order costs.
_Avoid_: zone, area, thana, city

**Kilogram**:
The unit bulk is sold in. Every quantity a bulk Customer asks for is a whole number of kilograms of one Product, and every bulk figure that depends on how much is bought — the Tier and the Delivery Charge among them — is read from kilograms, never from a count of lines or parcels. Retail is sold in Packs instead. Stock is held in grams beneath both, so a Kilogram and a Pack of the same tea come off one shelf.
_Avoid_: unit, piece, item, quantity (as a unit)

**Delivery Band**:
A range of an Order's total kilograms and the Delivery Charge for it — 1–5 kg ৳120, 51 kg and over free. The bands cover every whole kilogram from one upwards without gap or overlap, are the same for the whole country, and are set in Settings. They are their own table and owe nothing to how Products are priced: where a band's edges fall says nothing about where a price changes.
_Avoid_: delivery option, shipping tier, zone, rate, slab

**Delivery Charge**:
The amount added to an Order for getting the parcel to the door, taken from the Delivery Band its total kilograms fall in. It is part of what the courier collects, and it is agreed at Capture like every other figure on the Order. There is nothing for a Customer to choose: the weight decides it. A retail Order owes nothing to the Delivery Bands: it pays one flat retail charge set in Settings, waived once its total reaches a threshold set beside it.
_Avoid_: shipping cost, courier fee, postage

**Cancelled**:
An Order that genuinely happened but will not be fulfilled — an unreachable number, a changed mind, a refused parcel. Distinct from a deleted Order, which is one that should never have existed at all. Cancelling says nothing about money: a Payment that completes against a Cancelled Order is recorded Paid, and the Order is both at once until somebody refunds it. It says nothing about a parcel either: a Shipment already moving goes on moving, and the Order is Cancelled and out for delivery at once until it comes back.
_Avoid_: rejected, void, failed, refunded

### Paying

**Payment Method**:
One of the named ways an Order can be paid, known to the system by name: Cash on Delivery and the online gateways. The Merchant switches each on for the whole store in Settings and supplies what the gateway needs; the Checkout offers every switched-on method it can reach, and asks the Customer to choose only when there is more than one. Nothing anywhere on the store advertises a method that is not switched on.
_Avoid_: gateway, payment option, payment provider, driver

**Cash on Delivery**:
The Payment Method where the courier collects the total at the door. The one method the store opened with, the one the Checkout falls back to when nothing else can be offered, and the one that needs no Payment: an Order paid this way is simply due.
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
Where a gateway sends a Customer back. It says the Payment is being verified, runs the Verification, then sends the Customer to the Order Complete Page or the Retry Page. It prints nothing about the Customer, because it is the one address a gateway's own logs hold.
_Avoid_: callback page, return URL, success page

**Order Complete Page**:
Where a Customer lands once their Order is settled as far as the Checkout is concerned: straight after a Cash on Delivery Capture, or once a Payment is Paid. Addressed by order number alone. It shows the Order in full — the Customer's name, number and address included, and their shop and their note where they gave them — only to the browser that placed it, for as long as that browser keeps what Capture handed it; anyone else holding the address sees the Order and not the Customer. The address itself unlocks nothing, so it may sit in history and Referer headers without giving anything away.
_Avoid_: thank-you page, confirmation page, success page, receipt

**Retry Page**:
Where a Customer lands with an Unpaid Order: after a Failed, Cancelled or Expired Payment, or after a Capture that chose a method the store no longer offers. It shows the Order and offers the switched-on Payment Methods again, Cash on Delivery among them. Addressed by order number alone, like the Order Complete Page.
_Avoid_: failure page, error page, payment page

### Delivering

**Courier**:
A delivery company a parcel is handed to, known to the system by name — Steadfast, Pathao and RedEx to begin with. Like a Payment Method, the Merchant supplies what it needs once for the whole installation and a Courier that has not been supplied is offered nowhere. What any one of them can be asked is its own answer and no two agree: on what an address is, on whether a parcel's progress is announced or must be asked for, on whether many may be booked at once, or on whether they will say what they are holding.
_Avoid_: carrier, delivery partner, logistics provider, shipping method, vendor

**Shipment**:
One attempt to get one Order to a door through one Courier. An Order holds as many as it took — a parcel that came back and was sent again is two, each with its own Consignment, its own charge and its own account of what happened, because the first attempt cost money whatever the second one does. A Shipment records the recipient details it was actually sent with, which need not be what the Order says today. It exists from the moment the attempt is committed rather than from the moment a Courier accepts it: a batch writes every row before anything is dispatched, so a Shipment may be Awaiting Dispatch, and a parcel a Courier then refuses leaves the row behind saying so.
_Avoid_: delivery, parcel, booking, dispatch, consignment

**Consignment**:
The Courier's own identifier for a parcel, handed back when a Shipment is booked, and the only name that Courier will answer to afterwards. Every Courier spells it differently and issues it on its own terms, so a Consignment is quoted back and never interpreted, and it is never what the system knows a Shipment by.
_Avoid_: tracking id, tracking code, AWB, waybill, reference

**Parcel Detail**:
Something one Courier needs told about a parcel that no other Courier asks about — how it is to be delivered, what is inside it, what it weighs, which store it leaves from. Each is declared by that Courier's driver and nowhere else, so the send dialog puts the chosen Courier's questions and no other's in front of a User, opened on what the Merchant decided once in Settings. A change there belongs to that parcel alone: it is recorded on the Shipment as sent and configures nothing. A question with only one possible answer — a Merchant with a single pickup store — is never asked, and is sent all the same.
_Avoid_: courier option, custom field, extra field, metadata, parameter

**Pickup Store**:
A warehouse a Courier collects the Merchant's parcels from, known by the id that Courier gave it. It is one Courier's own name for the place and never the system's: the same warehouse is a different number at Pathao and at RedEx, so the Merchant registers it once per Courier and the two lists are never shared. Beside the id sits the Merchant's own name for it, which is theirs alone and is never sent anywhere — it is what makes the choice readable on the send dialog. One of a Courier's stores is the default a parcel leaves from when nobody chooses, which is the whole of the common case: a Merchant with one warehouse is never asked at all, and only a Merchant who registers a second starts being.
_Avoid_: warehouse id, hub, depot, branch, location

**Delivery Area**:
One of a Courier's own delivery areas, known by the id that Courier gave it. Not somewhere the system knows of: it is a word one Courier insists on hearing, because it will not read an address in free text the way the others do. The system caches that Courier's list, guesses which area an Order's address and District mean, and puts the guess in front of a User already chosen for them to confirm or correct — a guess, never a decision, because a parcel sent to a guessed depot is one the Merchant pays to have returned. A parcel whose area nobody chose is not sent at all.
_Avoid_: zone, thana, area id, location, region

**Shipment Event**:
One thing that happened to a Shipment, as the system came to know it. It carries two times and not one: when the Courier says it happened, where the Courier said, and when we found out, which is always. The two sit far apart for a Courier that must be asked rather than one that announces, and a timeline showing only one would quietly misdate every parcel from the couriers that say least.
_Avoid_: status change, tracking update, history entry, log

**Announcement**:
A Courier telling the system, on its own, that something happened to a parcel. Like a Notification it is a hint and never an answer: one claiming a parcel's state has moved is read only far enough to name the Shipment, and the system then asks that Courier itself and records what its own call learned. One carrying only a message — a line of prose about the parcel, from the Courier that keeps no history anyone could ask — is written to the timeline as text and can move no status, no filter, no figure and no Shipment's Active-ness. A Courier that announces nothing is polled, which for it is the only channel there ever was. A webhook is the post it arrives on and a Courier is asked whether it has one; the Announcement is what the post said.
_Avoid_: callback, push, tracking update, status update

**Active**:
Said of a Shipment still worth waiting on: not Delivered, not partly delivered, not Returned, not refused at booking, and not Abandoned. An Order has at most one, which is what stops a parcel being booked twice and what the Orders list draws. An Order with none is an Order that may be sent.
_Avoid_: open, live, in progress, pending, current

**Awaiting Dispatch**:
Said of a Shipment written down and not yet handed to anybody. Only a batch leaves one: a single parcel is booked while a User waits on the Consignment, where a batch of two hundred is written first and worked through by the sweep, because nobody is watching it and no Courier can be asked two hundred questions inside one request. The row is the whole point — a batch interrupted halfway has a record of every parcel it booked, and a parcel is offered exactly once because it is offered from its own row. It is Active from the moment it is written, so the Order it belongs to cannot go into a second batch.
_Avoid_: queued, pending, scheduled, draft, unsent

**Refused at Booking**:
Said of a Shipment a Courier would not take when it was offered. A time and the Courier's own words, never a status, exactly as Abandoned is — the row goes on saying truthfully that it was written and never dispatched, and the refusal is what takes it out of Active so the Order is one to send again. Only a batch produces one: a booking made while a User waits words the refusal back at them and records nothing at all.
_Avoid_: rejected, failed, cancelled, error, bounced

**Returned**:
Said of a Shipment whose parcel came back undelivered. It is the Courier's outcome and not the Merchant's decision, and it is emphatically not Cancelled: a returned parcel is a question — ring the Customer, correct the address, send it again, or give up — and the answer is a User's. Two of the three couriers say "cancelled" in their own words for exactly this, which is precisely why the system does not. The units it carried come back on the shelf as they left it — at the cost stamped on them the day the Courier took the box, never at what the shelf has cost since.
_Avoid_: cancelled, failed, undelivered, refused, RTO

**Partly Delivered**:
Said of a Shipment the Courier collected only part of the money for: some of the parcel arrived and the rest is coming back. The Courier reports an amount and never a list of items, so the system cannot know which units came back, and it does not guess — the parcel moves no stock at all and the Order joins Needs Attention until a User names the lines that came back. Until they do, those units are neither back in stock nor written off, which is exactly what they are. It is an arrival like Delivered: the attempt is over and the Order is not one to send again.
_Avoid_: partial, short delivery, split delivery, incomplete

**Abandoned**:
Said of a Shipment a User has written off — a parcel a Courier never collected, or one held so long there is nothing left to wait for. It is a time and a name and never a status, because the Courier's last word is worth keeping: a Shipment On Hold and then abandoned says both, where a status overwritten would say only that somebody gave up. Abandoning frees the Order to be sent again, and asserts that the first parcel is dead.
_Avoid_: cancelled, void, closed, written off, expired

**Balance (courier-reported)**:
What a Courier says it is holding of the Merchant's money, in that Courier's own words about its own account. Only one of the three publishes such a figure at all, and the other two are said to report none rather than shown a blank — a blank in a money column is read as a zero, and a zero here tells a Merchant their money has been paid over. A figure that could not be fetched is the last one read, with the time it was read at, or the reason there is none; it is never a zero and it is never added to COD in Flight.
_Avoid_: courier balance, wallet, float, due, payable

**COD in Flight**:
What the couriers are between them holding of the Merchant's money: what every Active Shipment was sent out to collect. Counted from the Merchant's own Orders rather than from anything a Courier says, so every Courier has the figure alike — and never added to a Courier's reported balance, because one is what we believe and the other is what we were told.
_Avoid_: outstanding, receivable, pending COD, courier balance, float

### Stock

**Supply**:
Goods arriving from a Supplier, recorded as one document: who they came from, the day they came and the reference they can be found under, with a line per Product saying how many arrived and what each one cost — an Order and its Order Lines, read from the buying side. Recording one raises stock there and then; there is no separate receiving step to forget. **Supply** is the domain term and the model; a shop owner reaches for **Purchase**, so that is the word the Inventory admin wears — the Purchases resource is Supplies relabelled, with nothing underneath moved (#172). That merchant label is a **distinct sense** from the tracking Moment **Purchase**, which is a sale reported to ad platforms, worth an Order's total: this one is stock bought in, that one is a sale sent out, and the two are never conflated. The word carries the buying sense only where a merchant reads a stock screen — a tracking figure never counts a Supply, and no stock report borrows the Moment's sense.
_Avoid_: purchase order, goods receipt, restock, intake — and never let the two senses of **purchase** blur: the buying label here, the tracking Moment elsewhere

**Supplier**:
Somebody the Merchant buys from, known by a name, a phone number and a note rather than by however somebody spelled it on the day, so that everything ever bought from one of them reads as one buying history. A Supplier is never a Customer: one is where goods come from and the other is where they go, and the two never overlap.
_Avoid_: vendor, seller, source, manufacturer, merchant

**Stock Movement**:
One thing that happened to a quantity of a Product: a signed number of units, the reason it moved, the unit cost in force when it moved, the person, the time, and what caused it — a Supply's line, a Shipment, a Stock Count. A movement is something that happened to a box, so one is written only where units really arrived or really left. Nothing is ever edited or deleted: a mistake is answered by another movement, and the mistake and its correction both stay on the record.
_Avoid_: stock entry, ledger line, transaction, stock change, quantity update

**On Hand**:
How many units of a Product are held: the sum of that Product's Stock Movements, never a figure anybody types and never one held apart from them. A Product nobody has counted reads as nothing rather than as zero — the same distinction a Courier's Balance and its Delivery Rate already draw — because a zero is an instruction to reorder, and a Product nobody has looked at is not a Product that has run out. It is allowed to go below nothing and drawn loudly when it does, since a negative figure is an unrecorded Supply announcing itself.
_Avoid_: quantity, stock level, inventory count, available, in stock, QOH

**Committed**:
How many units of a Product are already spoken for: what is on the Confirmed Orders nobody has sent — no Active Shipment, and nothing of theirs that ever reached a door. Both halves, and for the reason the Orders queue reads them together: a delivered Order has no Active Shipment either, and counting it would leave the figure climbing for ever with units that left months ago, where a parcel that came back is genuinely spoken for again. It is a fact about Orders and never a Stock Movement, because nothing has happened to a box — so it is drawn beside On Hand and never taken off it, and it moves when an operator confirms an Order or a parcel is booked rather than when anything leaves a shelf. Nothing spoken for reads as nothing rather than as unknown, unlike every other figure here: this one is counted off the Orders themselves, so there is no shelf nobody has looked at.
_Avoid_: reserved, allocated, on hold, pending stock, available to promise

**Stock Count**:
What a User physically counted of one Product, recorded as the number they are holding in their head at the shelf and never as a difference; how far the ledger was out is worked out rather than typed, and written down as an Adjustment. A Count is immutable and carries the name of whoever counted: a wrong one is answered by counting again, which is what somebody would physically do anyway. A Product joins the ledger at its first Stock Count and not before, so adopting stock is nothing more than counting the Products that matter this afternoon.
_Avoid_: stocktake, cycle count, audit, recount, inventory check

**Adjustment**:
The Stock Movement that squares the ledger with a Stock Count, carrying a cause chosen from a fixed list — damaged, lost, found, miscount, opening count — the person who counted, and a note where they have something to add. The cause is compulsory and picked rather than typed, because "what am I losing to damage" is a question a Merchant asks of a year of them at once. It is reached by two entry paths that come to the same thing: an **exact count**, where a User writes down the number on the shelf; or a direct **Increase (+) / Decrease (−)**, where a Merchant names only how much changed and the ledger reads On Hand and does the arithmetic for them (#174). Both derive exactly one Adjustment Stock Movement of the difference — a +/− is a count with the total worked out rather than a new kind of thing — so a below-zero figure is only ever concluded by the ledger, never asserted: a Decrease that would cross it, or one against an uncounted Product, is refused and pointed back at the exact count.
_Avoid_: correction, write-off, shrinkage, manual entry, fix

**Return**:
The Stock Movement that puts a parcel's units back on the shelf when a Courier brings the box back: the exact opposite of the one that took them, at the cost stamped on it the day the Courier took it. What comes back is what left rather than what the Order says now, so a Product nobody had counted has nothing to bring back and a Supply that arrived while the parcel was out cannot revalue units on a round trip. Said only of a box that came back through the door — a parcel given up as dead is answered by an **Unsold** and a **Loss**, which is the same arithmetic and a different sentence, and which is what leaves a Return free to be written if the Courier turns up with the parcel after all.
_Avoid_: restock, refund, reversal, put-back, RTO

**Partial Return**:
The Stock Movement that puts back the units of a Partly Delivered parcel a User has said came back: the same arithmetic as a Return, at the cost stamped on the Sale it answers, for the lines they named and no others. The only movement in the system a person decides on rather than a Courier, and the only one carrying the name of whoever decided — nothing else could have established which items came back, so a surprising figure has somebody to ask about it. Naming nothing is a real answer and settles the parcel too: what it records is that the Customer kept it all. Its own word rather than a Return, so that a Courier turning up afterwards with the whole box still owes one — and what that Return puts back is what left and has not already come back.
_Avoid_: partial refund, split return, part return, short return

**Loss**:
What a parcel given up as dead cost: the units that left on it, at the cost stamped on them when they left, named as money on the Product's own history. Written when a User Abandons a Shipment and never by a Courier's word, because only a person may say a parcel is dead. It is the answer to "what did that actually cost me" — without it, stock that left and was never paid for reads as an ordinary sale and surfaces months later as shrinkage nobody can account for. It takes nothing further off the shelf: the units left once, when the Courier took the box, so an **Unsold** takes them back off the sale at what they left at and the Loss is taken from there. A parcel nobody was ever handed writes none, because nothing left.
_Avoid_: write-off, shrinkage, wastage, damage, spoilage

**Unsold**:
The Stock Movement that takes a parcel's units back off the sale, written only beside a Loss and never alone: what it says is that the sale did not happen, not that anything came back through a door. It is what the Loss is taken out of, so that a write-off names what a dead parcel cost without deducting units that left the shelf once already. Its own word rather than a Return for a reason a Merchant reads and a reason the ledger enforces: nothing came back, and a parcel written off and then brought back by the Courier owes a Return as well — one the ledger would refuse if the write-off had already spent that word on the same parcel.
_Avoid_: reversal, cancellation, unsale, void, adjustment

**Value at Cost**:
What the units held of a Product are worth at what they cost: every Stock Movement at the unit cost stamped on it, added up. It is how much capital is sitting on the shelf, and it is the ledger's own figure rather than On Hand multiplied by an average — the two come to the same thing, because every movement was stamped with the average in force as it happened, and this one traces to rows a User can read. A Product nothing has ever been bought for reads as nothing, never as zero, like every other unknown figure here; one holding units nobody recorded buying reads what the ledger can account for, which is lower than what was really paid and is raised by recording the Supply that was missed. It goes below nothing only where On Hand has, and means the same thing when it does.
_Avoid_: stock value, inventory value, valuation, worth, capital tied up

**Days of Cover**:
How long a Product's On Hand lasts at the rate it is going out: units held against units shipped per day. The rate is read over a fixed trailing span of its own and never over the screen's Reporting Window, because a Merchant narrowing a window to Today would otherwise change every velocity in the catalogue by moving a control about something else. Thin cover is a to-do rather than a report, so it ignores the window like every other one, and one Setting says what thin means for the whole catalogue. A Product nobody has counted has none, and neither has one nothing has gone out of: no cover, never zero and never for ever, because a dormant Product must not present itself as safe.
_Avoid_: reorder point, stock days, runway, coverage, low stock threshold

**Void**:
What is done to a Supply recorded wrongly: one action that writes the exact reversing movements, marks the original voided with a time and a name, and leaves the pair in the history to be read together. It is not Cancelled — a Cancelled Order genuinely happened and will not be fulfilled, where a Voided Supply is one that should never have been written down at all.
_Avoid_: cancel, delete, reverse, undo, amend

### Reporting

**Reporting Window**:
The span of days every report figure is counted over, chosen once for a whole screen and obeyed by everything on it: Today, Yesterday, Last 7 days, Last 30 days, This month, or two dates. A day begins and ends in the Merchant's timezone, never the server's, because a Merchant judges a day's spend against a day's Orders and both happen in the same place. Every window has a comparison window of the same length immediately before it, which is what a change is measured against.
_Avoid_: date range, period, filter, timeframe

**Report**:
Figures a Merchant reads at length, on a screen of their own. A report is asked either of the whole installation — what the couriers did, what the Products sold, what is on the shelf. Nearly every one is counted over a Reporting Window and obeys it, and every figure on such a report answers for the same span of days, which is what makes two of them worth reading side by side. The exception is a report whose question has no span behind it at all: what is held right now is a fact about this moment, so the Stock Report is drawn with no window control rather than one that changes nothing — a dead control is worse than none, because a Merchant who moves it reads the same figures as the answer to a different question. A to-do list is not a report either way: it names what somebody must act on as things stand and ignores the window on purpose, because a backlog drawn under a window a Merchant has narrowed is a backlog that quietly shrinks. Where a figure and a to-do list share a screen, the one that ignores the window says so where it is drawn. A report counts bulk and retail Orders together unless the Merchant narrows it to one, because Sales is the business's whole takings, not one channel's.
_Avoid_: analytics, insights, metrics, stats, BI

**Orders (as a figure)**:
How many Orders were captured in a Reporting Window, whatever became of them afterwards. It is the number ad spend is judged against: a Cancelled Order still cost a click.
_Avoid_: conversions, leads, sales

**Sales**:
The money side of a Reporting Window: the totals, Delivery Charge included, of every Order captured in it that is not Cancelled. Counted at Capture, not when it is paid or delivered, because a Cash on Delivery Order has no other moment. Orders and Sales are always shown side by side and never collapsed into one.
_Avoid_: revenue, turnover, income, GMV

**Sales by Category**:
`Sales` and `Kilograms Sold` read once per Category, over the same Orders and the same moment, each line counted under the Category it was sold under rather than the one its Product sits in now. A Category's money is its lines alone: the Delivery Charge belongs to an Order and to no Category on it, so the Categories add up to `Sales` less the Delivery Charges and the screen says so, rather than inventing a split nobody charged.
_Avoid_: category revenue, segment sales, category mix

**Returning Customer**:
A Customer who bought in a Reporting Window and had bought before it began; one who bought in it for the first time is a **New Customer**. Buying means an Order counted in `Sales`, so a Cancelled Order neither makes anybody returning nor counts them in the window at all: someone whose only earlier Order was Cancelled never received any tea, and coming back is what the figure is about.
_Avoid_: repeat buyer, loyal customer, existing customer, retained customer

**Kilograms Sold**:
The weight side of a Reporting Window: the Kilograms on every Order counted in Sales, the same Orders and the same moment — a retail Order's Packs counted by their weight, so the figure may fall between whole kilograms. Drawn beside Sales wherever Sales is drawn, because a wholesale tea business measures itself in kilograms as much as in taka, and the two move apart whenever the Tiers do their work.
_Avoid_: volume, units sold, quantity, tonnage

**Cancellation Rate**:
Cancelled Orders as a share of Orders in a Reporting Window. Drawn as its own figure so that cancelling an Order moves a rate a Merchant watches rather than quietly shrinking a total.
_Avoid_: return rate, failure rate, churn

**Average Order Value**:
Sales divided by the Orders that count towards Sales in a Reporting Window. Zero, not undefined, when there are none.
_Avoid_: AOV, basket size, ticket size

**Needs Attention**:
The Orders, Payments, parcels and shelves an operator must act on regardless of any Reporting Window: a New Order nobody has called in a day, a Payment Pending for over an hour, an Order Paid twice, an Order Paid and Cancelled at once, an Order whose parcel came back and nobody has decided about, an Order whose parcel half arrived and nobody has said which items came back, a parcel nobody has heard about in roughly a week, and a Product whose Days of Cover has fallen under what the Merchant called thin. It is a to-do list, not a report, so it ignores the window. A returned parcel is here rather than anywhere else because it changes nothing by itself: what becomes of the Order is a decision a User makes, and until they make it the return is a question nobody has answered. A Partly Delivered one is here for a stricter reason still — the ledger itself is waiting on the answer, and only answering takes it off, because neither cancelling the Order nor sending another parcel says anything about which items are sitting in a courier's warehouse. A thin shelf is the one line here that is about a Product rather than an Order — what is emptying it is a week of them — and it is the one with no date beside it, because cover is read fresh from a rate that moves under it and no day is the day it crossed the line.
_Avoid_: alerts, exceptions, issues, anomalies

**Visit**:
One load of a page of the store by a browser, counted per day for the whole store and for the place that was loaded — a Page, a Category or a Product by the thing itself rather than its address, so renaming a Slug never splits its history, and Search and the Checkout as one place each. The store's figure stands on its own and the places are a breakdown of it, not its source: a load that resolves to nothing is still a Visit to the store but no place's, and days counted before places were are the store's alone, so the places may add up to less than the store and are never made to agree. Every load, not every browser, matching how an ad platform counts the same thing, so the two figures agree. Loads of the Order Complete Page and the Retry Page are not Visits: whoever is on them has already ordered, and counting them would dilute the Conversion Rate with people who converted. Counted only where the Merchant has switched Visit counting on, because counting is a database write on the path of every page load; what is stored is the count and never the load, so it holds nothing about anybody and is kept indefinitely.
_Avoid_: page view, hit, session, traffic

**Conversion Rate**:
Orders as a share of Visits in a Reporting Window, for the whole store. Every Order counts towards it, Cancelled ones included, for the same reason `Orders` as a figure counts them: the Visit converted whatever became of the Order afterwards. It owes nothing to any ad platform — a Merchant who reports to none still has one — and it is unknown rather than zero where Visits are not counted.
_Avoid_: CVR, conversion, close rate, win rate

**Buy Rate**:
The Orders that hold a Product as a share of that Product's Visits in a Reporting Window — what it says is "looked at a lot, rarely bought". Said of one Product and never of the store, which has the Conversion Rate instead. Cancelled Orders count, as they do for the Conversion Rate. It sets two counts side by side and follows nobody from one to the other, so an Order taken by phone or placed on a later day still counts, and the rate may read above one; it is shown as it is rather than capped. Unknown, not zero, where Visits are not counted or the Product had none.
_Avoid_: product conversion rate, view-to-order rate, CVR

**Courier Report**:
Everything the couriers' figures say over a Reporting Window — parcels sent, delivered and returned, how often each Courier delivers, how long it takes, and what it charged — on a screen of its own, broken down by Courier and never blended into one total. A figure a Courier never reports reads as nothing for that Courier rather than as zero for the trade, because the couriers do not answer the same questions and a single blended figure would present one courier's silence as everybody's.
_Avoid_: delivery analytics, logistics report, shipping stats, courier dashboard

**Delivery Rate**:
The parcels one Courier got to a door as a share of the parcels it was handed in a Reporting Window. Counted over everything that Courier was given rather than over the attempts it has finished with, so it is a share of the Merchant's own traffic and a parcel still moving is neither a success nor a failure yet. Said of one Courier and never of the trade. Unknown, not zero, where a Courier was handed nothing: a Courier nobody tried is not a Courier that fails.
_Avoid_: success rate, fulfilment rate, delivery percentage, SLA

**Return Rate**:
The parcels one Courier brought back as a share of the parcels it was handed in the same Reporting Window, read exactly as the Delivery Rate is. The two need not add up to one: what is left over is out for delivery, held, or Abandoned. It is a Courier's outcome and has nothing to do with the Cancellation Rate, which is a figure about what a User decided.
_Avoid_: RTO rate, failure rate, cancellation rate, bounce rate

**Stock Report**:
What every Product holds and what those units are worth, on a screen of its own, with the capital on the shelf added up across it. The one report drawn with **no Reporting Window control at all**: a stock level is a fact about this moment and has no span of days behind it, so there is nothing for a window to govern and a control that changed nothing would be worse than none. A Product nobody has counted reads as nothing on it, never as zero, and is left out of the total held rather than added in as a nought — which is why the screen also says how many of the Products behind the figure are in the ledger at all. A Product withdrawn from the catalogue stays on it while it still holds units, because units on a shelf are capital whether or not anybody is still selling them.
_Avoid_: inventory report, stock on hand report, valuation report, stock list

**Buying Report**:
What was bought over a Reporting Window — from which Supplier, how many units, at what cost — read once by Supplier and once by Product, with what a unit averaged and what the window cost altogether. It obeys the window exactly as the Courier Report does, and is meant to be read beside `Sales` over the same days: money out and money in are only comparable over the same span. A delivery belongs to the window by the day the goods came rather than the day somebody typed it in, because that is the day a buyer looks for. A Voided Supply is in none of it. The average a unit cost here is what these days paid and is not the Product's own cost, which is blended over everything ever bought and read as Value at Cost.
_Avoid_: purchase report, procurement report, spend analysis, purchases

**Margin Report**:
What a Reporting Window earned rather than what it turned over: revenue, the cost stamped on what sold and what is left, read per Product and added up across them. Its revenue **is `Sales` over the same window, to the minor unit** — both halves are counted at Capture, over the Orders `Sales` counts, so two screens can never give a Merchant two revenue figures for one span of days — and the Delivery Charge is inside it because `Sales` has always included it. The charge belongs to an Order rather than to any Product on it, so it appears on no row and the screen says where it is counted. A Cancelled Order leaves both sides together. A Product nothing has been recorded buying reads unknown, never zero, and sorts below every Product whose margin is known: nobody knows what it made, and a nought would present it as infinitely profitable. Where only some of what sold carries a stamped cost the report says how much, because a margin drawn over part of its cost is a ceiling rather than a figure. It is read beside `Top Products`, which ranks by turnover and cannot tell the Product carrying the most money from the Product carrying the most profit.
_Avoid_: profit report, P&L, gross profit, contribution

### Tracking

**Moment**:
Something that happens in this domain and is worth reporting to an ad platform: a Visit, a Product Viewed, an Added to Cart, a Checkout Started, a Capture, a Paid. Which are reported, and where, is decided once for the whole store in Settings. Tracking is organised around Moments rather than around any one platform's event names, so what the system reports is described in its own words and each Provider translates. A Moment produces zero, one or several provider events, and which ones is the Provider's answer.
_Avoid_: event, conversion, trigger, pixel event

**Checkout Started**:
The Moment a visitor opens the Checkout with something in their Cart. It says they set out to order, not that they did; a Capture is a different Moment.
_Avoid_: begin checkout, add to cart, engagement

**Product Viewed**:
The Moment a Product's own page is opened. Seeing a Product in a list, a rail or the bulk order form is not it.
_Avoid_: view content, impression, product view, page view

**Added to Cart**:
The Moment a Product goes from none to some in a Cart — once per Product per Cart, however many Kilograms follow it; in the retail Cart, once per Pack, however many of it follow. Every Moment says which Cart it came from, but a Pack is reported as its Product, so the ad platforms see one tea whichever way it is sold. Raising a quantity already there is not it. It happens in the browser, because that is where the Cart is.
_Avoid_: add to basket, cart event, cart update

**Provider**:
An ad platform events can be reported to — Facebook first, TikTok and Google Ads later. Like a Courier, the Merchant supplies what it needs once in Settings — for Facebook, a dataset and the access token that writes to it — and the store reports to one account per Provider, never several. Switching a Provider off pauses reporting to it without forgetting what was supplied. A Provider owns everything platform-specific: which of its own events a Moment produces, how many may travel in one request, how long after the fact it still accepts them, and why what the Merchant supplied cannot be delivered to.
_Avoid_: channel, integration, vendor, network, destination, connection

**Purchase**:
Exactly one is counted per Order. A Cash on Delivery Order reports it at Capture, because there is no later moment; an Order paid online reports it only once a Payment is Paid, so an Order abandoned at the gateway is never reported as revenue. What it is worth is the Order's total, Delivery Charge included, so it agrees with Sales. It is sent twice — once from the Customer's browser and once from the server — and counted once, because both copies carry the order number as the deduplication id. Not the **Purchases** the Inventory admin shows: that label is a Supply — stock bought in — where this Moment is a sale sent out. The two share a word and nothing else (#172).
_Avoid_: conversion, sale, transaction

**Pixel**:
The browser's half of reporting: a snippet a page carries that reports a Moment from the Customer's own browser, beside the copy the server sends. Both copies carry the same deduplication id, so the Provider counts one event. A Provider is not a Pixel — the Pixel is what addresses one, and a page while no Provider is switched on carries none.
_Avoid_: tag, tracker, script, beacon

**Tracking Outbox**:
Where a Moment is written down, one row per switched-on Provider per provider event, so that no web request ever calls an ad platform. A scheduled sweep delivers what is waiting. A row holds no personal detail: what a Provider is told about the Customer is read from the Order when the row is delivered.
_Avoid_: queue, event log, buffer

**Tracking Context**:
What the browser told us when an Order was captured — the click identifiers, the IP address, the User-Agent and the address it was on — kept apart from the Order in a table of its own. It exists for the Moment that arrives with no browser present: an Order paid online reports its Purchase when a Verification says Paid, which happens from a sweep or a gateway's own notification. Kept only where such a Moment is still to come, because an Order reporting at Capture has already written the same things onto its outbox row. Not part of the Order: an Order records what it needs to be fulfilled and is kept indefinitely, and a browser identifier is neither.
_Avoid_: session, visitor record, attribution data, fingerprint


### People

**User**:
Someone who signs into the admin to do some part of the work — authoring Pages, working Orders, counting shelves — and which part is their Roles' to say. A User is never a Customer; the two never overlap. There is only one kind of them: what tells two Users apart is the Roles they hold and nothing else, which is why there is no such thing as a staff account.
_Avoid_: admin, operator, staff

**Merchant**:
Bongo Tea, the one business this system is built for. There is no other: what Bongo Tea would want to change without a developer belongs in Settings, and nothing is made configurable merely so that some other business could run it.
_Avoid_: tenant, client, store owner, vendor

**Permission**:
One thing a User may do: a working area of the admin, or a single act that cannot be undone. The system knows every Permission by name and a Merchant never invents one — they arrive as features are built, and a Merchant's part is deciding which Roles carry them. A Permission is held through a Role and no other way, so what somebody may do has exactly one explanation.
_Avoid_: ability, right, privilege, access, scope, capability

**Role**:
A named bundle of Permissions a Merchant composes and gives to Users. A User may hold several, and what they may do is everything their Roles allow between them. A Role is deleted only once nobody holds it, because revoking several people's access at once is never something to do by accident.
_Avoid_: group, profile, access level, user type, permission set

**Owner**:
The User who may do everything, and the only one who decides what anyone else may do. Not a Role that happens to hold every Permission but one standing outside them: a Permission invented tomorrow is already theirs, which is what keeps an installation workable as it grows. An installation always has at least one and cannot be left without one.
_Avoid_: super admin, administrator, root, superuser

**Suspended**:
Said of a User holding no Roles. They cannot sign in, and everything they did keeps their name on it. It is not leaving: a suspended User is still listed and comes back the moment a Role is given, where one who has left is filed away. Deliberately the same state as never having been given a Role at all, because "has no job here" and "has no job here yet" are the same fact about today.
_Avoid_: disabled, deactivated, blocked, banned, inactive

**Filed away**:
Said of a User who has left, taken off the roster but not erased. They cannot sign in and drop off the default list, and can be brought back if they return — but their name stays on every Stock Count and Adjustment they made, because that name is the only thing that lets a surprising figure be asked about, and deleting the row would null it. Distinct from Suspended: a suspended User is between jobs and expected back with a Role; a filed-away one is gone, and filing them away is what locks them out for good rather than a Role they no longer hold.
_Avoid_: deleted, archived, removed, terminated, offboarded

### The installation

**Settings**:
The values a Merchant decides once for the whole store: how the store is reached and what it is called, the Delivery Bands, and what payment gateways, couriers and SMS Gateways need. What appears on every page is a Setting and what appears on one is a Block; if a single Page could reasonably differ on it, it is not a Setting.
_Avoid_: config, options, preferences, site settings, global settings

**Menu**:
A named list of links the Merchant builds — "Main menu", "Footer – Order" — that appears wherever it is assigned: the header (on every screen size), a footer column, the bottom line. Menus belong with Pages, not Settings: they are storefront content, edited by whoever writes Pages. A Menu is not its place: one Menu may serve two, and a place shows nothing until a Menu is assigned to it. A link may hold links beneath it, which the header draws as a dropdown. A link points at a Page, a Category, a Product, a place on a page or an outside address, and points at the thing rather than at where it is, so renaming never breaks a Menu. A dropdown may be told to list every Category as it stands, rather than the ones somebody remembered to add.
_Avoid_: navigation, nav, links, sitemap

**Payment Banner**:
One image the Merchant uploads showing how a Customer can pay, drawn across the full width of the footer. It is a picture and nothing more: it is not read from which payment methods are switched on, and a store that has uploaded none shows nothing in its place. Not the payment method logos, which are drawn only at Checkout beside each method's name.
_Avoid_: payment badges, payment strip, accepted cards
