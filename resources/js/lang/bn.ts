/**
 * Every piece of storefront interface copy, in Bangla.
 *
 * The one translation set: a component never writes a sentence of its own
 * and never carries an English fallback. What the Merchant writes — the
 * store's name, the announcement, Menu labels — arrives as props and is not
 * here.
 */
export const bn = {
    header: {
        searchPlaceholder: 'চা খুঁজুন: প্রিমিয়াম, স্ট্রং, দুধ চা…',
        search: 'খুঁজুন',
        myOrdersEyebrow: 'আমার',
        myOrders: 'অর্ডার ট্র্যাক',
        cart: 'কার্ট',
        bulkOrder: 'বাল্ক অর্ডার করুন →',
        bulkOrderShort: 'বাল্ক অর্ডার',
        menu: 'মেনু',
        home: 'হোম',
    },
    topBar: {
        nationwideDelivery: 'সারা দেশে ডেলিভারি',
        cashOnDelivery: 'ক্যাশ অন ডেলিভারি',
        hotline: 'হটলাইন:',
    },
    drawer: {
        myOrders: 'আমার অর্ডার ও ডেলিভারি',
        phoneOrder: 'ফোনে অর্ডার:',
    },
    footer: {
        call: 'কল',
        phoneOrder: 'ফোনে অর্ডার',
        whatsappBadge: 'WA',
        whatsapp: 'হোয়াটসঅ্যাপে মেসেজ দিন',
        address: 'ঠিকানা',
        deliveryNote: 'সারা দেশে ডেলিভারি, ক্যাশ অন ডেলিভারি',
        payment: 'পেমেন্ট:',
        rights: (year: string, name: string) => `© ${year} ${name}। সর্বস্বত্ব সংরক্ষিত।`,
    },
    paymentMethods: {
        cash_on_delivery: 'ক্যাশ অন ডেলিভারি',
        bkash: 'বিকাশ',
        sslcommerz: 'কার্ড ও মোবাইল ব্যাংকিং',
        aamarpay: 'আমারপে',
        paystation: 'পেস্টেশন',
    } as Record<string, string>,
    blocks: {
        slide: 'স্লাইড',
        previous: 'আগের',
        next: 'পরের',
        imagePlaceholder: 'ছবি',
        watchHowTo: 'কীভাবে অর্ডার করবেন, ভিডিও দেখুন',
        howToVideoTitle: 'কীভাবে অর্ডার করবেন',
        phoneHelp: 'অনলাইনে অসুবিধা হলে ফোন করুন:',
        swipe: 'সরিয়ে দেখুন',
        swipeMore: ' · সরিয়ে দেখুন →',
        videoTag: (number: string) => `ভিডিও ${number}`,
        stars: '★★★★★',
        bought: (product: string) => `কিনেছেন ${product}`,
        verifiedBuyer: 'যাচাইকৃত ক্রেতা',
        closeVideo: 'বন্ধ করুন',
        videoDismissHint: 'ভিডিওর বাইরে চাপলে বা ✕ চাপলে বন্ধ হবে',
        contact: {
            callBadge: 'কল',
            call: 'ফোন করুন',
            whatsappBadge: 'WA',
            whatsapp: 'হোয়াটসঅ্যাপ',
            emailBadge: '@',
            email: 'ইমেইল',
        },
        map: {
            title: 'ম্যাপ',
            address: 'ঠিকানা',
            hours: 'খোলা থাকে',
        },
    },
} as const;
