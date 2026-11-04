import { usePage } from '@inertiajs/react';

/**
 * Interface translation.
 *
 * The catalogue arrives with every Inertia page as flat dot keys, so looking a
 * label up is a map read. There is no new dependency and no second source of
 * truth: lang/en/ui.php is where a string is written, lang/gu/ui.php is where
 * it is translated, and a test keeps the two in step.
 */

export type Replacements = Record<string, string | number>;

interface SharedProps {
    locale?: string;
    translations?: Record<string, string>;
}

/** Fill :name placeholders, the way Laravel's own translator does. */
function fill(line: string, replace?: Replacements): string {
    if (!replace) return line;
    return Object.entries(replace).reduce(
        (out, [key, value]) => out.replaceAll(`:${key}`, String(value)),
        line,
    );
}

export function translate(
    catalogue: Record<string, string>,
    key: string,
    replace?: Replacements,
): string {
    const line = catalogue[key];
    // A missing key shows the key itself. That is deliberate: it is visible in
    // review, where a silent fallback to English would not be.
    return fill(line ?? key, replace);
}

/**
 * Pick a line from a "one|many" pair.
 *
 * Laravel writes plurals as "1 file|:count files" in the same string, so the
 * catalogue stays one entry per idea. Gujarati, like English, needs only the
 * two branches.
 */
export function choose(line: string, count: number): string {
    const parts = line.split('|');
    if (parts.length < 2) return line;
    return count === 1 ? parts[0] : parts[1];
}

export function useTranslation() {
    const page = usePage<SharedProps>();
    const catalogue = page.props.translations || {};
    const locale = page.props.locale || 'en';

    return {
        /** t('nav.browse'), or t('sidebar.rename', { name: 'Utsav' }). */
        t: (key: string, replace?: Replacements) => translate(catalogue, key, replace),
        /** tc('folders.items', 3) → "3 items"; :count is filled for you. */
        tc: (key: string, count: number, replace?: Replacements) =>
            fill(choose(catalogue[key] ?? key, count), { count, ...(replace || {}) }),
        locale,
        isGujarati: locale === 'gu',
    };
}

/** Just the lookup, for components that do not need the locale itself. */
export function useT() {
    return useTranslation().t;
}

/** The lookup plus the plural picker, as a pair. */
export function useTx() {
    const { t, tc } = useTranslation();
    return { t, tc };
}
