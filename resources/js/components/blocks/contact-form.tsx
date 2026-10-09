import { useHttp, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { bn } from '@/lang/bn';
import { toLatinDigits } from '@/lib/bangla';
import { cn } from '@/lib/utils';
import type { ContactFormProps } from '@/types/blocks';

type Enquiry = { name: string; phone: string; topic: string; message: string; website: string };

/**
 * The contact form: a name, a phone number, a topic chip and a message,
 * sent as an Enquiry for somebody to call back — then a thank-you in its
 * place. The `website` field is a honeypot no person sees.
 */
export function ContactForm({ heading, text, topics }: ContactFormProps) {
    const { links } = usePage().props;
    const [sent, setSent] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const form = useHttp<Enquiry>({ name: '', phone: '', topic: topics[0] ?? '', message: '', website: '' });

    function send(event: React.FormEvent) {
        event.preventDefault();
        setNotice(null);

        if (form.data.name.trim() === '' || toLatinDigits(form.data.phone).replace(/\D/g, '').length < 11) {
            setNotice(bn.contactForm.incomplete);

            return;
        }

        form.transform((data) => ({ ...data, phone: toLatinDigits(data.phone) }));
        form.post(links.enquiry, {
            onSuccess: () => setSent(true),
            onHttpException: (response: { status: number }) => {
                if (response.status !== 422) {
                    setNotice(response.status === 429 ? bn.contactForm.tooMany : bn.contactForm.failed);
                }
            },
            onNetworkError: () => setNotice(bn.contactForm.failed),
        }).catch(() => {
            // Answered above.
        });
    }

    function another() {
        form.reset();
        form.clearErrors();
        setNotice(null);
        setSent(false);
    }

    const error = notice ?? form.errors.name ?? form.errors.phone ?? form.errors.topic ?? form.errors.message;

    return (
        <div className="shell-container py-5 md:py-9">
            <div className="flex max-w-[760px] flex-col gap-4 rounded-[20px] border border-border p-[clamp(18px,3vw,28px)]">
                {sent ? (
                    <div role="status" className="flex flex-col items-center gap-3 px-2 py-7 text-center">
                        <span aria-hidden className="flex size-[60px] items-center justify-center rounded-full bg-green-50 text-[26px] font-bold text-primary">
                            ✓
                        </span>
                        <b className="text-[21px] font-semibold text-ink">{bn.contactForm.sentHeading}</b>
                        <span className="text-[15px] leading-[1.7] text-muted">{bn.contactForm.sentText}</span>
                        <button type="button" onClick={another} className="cursor-pointer border-0 bg-transparent p-0 text-[14px] font-semibold text-primary">
                            {bn.contactForm.another}
                        </button>
                    </div>
                ) : (
                    <form onSubmit={send} noValidate className="flex flex-col gap-4">
                        <div className="flex flex-col gap-1">
                            <b className="text-[22px] font-semibold text-ink">{heading ?? bn.contactForm.heading}</b>
                            <span className="text-[14px] text-muted">{text ?? bn.contactForm.intro}</span>
                        </div>

                        <div className="grid grid-cols-1 gap-[14px] md:grid-cols-2">
                            <Field label={bn.contactForm.name}>
                                <input
                                    className={INPUT}
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    placeholder={bn.contactForm.namePlaceholder}
                                    autoComplete="name"
                                    maxLength={120}
                                />
                            </Field>
                            <Field label={bn.contactForm.phone}>
                                <input
                                    className={INPUT}
                                    value={form.data.phone}
                                    onChange={(event) => form.setData('phone', event.target.value)}
                                    placeholder={bn.contactForm.phonePlaceholder}
                                    type="tel"
                                    inputMode="tel"
                                    autoComplete="tel"
                                />
                            </Field>
                        </div>

                        {topics.length > 0 && (
                            <div className="flex flex-col gap-[6px]">
                                <span className="text-[14px] font-semibold">{bn.contactForm.topic}</span>
                                <div role="radiogroup" aria-label={bn.contactForm.topic} className="flex flex-wrap gap-2">
                                    {topics.map((topic) => {
                                        const chosen = form.data.topic === topic;

                                        return (
                                            <button
                                                key={topic}
                                                type="button"
                                                role="radio"
                                                aria-checked={chosen}
                                                onClick={() => form.setData('topic', topic)}
                                                className={cn(
                                                    'cursor-pointer rounded-full border px-[14px] py-2 text-[14px] font-semibold',
                                                    chosen ? 'border-ink bg-ink text-white' : 'border-input bg-white text-ink',
                                                )}
                                            >
                                                {topic}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        )}

                        <Field label={bn.contactForm.message}>
                            <textarea
                                className="resize-y rounded-[10px] border border-input bg-white px-[14px] py-3 text-[16px] text-ink outline-none focus:border-primary"
                                value={form.data.message}
                                onChange={(event) => form.setData('message', event.target.value)}
                                placeholder={bn.contactForm.messagePlaceholder}
                                rows={4}
                                maxLength={2000}
                            />
                        </Field>

                        <label aria-hidden className="absolute -left-[9999px] h-px w-px overflow-hidden">
                            {bn.contactForm.honeypot}
                            <input tabIndex={-1} autoComplete="off" value={form.data.website} onChange={(event) => form.setData('website', event.target.value)} />
                        </label>

                        {error && (
                            <span role="alert" className="rounded-[8px] bg-error-soft px-3 py-[10px] text-[14px] text-error">
                                {error}
                            </span>
                        )}

                        <button
                            type="submit"
                            disabled={form.processing}
                            className="cursor-pointer rounded-[10px] border-0 bg-primary p-[15px] text-center text-[16px] font-semibold text-white hover:bg-primary-dark disabled:cursor-wait disabled:bg-disabled"
                        >
                            {form.processing ? bn.contactForm.sending : bn.contactForm.send}
                        </button>
                    </form>
                )}
            </div>
        </div>
    );
}

const INPUT = 'h-[50px] rounded-[10px] border border-input bg-white px-[14px] text-[16px] text-ink outline-none focus:border-primary';

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <label className="flex flex-col gap-[6px]">
            <span className="text-[14px] font-semibold">{label}</span>
            {children}
        </label>
    );
}
