import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import '@fontsource/hind-vadodara/400.css';
import '@fontsource/hind-vadodara/500.css';
import '@fontsource/hind-vadodara/600.css';
import '@fontsource/hind-vadodara/700.css';
import '@fontsource/noto-sans-gujarati/400.css';
import '@fontsource/noto-sans-gujarati/600.css';
import '@fontsource/hind/400.css';
import '@fontsource/hind/600.css';
import '@fontsource/noto-sans-devanagari/400.css';
import '@fontsource/noto-sans-devanagari/600.css';
import '@fontsource/inter/400.css';
import '@fontsource/inter/600.css';
import '@fontsource/roboto/400.css';
import '@fontsource/roboto/600.css';
import type { BrandingSettings, SharedAppearance } from './types';

let portalName = 'Karyalay Portal';

function applyAppearance(appearance?: SharedAppearance) {
    if (!appearance) return;
    const bySlug = new Map(appearance.families?.map(f => [f.slug, f]) ?? []);
    const familyName = (slug: string) => bySlug.get(slug)?.css_family || slug.replace(/-/g, ' ');
    const a = appearance.assignments;
    document.documentElement.style.setProperty('--font-global', `'${familyName(a.global)}', sans-serif`);
    document.documentElement.style.setProperty('--font-body', `var(--font-global)`);
    document.documentElement.style.setProperty('--font-heading', `var(--font-global)`);
    document.documentElement.style.setProperty('--font-english', `'${familyName(a.english)}', sans-serif`);
    document.documentElement.style.setProperty('--font-hindi', `'${familyName(a.hindi)}', sans-serif`);
    document.documentElement.style.setProperty('--font-gujarati', `'${familyName(a.gujarati)}', sans-serif`);

    document.getElementById('custom-font-runtime')?.remove();
    const customFamilies = (appearance.families ?? []).filter(f => f.source === 'custom' && f.files?.length);
    if (customFamilies.length) {
        const style = document.createElement('style');
        style.id = 'custom-font-runtime';
        style.textContent = customFamilies.flatMap(f => f.files.map(file =>
            `@font-face{font-family:${JSON.stringify(f.css_family)};src:url(${JSON.stringify(file.asset_url)}) format('${file.original_name.toLowerCase().endsWith('.woff2') ? 'woff2' : file.original_name.toLowerCase().endsWith('.woff') ? 'woff' : file.original_name.toLowerCase().endsWith('.otf') ? 'opentype' : 'truetype'}');font-weight:${file.weight};font-style:${file.style};font-display:swap}`
        )).join('\n');
        document.head.appendChild(style);
    }
}

function applySharedUi(props: Record<string, unknown>) {
    const branding = props.branding as BrandingSettings | undefined;
    const locale = typeof props.locale === 'string' ? props.locale : branding?.default_language || 'en';
    portalName = branding?.portal_name?.trim() || 'Karyalay Portal';
    document.documentElement.lang = locale;
    document.title = portalName;
    applyAppearance(props.appearance as SharedAppearance | undefined);
}

createInertiaApp({
    title: title => title ? `${title} - ${portalName}` : portalName,
    resolve: name => resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx')),
    setup({ el, App, props }) {
        applySharedUi(props.initialPage.props as Record<string, unknown>);
        router.on('navigate', event => applySharedUi(event.detail.page.props as Record<string, unknown>));
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#2a9d8f' },
});
