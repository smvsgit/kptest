/**
 * Upload mechanics shared by Batch Upload and the folder workspace.
 *
 * Both screens send the same two request shapes — one POST for a small file,
 * a resumable chunk sequence for a large one — so the transfer logic lives
 * here and each screen supplies only the fields that go with the file.
 */

export type MediaKind = 'image' | 'video' | 'audio' | 'document';

export const IMAGE_EXTS = ['jpg','jpeg','png','webp','gif','svg','bmp','tiff','tif','ico','heic','heif','avif','raw'];
export const VIDEO_EXTS = ['mp4','mov','avi','mkv','webm','flv','wmv','m4v','3gp'];
export const AUDIO_EXTS = ['mp3','wav','aac','ogg','m4a','flac','wma','opus'];
export const DOC_EXTS = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf','zip','rar','7z'];
export const DEFAULT_ACCEPT = [...IMAGE_EXTS, ...VIDEO_EXTS, ...AUDIO_EXTS, ...DOC_EXTS].map(e => `.${e}`).join(',');

export interface UploadItem {
    id: string;
    file: File;
    name: string;
    type: MediaKind;
    size: number;
    status: 'pending' | 'uploading' | 'success' | 'error' | 'cancelled';
    previewUrl?: string;
    uploadProgress?: number;
    uploadedBytes?: number;
    isChunked?: boolean;
    error?: string;
    /** Path the browser reported for a folder upload, e.g. "Annakut 2024/Photos/01.jpg". */
    relativePath?: string;
}

/** Everything that travels beside the bytes. */
export interface UploadFields {
    category_id?: string | number | null;
    subcategory_id?: string | number | null;
    tags?: string;
    access_policy: string;
    download_allowed: boolean;
    visible_role_ids?: number[];
    /** Flat metadata values; empty strings and nulls are left out. */
    metadata?: Record<string, string | number | null | undefined>;
    folder_id?: number | null;
    folder_path?: string;
    /** Store the bytes now and describe the asset later; it lands as a draft. */
    defer_metadata?: boolean;
}

export function fileKind(name: string): MediaKind {
    const ext = name.split('.').pop()?.toLowerCase() || '';
    if (VIDEO_EXTS.includes(ext)) return 'video';
    if (IMAGE_EXTS.includes(ext)) return 'image';
    if (AUDIO_EXTS.includes(ext)) return 'audio';
    return 'document';
}

export function getCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

export function xhrUpload(
    url: string,
    fd: FormData,
    onProgress: (loaded: number, total: number) => void,
    activeRef: { current: XMLHttpRequest | null },
): Promise<void> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        activeRef.current = xhr;
        xhr.open('POST', url, true);
        xhr.setRequestHeader('X-CSRF-TOKEN', getCsrf());
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.onprogress = e => { if (e.lengthComputable) onProgress(e.loaded, e.total); };
        xhr.onload = () => {
            activeRef.current = null;
            if (xhr.status >= 200 && xhr.status < 300) return resolve();
            let msg = `HTTP ${xhr.status}`;
            try { msg = JSON.parse(xhr.responseText)?.message || msg; } catch { /* keep the status */ }
            reject(new Error(msg));
        };
        xhr.onerror = () => { activeRef.current = null; reject(new Error('Network error during upload.')); };
        xhr.onabort = () => { activeRef.current = null; reject(new DOMException('Upload cancelled', 'AbortError')); };
        xhr.send(fd);
    });
}

/** Write the asset's fields onto a form body. */
export function appendFields(fd: FormData, fields: UploadFields, item?: UploadItem) {
    if (fields.category_id) fd.append('category_id', String(fields.category_id));
    if (fields.subcategory_id) fd.append('subcategory_id', String(fields.subcategory_id));
    if (fields.tags) fd.append('tags', fields.tags);
    fd.append('access_policy', fields.access_policy);
    fd.append('download_allowed', fields.download_allowed ? '1' : '0');
    (fields.visible_role_ids || []).forEach(id => fd.append('visible_role_ids[]', String(id)));
    if (fields.folder_id) fd.append('folder_id', String(fields.folder_id));
    if (fields.defer_metadata) fd.append('defer_metadata', '1');
    // The item's own path wins, so one queue can mix loose files and a folder.
    const path = item?.relativePath || fields.folder_path;
    if (path) fd.append('folder_path', path);
    Object.entries(fields.metadata || {}).forEach(([k, v]) => {
        if (v !== '' && v != null) fd.append(k, String(v));
    });
}

export async function uploadSimple(
    item: UploadItem,
    fields: UploadFields,
    onProgress: (bytes: number) => void,
    activeRef: { current: XMLHttpRequest | null },
) {
    const fd = new FormData();
    fd.append('file', item.file);
    appendFields(fd, fields, item);
    await xhrUpload('/files', fd, (loaded, total) => {
        onProgress(Math.min(item.size, total ? Math.round((loaded / total) * item.size) : loaded));
    }, activeRef);
    onProgress(item.size);
}

export async function uploadChunked(
    item: UploadItem,
    fields: UploadFields,
    onProgress: (bytes: number) => void,
    activeRef: { current: XMLHttpRequest | null },
    uploadIdRef: { current: string | null },
    chunkSize: number,
    retryCount: number,
) {
    const resumeKey = `karyalay-upload:${item.name}:${item.size}:${item.file.lastModified}`;
    let uploadId: string | null = null;
    try { uploadId = localStorage.getItem(resumeKey); } catch { /* private window */ }
    if (!uploadId) {
        uploadId = crypto.randomUUID();
        try { localStorage.setItem(resumeKey, uploadId); } catch { /* nothing to resume from, carry on */ }
    }
    uploadIdRef.current = uploadId;

    const totalChunks = Math.ceil(item.file.size / chunkSize);
    let received: number[] = [];
    try {
        const r = await fetch(`/files/chunk/${uploadId}/status`, { headers: { Accept: 'application/json' } });
        if (r.ok) received = (await r.json()).received || [];
    } catch { /* no resumable state; start from the first chunk */ }

    let restored = 0;
    for (const i of received) restored += Math.min(chunkSize, Math.max(0, item.file.size - i * chunkSize));
    onProgress(Math.min(restored, item.file.size));

    for (let i = 0; i < totalChunks; i++) {
        const start = i * chunkSize;
        const end = Math.min(start + chunkSize, item.file.size);
        const blob = item.file.slice(start, end);
        const isLast = i === totalChunks - 1;
        if (received.includes(i) && !isLast) { onProgress(end); continue; }

        const fd = new FormData();
        fd.append('upload_id', uploadId);
        fd.append('chunk_index', String(i));
        fd.append('total_chunks', String(totalChunks));
        fd.append('total_size', String(item.file.size));
        fd.append('chunk', blob, item.name);
        fd.append('is_last_chunk', isLast ? 'true' : 'false');
        fd.append('filename', item.name);
        // The asset is created when the last chunk lands, so its fields ride with it.
        if (isLast) appendFields(fd, fields, item);

        let lastError: unknown;
        for (let attempt = 1; attempt <= retryCount; attempt++) {
            try {
                await xhrUpload('/files/chunk', fd, (loaded, total) => {
                    const chunkLoaded = total ? Math.min(blob.size, Math.round((loaded / total) * blob.size)) : Math.min(blob.size, loaded);
                    onProgress(start + chunkLoaded);
                }, activeRef);
                lastError = undefined;
                break;
            } catch (e) {
                lastError = e;
                if (e instanceof DOMException && e.name === 'AbortError') throw e;
                if (attempt < retryCount) await new Promise(r => setTimeout(r, 600 * attempt));
            }
        }
        if (lastError) throw lastError;
        onProgress(end);
    }

    try { localStorage.removeItem(resumeKey); } catch { /* nothing stored */ }
    uploadIdRef.current = null;
}
