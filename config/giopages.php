<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | One currency per installation. Money is stored as integer minor units
    | everywhere; this is the only place that knows what a minor unit is worth
    | or what symbol to draw, so a merchant outside Bangladesh changes one
    | config value rather than patching views.
    |
    */

    'currency' => [
        'code' => env('GIOPAGES_CURRENCY_CODE', 'BDT'),
        'symbol' => env('GIOPAGES_CURRENCY_SYMBOL', '৳'),

        /** Digits after the decimal point. Zero for JPY, three for KWD. */
        'decimals' => (int) env('GIOPAGES_CURRENCY_DECIMALS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    |
    | Where a Merchant's day begins and ends. Storage stays UTC; this decides
    | how a capture time is shown in the admin and at which midnight a
    | Reporting Window is cut, so "today" matches the Merchant's calendar and
    | not the server's. A PHP timezone identifier, overridable in Settings.
    |
    */

    'timezone' => env('GIOPAGES_TIMEZONE', 'Asia/Dhaka'),

    /*
    |--------------------------------------------------------------------------
    | Phone numbers
    |--------------------------------------------------------------------------
    |
    | A Customer *is* their phone number, so every number is normalised to one
    | canonical form on write. That normalisation cannot be country-agnostic:
    | `01712345678` and `+8801712345678` are the same person, and only the
    | country's dialling rules say so. Getting this wrong does not look like a
    | bug — it looks like two customers.
    |
    */

    'phone' => [
        /** Country calling code, digits only. Bangladesh is 880. */
        'country_code' => env('GIOPAGES_PHONE_COUNTRY_CODE', '880'),

        /** The leading digit dropped when dialling internationally. */
        'trunk_prefix' => env('GIOPAGES_PHONE_TRUNK_PREFIX', '0'),

        /**
         * Length bounds for the national number, once the country code and
         * trunk prefix are stripped. Bangladeshi mobiles are 10 digits
         * (`1XXXXXXXXX`); the range stays loose enough not to reject a
         * legitimate landline a courier could still call.
         */
        'national_length' => [
            'min' => (int) env('GIOPAGES_PHONE_MIN_LENGTH', 9),
            'max' => (int) env('GIOPAGES_PHONE_MAX_LENGTH', 12),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | The one Delivery Option a Page is born with, in major units — a single
    | arrangement named "Delivery", added to the total with no question asked.
    |
    | A starting point and not the answer: delivery is priced per Page from
    | here on, as Delivery Options the Merchant names and prices themselves
    | (ADR-0005). Changing this changes what the *next* Page starts from and
    | nothing that already exists, which is the point — an installation-wide
    | figure reaching every live campaign at once is what that ADR ended.
    |
    */

    'delivery' => [
        'charge' => env('GIOPAGES_DELIVERY_CHARGE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Front Page
    |--------------------------------------------------------------------------
    |
    | Which Page answers the Site Root, by id. Zero means none, and the Site
    | Root serves the Placeholder instead (ADR-0023).
    |
    | A default here rather than in the Settings group alone, because every
    | property of a group is set from config in its constructor and the row is
    | only ever the override (ADR-0012). A Merchant chooses this from a
    | dropdown; the environment variable is here for an installation that
    | provisions itself.
    |
    */

    'front_page_id' => (int) env('GIOPAGES_FRONT_PAGE_ID', 0),

    /*
    |--------------------------------------------------------------------------
    | Districts
    |--------------------------------------------------------------------------
    |
    | The structured region courier APIs want. It settles nothing about what an
    | Order costs — Delivery Options do that (ADR-0005) — and a Page may switch
    | the question off entirely. A config list rather than a table: it is not
    | data the merchant manages, and it must be swappable per installation — a
    | merchant outside Bangladesh replaces this list wholesale.
    |
    | Structure cannot be recovered from free text later: "Mirpur 10, Dhaka"
    | and "Dhaka-1216" will not sort into buckets a year on. That is why this
    | is captured separately from the street address rather than derived
    | from it.
    |
    */

    'districts' => [
        'Bagerhat', 'Bandarban', 'Barguna', 'Barishal', 'Bhola', 'Bogura',
        'Brahmanbaria', 'Chandpur', 'Chapainawabganj', 'Chattogram', 'Chuadanga',
        'Cox\'s Bazar', 'Cumilla', 'Dhaka', 'Dinajpur', 'Faridpur', 'Feni',
        'Gaibandha', 'Gazipur', 'Gopalganj', 'Habiganj', 'Jamalpur', 'Jashore',
        'Jhalokati', 'Jhenaidah', 'Joypurhat', 'Khagrachhari', 'Khulna',
        'Kishoreganj', 'Kurigram', 'Kushtia', 'Lakshmipur', 'Lalmonirhat',
        'Madaripur', 'Magura', 'Manikganj', 'Meherpur', 'Moulvibazar',
        'Munshiganj', 'Mymensingh', 'Naogaon', 'Narail', 'Narayanganj',
        'Narsingdi', 'Natore', 'Netrokona', 'Nilphamari', 'Noakhali', 'Pabna',
        'Panchagarh', 'Patuakhali', 'Pirojpur', 'Rajbari', 'Rajshahi',
        'Rangamati', 'Rangpur', 'Satkhira', 'Shariatpur', 'Sherpur', 'Sirajganj',
        'Sunamganj', 'Sylhet', 'Tangail', 'Thakurgaon',
    ],

    /*
    |--------------------------------------------------------------------------
    | Couriers
    |--------------------------------------------------------------------------
    |
    | What an ordinary parcel of this merchant's looks like to each courier,
    | before they have said otherwise on the Couriers settings page. Nothing
    | here decides which couriers exist — the `Courier` enum is the registry
    | (ADR-0013) — and nothing here is a credential: a key in an environment
    | file is a key the settings group never got to seal.
    |
    | Weight and declared value are what make an unattended booking possible
    | at all. RedEx refuses a parcel without either, and the system holds
    | neither: no page asks a customer how heavy their order is, and it must
    | not start. The type codes are each courier's own documented words for
    | "an ordinary parcel going to a home", spelled as that courier spells
    | them, and a merchant with a different arrangement overrides them in
    | Settings rather than here.
    |
    */

    'couriers' => [

        'steadfast' => [
            /** Steadfast collects from one arrangement per account and takes no store. */
            'pickup_store' => '',

            /** Steadfast's `delivery_type`: 0 is to the door, 1 is to one of their points. */
            'delivery_type' => '0',

            'item_type' => '',

            /** Kilograms. */
            'parcel_weight' => 0.5,

            /** Major units, in the installation currency. */
            'declared_value' => 1000.0,
        ],

        'pathao' => [
            /** Pathao insists on a store, and its id is the merchant's own. Blank until they fill it in. */
            'pickup_store' => '',

            /** Pathao's `delivery_type`: 48 is normal delivery, 12 is on demand. */
            'delivery_type' => '48',

            /** Pathao's `item_type`: 2 is a parcel, 1 is a document. */
            'item_type' => '2',

            'parcel_weight' => 0.5,

            'declared_value' => 1000.0,
        ],

        'redex' => [
            /** RedEx collects from a pickup store the merchant registers with them. */
            'pickup_store' => '',

            'delivery_type' => '',

            'item_type' => '',

            /** RedEx refuses a parcel with no weight, so this is never blank. */
            'parcel_weight' => 0.5,

            /** RedEx refuses a parcel with no declared value either. */
            'declared_value' => 1000.0,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    |
    | What the beacon — the one thing on a Visit's path that boots the
    | framework at all — may be asked for.
    |
    | The default for each Provider event is deliberately not here. It is a
    | decision a Merchant makes on a screen about events only a Provider can
    | name, so it lives in the tracking settings row alone with no config key
    | to disagree with; an event nobody has said anything about fires.
    |
    */

    'tracking' => [
        /**
         * Moments one address may report in a minute before the beacon
         * refuses it. Keyed on IP alone, with the trade-off named at the
         * property this fills: a carrier's NAT puts thousands of genuine
         * visitors behind one address, and being refused costs the redundancy
         * rather than the event, since the browser's own Pixel has fired.
         */
        'beacon_rate_limit' => (int) env('GIOPAGES_TRACKING_BEACON_RATE_LIMIT', 300),

        /**
         * Whether every load of a published Page is counted as a Visit.
         *
         * Off, because it is a database write on the path of a page load and
         * this product is served from shared hosting: adding one to every
         * installation without being asked is the wrong instinct. A Merchant
         * who wants the Visits tile and a Conversion Rate switches it on and
         * accepts the write, and gets both whether or not they use any ad
         * platform at all.
         */
        'counts_visits' => (bool) env('GIOPAGES_TRACKING_COUNTS_VISITS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    */

    'checkout' => [
        /**
         * Upper bound on a single line's quantity. Not a stock check — stock
         * does not exist yet — but a COD order for 10,000 units is a mistake
         * or an abuse, and either way it should not reach the fulfilment queue.
         */
        'max_quantity' => (int) env('GIOPAGES_CHECKOUT_MAX_QUANTITY', 100),

        /**
         * Captures allowed per minute from one IP.
         *
         * Keyed on IP alone, which is the trade-off worth naming: mobile
         * carriers put thousands of real buyers behind one NAT address, so the
         * limit is set well above what a shared address plausibly produces
         * rather than tuned tight against a determined abuser.
         */
        'rate_limit' => (int) env('GIOPAGES_CHECKOUT_RATE_LIMIT', 30),
    ],

];
