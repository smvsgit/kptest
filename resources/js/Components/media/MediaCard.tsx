import { Check, Video as VideoIcon, LockKeyhole, Globe2, ShieldAlert, Building2, Archive, Copy, Star, Link2 } from 'lucide-react';
import type { MediaFile } from '../../types';

const VideoPlaceholder = () => <VideoIcon size={42} style={{ color: 'var(--text-muted)' }} />;

interface Props {
    file: MediaFile;
    isSelected: boolean;
    onSelect: () => void;
    onFavorite: () => void;
    onOpen: () => void;
}

function formatBytes(bytes: number) {
    if (!bytes) return '0 B';
    const k = 1024, sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

export default function MediaCard({ file, isSelected, onSelect, onFavorite, onOpen }: Props) {
    const handleClick = (e: React.MouseEvent) => {
        const target = e.target as HTMLElement;
        const overlay = target.closest('.card-select-overlay');
        const favorite = target.closest('.card-favorite-btn');
        if (overlay) { e.stopPropagation(); onSelect(); }
        else if (favorite) { e.stopPropagation(); onFavorite(); }
        else { onOpen(); }
    };

    const renderPreview = () => {
        if (!file.can_preview) {
            return <div className="doc-card-representation" style={{ gap: 8 }}><LockKeyhole size={42} /><span style={{ fontSize: 11 }}>Protected content</span></div>;
        }
        if (file.type === 'image') {
            return file.thumbnail_url ? <img src={file.thumbnail_url} alt={file.name} className="card-image" loading="lazy" /> : <div className="doc-card-representation">Image processing…</div>;
        }
        if (file.type === 'video') {
            return <>
                {file.thumbnail_url ? <img src={file.thumbnail_url} alt={file.name} className="card-image" loading="lazy" /> : <div className="doc-card-representation"><VideoPlaceholder /></div>}
                <div className="video-overlay-play">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3" /></svg>
                </div>
            </>;
        }
        if (file.type === 'audio') {
            return <div className="audio-card-representation">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                    <path d="M9 18V5l12-2v13" /><circle cx="6" cy="18" r="3" /><circle cx="18" cy="16" r="3" />
                </svg>
                <div className="audio-waveform-bar"><span /><span /><span /><span /><span /></div>
            </div>;
        }
        const ext = file.name.split('.').pop()?.toUpperCase() || 'FILE';
        const iconColor: Record<string, string> = {
            PDF: '#e76f51', DOCX: '#2a5bd7', DOC: '#2a5bd7', XLSX: '#1e6f40', XLS: '#1e6f40',
            PPTX: '#c84b2f', PPT: '#c84b2f', ZIP: '#e9c46a', RAR: '#e9c46a', '7Z': '#e9c46a',
        };
        return <div className="doc-card-representation"><div style={{ width: 48, height: 56, borderRadius: 6, background: iconColor[ext] || 'var(--text-secondary)', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', color: '#fff', gap: 2 }}><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /></svg><span style={{ fontSize: 9, fontWeight: 700, letterSpacing: 0.5 }}>{ext}</span></div></div>;
    };

    const policy = file.access_policy === 'public'
        ? { icon: <Globe2 size={11} />, label: 'Public', cls: 'access-public' }
        : file.access_policy === 'protected'
            ? { icon: <ShieldAlert size={11} />, label: file.can_preview ? 'Protected · Granted' : 'Protected · Locked', cls: 'access-protected' }
            : { icon: <Building2 size={11} />, label: 'Private', cls: 'access-private' };

    return (
        <div className={`media-card${isSelected ? ' selected' : ''}`} data-id={file.id} onClick={handleClick}>
            <div className="card-select-overlay"><div className="custom-checkbox"><Check size={14} /></div></div>
            <button className={`card-favorite-btn${file.is_favorite ? ' active' : ''}`} title={file.is_favorite ? 'Remove from favorites' : 'Add to favorites'} aria-label={file.is_favorite ? 'Remove from favorites' : 'Add to favorites'}><Star size={15} fill={file.is_favorite ? 'currentColor' : 'none'} /></button>
            <div className="card-preview-wrapper">{renderPreview()}<span className={`card-type-badge badge-${file.type}`}>{file.type}</span></div>
            <div className="card-details">
                <div style={{ display: 'flex', gap: 6, alignItems: 'center', justifyContent: 'space-between' }}>
                    <span className="card-filename" title={file.name}>{file.name}</span>
                </div>
                <div style={{display:'flex',gap:5,flexWrap:'wrap'}}><span className={`access-policy-badge ${policy.cls}`}>{policy.icon}{policy.label}</span><span className="access-policy-badge">v{file.current_version||1}</span>{file.asset_status==='archived'&&<span className="access-policy-badge"><Archive size={10}/> Archived</span>}{file.source_health_status&&<span className={`access-policy-badge source-${file.source_health_status}`} title="Linked source health"><Link2 size={10}/> {file.source_health_status}</span>}{file.duplicate_of_id&&<span className="access-policy-badge" title={`Potential duplicate of asset #${file.duplicate_of_id}`}><Copy size={10}/> Duplicate?</span>}</div>
                <div className="card-meta-row"><span>{formatBytes(file.size)}</span><span>{new Date(file.created_at).toLocaleDateString()}</span></div>
                <div className="card-tags">{(file.tags || []).slice(0, 3).map(tag => <span key={tag} className="card-tag">{tag}</span>)}{(file.tags || []).length > 3 && <span className="card-tag" style={{ background: 'transparent' }}>+{(file.tags || []).length - 3}</span>}</div>
            </div>
        </div>
    );
}
