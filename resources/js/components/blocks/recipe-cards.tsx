import { ChevronDownIcon } from '@/components/storefront/icons';
import { bn } from '@/lang/bn';
import { toBanglaDigits } from '@/lib/bangla';
import { cn } from '@/lib/utils';
import type { RecipeCardsProps } from '@/types/blocks';
import { SectionHeading } from './shared';

/**
 * Recipes as cards that open to the whole recipe: its facts at a glance on
 * the closed card, then the ingredients, the numbered steps and the tips.
 * Native `<details>`, so each opens without script and a crawler reads it all.
 */
export function RecipeCards({ eyebrow, heading, text, recipes }: RecipeCardsProps) {
    if (recipes.length === 0) {
        return null;
    }

    return (
        <div className="shell-container flex flex-col gap-6 py-5 md:gap-11 md:py-9">
            <SectionHeading eyebrow={eyebrow} heading={heading} text={text} />
            <div className="grid grid-cols-1 items-start gap-[14px] md:grid-cols-2">
                {recipes.map((recipe, index) => {
                    const facts = [
                        { label: bn.blocks.recipe.tea, value: recipe.tea },
                        {
                            label: bn.blocks.recipe.serves,
                            value: recipe.serves,
                        },
                        { label: bn.blocks.recipe.time, value: recipe.time },
                    ].filter((fact) => fact.value);

                    return (
                        <details
                            key={index}
                            id={recipe.anchor ?? undefined}
                            className={cn('group rounded-[18px] border border-border bg-white open:shadow-[0_8px_30px_rgba(18,35,26,0.08)]', recipe.anchor && 'scroll-mt-[130px] lg:scroll-mt-[170px]')}
                        >
                            <summary className="flex cursor-pointer list-none flex-col gap-3 p-[22px] [&::-webkit-details-marker]:hidden">
                                <span className="flex items-start justify-between gap-4">
                                    <span className="flex flex-col gap-1">
                                        <b className="text-[19px] leading-[1.35] font-semibold text-ink">{recipe.name}</b>
                                        {recipe.englishName && <span className="text-[13px] font-medium tracking-wide text-muted uppercase">{recipe.englishName}</span>}
                                    </span>
                                    <span className="flex size-9 flex-none items-center justify-center rounded-full bg-surface text-muted">
                                        <ChevronDownIcon size={16} strokeWidth={2} className="transition-transform group-open:rotate-180" />
                                    </span>
                                </span>
                                {facts.length > 0 && (
                                    <span className="flex flex-wrap gap-2">
                                        {facts.map((fact) => (
                                            <span key={fact.label} className="rounded-full bg-green-50 px-3 py-1 text-[13px] text-primary-dark">
                                                {fact.label}: <b className="font-semibold">{fact.value}</b>
                                            </span>
                                        ))}
                                    </span>
                                )}
                                <span className="text-[14px] font-semibold text-primary group-open:hidden">{bn.blocks.recipe.open}</span>
                            </summary>

                            <div className="flex flex-col gap-5 border-t border-border px-[22px] pt-5 pb-[22px]">
                                {recipe.taste && (
                                    <p className="m-0 text-[15px] leading-[1.75] text-body">
                                        <b className="font-semibold text-ink">{bn.blocks.recipe.taste}:</b> {recipe.taste}
                                    </p>
                                )}

                                {recipe.ingredients.length > 0 && (
                                    <div className="flex flex-col gap-2">
                                        <b className="text-[14px] font-semibold text-eyebrow">{bn.blocks.recipe.ingredients}</b>
                                        <ul className="m-0 flex list-none flex-col divide-y divide-border rounded-[12px] bg-surface p-0">
                                            {recipe.ingredients.map((ingredient, ingredientIndex) => (
                                                <li key={ingredientIndex} className="flex justify-between gap-4 px-4 py-[10px] text-[15px] text-body">
                                                    <span>{ingredient.item}</span>
                                                    {ingredient.quantity && <span className="text-right font-semibold text-ink">{ingredient.quantity}</span>}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}

                                {recipe.steps.length > 0 && (
                                    <div className="flex flex-col gap-2">
                                        <b className="text-[14px] font-semibold text-eyebrow">{bn.blocks.recipe.steps}</b>
                                        <ol className="m-0 flex list-none flex-col gap-[10px] p-0">
                                            {recipe.steps.map((step, stepIndex) => (
                                                <li key={stepIndex} className="flex gap-3 text-[15px] leading-[1.7] text-body">
                                                    <span className="flex size-6 flex-none items-center justify-center rounded-full bg-night text-[12px] font-bold text-white">
                                                        {toBanglaDigits(stepIndex + 1)}
                                                    </span>
                                                    {step}
                                                </li>
                                            ))}
                                        </ol>
                                    </div>
                                )}

                                {recipe.tips.map((tip, tipIndex) => (
                                    <div key={tipIndex} className="flex flex-col gap-1 rounded-[12px] border-l-[3px] border-gold-accent bg-surface px-4 py-3">
                                        {tip.label && <b className="text-[13px] font-semibold text-eyebrow">{tip.label}</b>}
                                        <span className="text-[14px] leading-[1.7] whitespace-pre-line text-body">{tip.text}</span>
                                    </div>
                                ))}
                            </div>
                        </details>
                    );
                })}
            </div>
        </div>
    );
}
