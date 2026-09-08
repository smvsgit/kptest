import { CheckCircle, AlertTriangle, Info } from 'lucide-react';
import type { ToastItem } from '../../types';

interface Props {
    toasts: ToastItem[];
    onRemove: (id: string) => void;
}

export default function Toast({ toasts, onRemove }: Props) {
    return (
        <div className="toast-container">
            {toasts.map(t => (
                <div key={t.id} className={`toast toast-${t.type}`} onClick={() => onRemove(t.id)}>
                    {t.type === 'success' && <CheckCircle size={16} />}
                    {t.type === 'error' && <AlertTriangle size={16} />}
                    {t.type === 'info' && <Info size={16} />}
                    <span>{t.message}</span>
                </div>
            ))}
        </div>
    );
}
