import { useState, type InputHTMLAttributes } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { useT } from '../../i18n';

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>;

/**
 * A password box you can read back.
 *
 * Typing a long password blind is where most failed sign-ins come from, so
 * every password field in the portal carries this. The field styling still
 * comes from whatever wraps it — .auth-field input, .profile-field input —
 * because the input keeps its place in that tree; only the eye is added.
 *
 * It starts hidden every time and is never remembered: revealing is a
 * deliberate act, and a shared office screen should not keep showing it.
 */
export default function PasswordInput(props: Props) {
    const t = useT();
    const [shown, setShown] = useState(false);
    const label = shown ? t('auth.hide_password') : t('auth.show_password');

    return (
        <span className="password-field">
            <input {...props} type={shown ? 'text' : 'password'} />
            <button
                type="button"
                className="password-reveal"
                onClick={() => setShown(value => !value)}
                aria-label={label}
                aria-pressed={shown}
                title={label}
            >
                {shown ? <EyeOff size={16} /> : <Eye size={16} />}
            </button>
        </span>
    );
}
