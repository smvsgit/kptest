import type { UiTheme, UiThemePreset } from './types';

export type UiMode = 'light' | 'dark';
export type UiThemeFamily = 'smvs' | 'slack' | 'google' | 'ocean' | 'royal' | 'forest' | 'rose' | 'amber';

/**
 * label and help hold catalogue keys, not text: the theme picker reads in
 * whichever language the account has chosen. The palette names themselves
 * (SMVS Teal, Google Blue) stay recognisable in the translated label.
 */
export interface ThemePresetChoice {
    key: UiThemePreset;
    family: UiThemeFamily;
    mode: UiMode;
    label: string;
    help: string;
    colors: string[];
}

export const themeChoices: ThemePresetChoice[] = [
    { key:'smvs-light', family:'smvs', mode:'light', label:'theme.label_smvs-light', help:'theme.help_smvs-light', colors:['#2a9d8f','#264653','#e9c46a','#f4f7f6'] },
    { key:'smvs-dark', family:'smvs', mode:'dark', label:'theme.label_smvs-dark', help:'theme.help_smvs-dark', colors:['#2a9d8f','#34c4b2','#e9c46a','#121b1f'] },
    { key:'slack-light', family:'slack', mode:'light', label:'theme.label_slack-light', help:'theme.help_slack-light', colors:['#611f69','#36c5f0','#2eb67d','#faf7fb'] },
    { key:'slack-dark', family:'slack', mode:'dark', label:'theme.label_slack-dark', help:'theme.help_slack-dark', colors:['#8f4b99','#36c5f0','#2eb67d','#18131a'] },
    { key:'google-light', family:'google', mode:'light', label:'theme.label_google-light', help:'theme.help_google-light', colors:['#1a73e8','#ea4335','#34a853','#f8faff'] },
    { key:'google-dark', family:'google', mode:'dark', label:'theme.label_google-dark', help:'theme.help_google-dark', colors:['#8ab4f8','#f28b82','#81c995','#111318'] },
    { key:'ocean-light', family:'ocean', mode:'light', label:'theme.label_ocean-light', help:'theme.help_ocean-light', colors:['#0891b2','#0369a1','#22c55e','#f2fbfd'] },
    { key:'ocean-dark', family:'ocean', mode:'dark', label:'theme.label_ocean-dark', help:'theme.help_ocean-dark', colors:['#22d3ee','#38bdf8','#34d399','#071a24'] },
    { key:'royal-light', family:'royal', mode:'light', label:'theme.label_royal-light', help:'theme.help_royal-light', colors:['#4f46e5','#7c3aed','#0ea5e9','#f7f7ff'] },
    { key:'royal-dark', family:'royal', mode:'dark', label:'theme.label_royal-dark', help:'theme.help_royal-dark', colors:['#818cf8','#a78bfa','#38bdf8','#141427'] },
    { key:'forest-light', family:'forest', mode:'light', label:'theme.label_forest-light', help:'theme.help_forest-light', colors:['#15803d','#047857','#84cc16','#f5fbf5'] },
    { key:'forest-dark', family:'forest', mode:'dark', label:'theme.label_forest-dark', help:'theme.help_forest-dark', colors:['#4ade80','#34d399','#a3e635','#0c1a12'] },
    { key:'rose-light', family:'rose', mode:'light', label:'theme.label_rose-light', help:'theme.help_rose-light', colors:['#e11d48','#f97316','#db2777','#fff7f8'] },
    { key:'rose-dark', family:'rose', mode:'dark', label:'theme.label_rose-dark', help:'theme.help_rose-dark', colors:['#fb7185','#fb923c','#f472b6','#211116'] },
    { key:'amber-light', family:'amber', mode:'light', label:'theme.label_amber-light', help:'theme.help_amber-light', colors:['#d97706','#92400e','#eab308','#fffaf0'] },
    { key:'amber-dark', family:'amber', mode:'dark', label:'theme.label_amber-dark', help:'theme.help_amber-dark', colors:['#fbbf24','#f59e0b','#facc15','#1d160b'] },
];

const presetKeys = new Set(themeChoices.map(theme => theme.key));
const families = new Set<UiThemeFamily>(themeChoices.map(theme => theme.family));

export function resolveThemeSelection(value?: UiTheme | string | null, legacyMode: UiMode = 'dark'): UiThemePreset {
    const raw = String(value || '').trim().toLowerCase();
    if (presetKeys.has(raw as UiThemePreset)) return raw as UiThemePreset;
    if (families.has(raw as UiThemeFamily)) return `${raw}-${legacyMode}` as UiThemePreset;
    return `smvs-${legacyMode}` as UiThemePreset;
}

export function splitThemeSelection(value?: UiTheme | string | null, legacyMode: UiMode = 'dark'): { preset: UiThemePreset; family: UiThemeFamily; mode: UiMode } {
    const preset = resolveThemeSelection(value, legacyMode);
    const mode: UiMode = preset.endsWith('-light') ? 'light' : 'dark';
    const family = preset.slice(0, preset.lastIndexOf('-')) as UiThemeFamily;
    return { preset, family, mode };
}

export function applyUserTheme(value?: UiTheme | string | null, legacyMode: UiMode = 'dark'): UiThemePreset {
    const resolved = splitThemeSelection(value, legacyMode);
    document.documentElement.setAttribute('data-color-theme', resolved.family);
    document.documentElement.setAttribute('data-theme', resolved.mode);
    document.documentElement.style.colorScheme = resolved.mode;
    return resolved.preset;
}

/*
 * The header Sun/Moon button is a temporary viewing-mode override: it changes
 * light/dark for the session while the account keeps its saved theme family.
 * It has to be stored and re-applied explicitly, because a saved value like
 * "smvs-dark" already carries its own mode and would otherwise win on every
 * page mount and snap the override back (UAT C25).
 */
const MODE_OVERRIDE_KEY = 'smvs_theme';

export function readModeOverride(): UiMode | null {
    try {
        const stored = localStorage.getItem(MODE_OVERRIDE_KEY);
        return stored === 'light' || stored === 'dark' ? stored : null;
    } catch { return null; }
}

export function writeModeOverride(mode: UiMode): void {
    try { localStorage.setItem(MODE_OVERRIDE_KEY, mode); } catch { /* private mode */ }
}

/** Called when the user picks a specific preset, which supersedes the override. */
export function clearModeOverride(): void {
    try { localStorage.removeItem(MODE_OVERRIDE_KEY); } catch { /* private mode */ }
}

/** Apply the account theme for this page, honouring any Sun/Moon override. */
export function applySessionTheme(value?: UiTheme | string | null): { preset: UiThemePreset; mode: UiMode } {
    const override = readModeOverride();
    const resolved = splitThemeSelection(value, override ?? 'dark');
    const mode = override ?? resolved.mode;
    document.documentElement.setAttribute('data-color-theme', resolved.family);
    document.documentElement.setAttribute('data-theme', mode);
    document.documentElement.style.colorScheme = mode;
    return { preset: resolved.preset, mode };
}
