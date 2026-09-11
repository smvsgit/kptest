import type { UiTheme, UiThemePreset } from './types';

export type UiMode = 'light' | 'dark';
export type UiThemeFamily = 'smvs' | 'slack' | 'google' | 'ocean' | 'royal' | 'forest' | 'rose' | 'amber';

export interface ThemePresetChoice {
    key: UiThemePreset;
    family: UiThemeFamily;
    mode: UiMode;
    label: string;
    help: string;
    colors: string[];
}

export const themeChoices: ThemePresetChoice[] = [
    { key:'smvs-light', family:'smvs', mode:'light', label:'SMVS Teal - Light', help:'Clean teal and warm gold on a bright workspace.', colors:['#2a9d8f','#264653','#e9c46a','#f4f7f6'] },
    { key:'smvs-dark', family:'smvs', mode:'dark', label:'SMVS Teal - Dark', help:'The familiar teal portal palette on a deep charcoal canvas.', colors:['#2a9d8f','#34c4b2','#e9c46a','#121b1f'] },
    { key:'slack-light', family:'slack', mode:'light', label:'Slack Aubergine - Light', help:'Aubergine, aqua and green on a soft lavender workspace.', colors:['#611f69','#36c5f0','#2eb67d','#faf7fb'] },
    { key:'slack-dark', family:'slack', mode:'dark', label:'Slack Aubergine - Dark', help:'Aubergine communication accents on a rich dark surface.', colors:['#8f4b99','#36c5f0','#2eb67d','#18131a'] },
    { key:'google-light', family:'google', mode:'light', label:'Google Blue - Light', help:'Bright blue with familiar red, yellow and green accents.', colors:['#1a73e8','#ea4335','#34a853','#f8faff'] },
    { key:'google-dark', family:'google', mode:'dark', label:'Google Blue - Dark', help:'Google-inspired colors balanced for a low-light workspace.', colors:['#8ab4f8','#f28b82','#81c995','#111318'] },
    { key:'ocean-light', family:'ocean', mode:'light', label:'Ocean Cyan - Light', help:'Fresh cyan and ocean blue with cool, airy surfaces.', colors:['#0891b2','#0369a1','#22c55e','#f2fbfd'] },
    { key:'ocean-dark', family:'ocean', mode:'dark', label:'Ocean Cyan - Dark', help:'Cool cyan accents on deep navy-blue surfaces.', colors:['#22d3ee','#38bdf8','#34d399','#071a24'] },
    { key:'royal-light', family:'royal', mode:'light', label:'Royal Indigo - Light', help:'Professional indigo and violet with crisp neutral cards.', colors:['#4f46e5','#7c3aed','#0ea5e9','#f7f7ff'] },
    { key:'royal-dark', family:'royal', mode:'dark', label:'Royal Indigo - Dark', help:'Indigo and violet highlights on a sophisticated dark base.', colors:['#818cf8','#a78bfa','#38bdf8','#141427'] },
    { key:'forest-light', family:'forest', mode:'light', label:'Forest Green - Light', help:'Calm green and emerald tones for a natural workspace.', colors:['#15803d','#047857','#84cc16','#f5fbf5'] },
    { key:'forest-dark', family:'forest', mode:'dark', label:'Forest Green - Dark', help:'Emerald highlights on deep forest surfaces.', colors:['#4ade80','#34d399','#a3e635','#0c1a12'] },
    { key:'rose-light', family:'rose', mode:'light', label:'Rose Coral - Light', help:'Warm rose and coral accents on a soft neutral background.', colors:['#e11d48','#f97316','#db2777','#fff7f8'] },
    { key:'rose-dark', family:'rose', mode:'dark', label:'Rose Coral - Dark', help:'Rose, coral and pink accents designed for dark mode.', colors:['#fb7185','#fb923c','#f472b6','#211116'] },
    { key:'amber-light', family:'amber', mode:'light', label:'Amber Sand - Light', help:'Warm amber and brown tones with a comfortable cream base.', colors:['#d97706','#92400e','#eab308','#fffaf0'] },
    { key:'amber-dark', family:'amber', mode:'dark', label:'Amber Sand - Dark', help:'Golden amber highlights on deep espresso surfaces.', colors:['#fbbf24','#f59e0b','#facc15','#1d160b'] },
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
