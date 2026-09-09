import { usePortalBranding } from '../branding';

export default function AuthBrand() {
    const { branding } = usePortalBranding();
    return (
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
    );
}
