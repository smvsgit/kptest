import { router } from '@inertiajs/react';
import { Languages } from 'lucide-react';
import { usePortalBranding } from '../branding';
import { useTranslation } from '../i18n';

/**
 * The masthead every sign-in screen shares: the portal's logo and name, and
 * the language switch.
 *
 * The switch has to live here rather than in the header, because nobody has
 * signed in yet and there is no account to store a preference on. It posts to
 * /locale, which writes a cookie the server reads on the next request.
 */
export default function AuthBrand() {
    const { branding } = usePortalBranding();
    const { t, locale } = useTranslation();

    const choose = (language: string) => {
        if (language === locale) return;
        router.post('/locale', { language }, { preserveScroll: true });
    };

    return (
        <div className="auth-brand-row">
            <div className="auth-brand">
                <img
                    src={branding.logo_url}
                    alt={branding.portal_name}
                    onError={e => {
                        const img = e.currentTarget;
                        if (!img.src.endsWith('/logo.svg')) img.src = '/logo.svg';
                        else img.style.display = 'none';
                    }}
                />
                <span>{branding.portal_name}</span>
            </div>

            <div className="auth-lang" role="group" aria-label={t('common.language')}>
                <Languages size={14} aria-hidden="true" />
                <button
                    type="button"
                    className={locale === 'en' ? 'active' : ''}
                    aria-pressed={locale === 'en'}
                    onClick={() => choose('en')}
                >{t('common.english')}</button>
                <button
                    type="button"
                    className={locale === 'gu' ? 'active' : ''}
                    aria-pressed={locale === 'gu'}
                    onClick={() => choose('gu')}
                >{t('common.gujarati')}</button>
            </div>
        </div>
    );
}
