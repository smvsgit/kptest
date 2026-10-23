import { useEffect, useState } from 'react';
import { Download, X, ZoomIn, ZoomOut } from 'lucide-react';
import type { MediaFile } from '../../types';
import { formatBytes } from '../../format';

interface Props {
    file: MediaFile;
    onClose: () => void;
    onDownload: (id: number) => void;
}

/**
 * Full-size image viewing. Always loads the original, so it shows a loading
 * state first: originals are routinely several megabytes and a bare dark pane
 * is indistinguishable from a broken preview.
 */
export default function ImageViewer({ file, onClose, onDownload }: Props) {
    const [actualSize, setActualSize] = useState(false);
    const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
    const src = file.image_url || file.thumbnail_url || undefined;

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    if (!src) return null;

    return (
        <div className="image-zoom-overlay" onClick={e => { if (e.target === e.currentTarget) onClose(); }}>
            <div className="image-zoom-toolbar">
                <span className="image-zoom-name">
                    {file.name}{file.resolution ? ` · ${file.resolution}` : ''} · {formatBytes(file.size)}
                </span>
                <div className="image-zoom-actions">
                    <button className="btn btn-secondary" disabled={status !== 'ready'} onClick={() => setActualSize(v => !v)}
                        title={actualSize ? 'Fit to screen' : 'Show at actual pixel size'}>
                        {actualSize ? <><ZoomOut size={14}/> Fit to screen</> : <><ZoomIn size={14}/> Actual size</>}
                    </button>
                    {file.can_download && <button className="btn btn-primary" onClick={() => onDownload(file.id)}><Download size={14}/> Download</button>}
                    <button className="btn btn-secondary" onClick={onClose} aria-label="Close full-size view"><X size={14}/></button>
                </div>
            </div>

            <div className={`image-zoom-canvas${actualSize ? ' actual' : ''}`}>
                {status === 'loading' && <div className="image-zoom-status"><span className="image-zoom-spinner" aria-hidden="true" />Loading the original ({formatBytes(file.size)})…</div>}
                {status === 'error' && <div className="image-zoom-status">This image could not be loaded. It may still be uploading, or its source may be unavailable.</div>}
                <img
                    src={src}
                    alt={file.name}
                    className={actualSize ? 'actual' : 'fit'}
                    style={status === 'ready' ? undefined : { display: 'none' }}
                    onLoad={() => setStatus('ready')}
                    onError={() => setStatus('error')}
                />
            </div>
        </div>
    );
}
