<?php

namespace Database\Seeders\Pages;

use App\Support\Pages\Blocks\ContactCards;
use App\Support\Pages\Blocks\ContactForm;
use App\Support\Pages\Blocks\MapAndAddress;

/**
 * How to reach the store: the hotline, WhatsApp and email, a form for a
 * question, and where the store is and when it is open.
 */
final class ContactPage extends DemoPage
{
    public static function title(): string
    {
        return 'যোগাযোগ';
    }

    public static function slug(): string
    {
        return 'contact';
    }

    public static function blocks(): array
    {
        return [
            self::banner('যোগাযোগ', 'চা নিয়ে যেকোনো প্রশ্ন? আমরা আছি', 'অর্ডার, দাম, পরিবেশক হওয়া বা ডেলিভারি নিয়ে জানতে ফোন করুন, হোয়াটসঅ্যাপে মেসেজ দিন অথবা নিচের ফর্মটি পূরণ করুন।'),
            self::block(ContactCards::class, [
                'whatsapp_note' => 'সাধারণত ১০ মিনিটে উত্তর',
                'email_note' => '২৪ ঘণ্টার মধ্যে উত্তর',
                'raised' => true,
            ]),
            self::block(ContactForm::class, [
                'heading' => 'মেসেজ পাঠান',
                'text' => 'আমরা ফোন করে উত্তর দেব।',
                'topics' => ['অর্ডার', 'দাম', 'পরিবেশক হতে চাই', 'ডেলিভারি', 'অন্যান্য'],
            ]),
            self::block(MapAndAddress::class, [
                'hours' => [
                    ['days' => 'শনি – বৃহস্পতি', 'hours' => 'সকাল ৯টা – রাত ৯টা'],
                    ['days' => 'শুক্রবার', 'hours' => 'বিকেল ৩টা – রাত ৯টা'],
                ],
            ]),
        ];
    }
}
