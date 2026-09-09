import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import type { BrandingSettings, SharedProps } from './types';

const fallback: BrandingSettings = {
    portal_name: 'Karyalay Portal',
    login_text: 'Secure office media and document portal',
    default_language: 'en',
    logo_path: null,
    logo_url: '/logo.svg',
};

export function usePortalBranding() {
    const props = usePage().props as unknown as Partial<SharedProps>;
    const branding = { ...fallback, ...(props.branding ?? {}) } as BrandingSettings;
    const locale = props.locale || branding.default_language || 'en';

    useEffect(() => {
        document.documentElement.lang = locale;
        document.title = branding.portal_name;
    }, [branding.portal_name, locale]);

    return { branding, locale };
}
