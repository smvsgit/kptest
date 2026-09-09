import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
    panelName:string;
    resetKey:string;
    onReturn?:()=>void;
    children:ReactNode;
}
interface State { error:Error|null; }

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
        return <section className="module-panel active">
            <div className="settings-card" style={{maxWidth:760,margin:'24px auto'}}>
                <h2 className="settings-card-title">{this.props.panelName} could not be displayed</h2>
                <div className="admin-guide">The application shell is still running. This panel hit a client-side rendering error instead of taking the whole portal to a blank screen.</div>
                <div className="setting-help" style={{marginTop:10,wordBreak:'break-word'}}>{this.state.error.message||'Unknown panel error'}</div>
                {this.props.onReturn&&<button className="btn btn-primary" style={{marginTop:14}} onClick={this.props.onReturn}>Return to Browse Files</button>}
            </div>
        </section>;
    }
}
