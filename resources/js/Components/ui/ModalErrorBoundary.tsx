import { Component, type ErrorInfo, type ReactNode } from 'react';
import { X, AlertTriangle } from 'lucide-react';
import { useT } from '../../i18n';

interface Props {
    /** Catalogue key for the dialog's name, e.g. 'actionbar.bulk_edit'. */
    titleKey: string;
    onClose: () => void;
    children: ReactNode;
}
interface State { error: Error | null; }

/**
 * A dialog that fails must not take the portal with it.
 *
 * Every dialog here is `lazy()`-loaded, so an error thrown while the chunk is
 * evaluated — a hook called outside a component, for one — rejects the lazy
 * element itself. With no boundary above it, React unmounts the whole tree and
 * the user is left on a blank white page with no way back except a reload.
 * That is exactly what a module-scope `useT()` in Bulk Edit did between v19.12
 * and v19.13.
 */
function ModalErrorFallback({ titleKey, message, onClose }: { titleKey: string; message: string; onClose: () => void }) {
    const t = useT();
    return <div className="modal-overlay active" onClick={e => e.target === e.currentTarget && onClose()}>
        <div className="modal-container" style={{ maxWidth: 520, width: '92vw', display: 'block' }}>
            <div className="modal-info-pane" style={{ width: '100%' }}>
                <div className="modal-header">
                    <h2 className="modal-title"><AlertTriangle size={20} /> {t('modal.error_title', { name: t(titleKey) })}</h2>
                    <button className="modal-close-btn" onClick={onClose}><X size={20} /></button>
                </div>
                <div className="admin-guide">{t('modal.error_body')}</div>
                <div className="setting-help" style={{ marginTop: 10, wordBreak: 'break-word' }}>{message || t('panel.unknown_error')}</div>
                <button className="btn btn-primary" style={{ marginTop: 14 }} onClick={onClose}>{t('common.close')}</button>
            </div>
        </div>
    </div>;
}

export default class ModalErrorBoundary extends Component<Props, State> {
    state: State = { error: null };

    static getDerivedStateFromError(error: Error): State {
        return { error };
    }

    componentDidCatch(error: Error, info: ErrorInfo) {
        console.error('[Karyalay Portal] dialog failed to render', this.props.titleKey, error, info);
    }

    render() {
        if (!this.state.error) return this.props.children;
        return <ModalErrorFallback titleKey={this.props.titleKey} message={this.state.error.message} onClose={this.props.onClose} />;
    }
}
