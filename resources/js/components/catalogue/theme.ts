import type { CSSProperties } from 'react';
import type { CategoryTheme } from '@/types/catalogue';

/**
 * A Category Theme as CSS variables, set on the element that wears it, so
 * everything inside draws its colours with `text-(--cat-text)` and the like
 * and no component writes a colour of its own.
 */
export function themeStyle(theme: CategoryTheme): CSSProperties {
    return {
        '--cat-text': theme.text,
        '--cat-tint': theme.tint,
        '--cat-panel': theme.panel,
        '--cat-panel-text': theme.panelText,
        '--cat-panel-subtext': theme.panelSubtext,
        '--cat-accent': theme.accent,
        '--cat-pill-bg': theme.pillBackground,
        '--cat-pill-text': theme.pillText,
        '--cat-tick-bg': theme.tickBackground,
        '--cat-line': theme.line,
    } as CSSProperties;
}
