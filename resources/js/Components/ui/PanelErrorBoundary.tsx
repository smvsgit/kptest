import { Component, type ErrorInfo, type ReactNode } from 'react';
import { useT } from '../../i18n';

interface Props {
    panelName:string;
    resetKey:string;
    onReturn?:()=>void;
    children:ReactNode;
}
interface State { error:Error|null; }

/**
 * The boundary itself has to be a class, and a class cannot call a hook, so the
 * fallback it renders is a small function component — that is where the
 * catalogue is read.
 */
function PanelErrorFallback({panelName,message,onReturn}:{panelName:string;message:string;onReturn?:()=>void}) {
    const t = useT();
    return <section className="module-panel active">
        <div className="settings-card" style={{maxWidth:760,margin:'24px auto'}}>
            <h2 className="settings-card-title">{t('panel.error_title',{panel:panelName})}</h2>
            <div className="admin-guide">{t('panel.error_body')}</div>
            <div className="setting-help" style={{marginTop:10,wordBreak:'break-word'}}>{message||t('panel.unknown_error')}</div>
            {onReturn&&<button className="btn btn-primary" style={{marginTop:14}} onClick={onReturn}>{t('panel.return_browse')}</button>}
        </div>
    </section>;
}

export default class PanelErrorBoundary extends Component<Props,State> {
    state:State={error:null};

    static getDerivedStateFromError(error:Error):State {
        return {error};
    }

    componentDidCatch(error:Error,info:ErrorInfo) {
        console.error(`[Karyalay Portal] ${this.props.panelName} panel render failed`,error,info);
    }

    componentDidUpdate(prevProps:Props) {
        if(prevProps.resetKey!==this.props.resetKey&&this.state.error) this.setState({error:null});
    }

    render() {
        if(!this.state.error) return this.props.children;
        return <PanelErrorFallback panelName={this.props.panelName} message={this.state.error.message} onReturn={this.props.onReturn} />;
    }
}
