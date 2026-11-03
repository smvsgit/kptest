import { forwardRef, useImperativeHandle, useRef, useState } from 'react';
import { ChevronDown, ChevronUp, UploadCloud, X, Loader2, CheckCircle2, AlertTriangle } from 'lucide-react';
import type { UploadSettings } from '../../types';
import { formatBytes } from '../../format';
import { fileKind, uploadChunked, uploadSimple, type UploadFields, type UploadItem } from '../../upload';

export interface TrayHandle {
    /** Queue a batch and start transferring. `folderId` is the folder that was open. */
    enqueue:(files:File[],folderId:number|null)=>void;
}
interface Props {
    uploadSettings:UploadSettings;
    onFinished:(message:string)=>void;
    onError:(message:string)=>void;
}

/** The path the browser reports for a folder upload, if there is one. */
function relativePathOf(file:File):string|undefined{
    const path=(file as File&{webkitRelativePath?:string}).webkitRelativePath;
    return path&&path.includes('/')?path:undefined;
}

/**
 * Background transfers with visible controls.
 *
 * Review finding B08: an upload running in the background had no way to see
 * what it was doing, pause it or cancel it. The tray stays docked while the
 * rest of the workspace carries on, shows each file's folder and progress, and
 * reports a refusal (an empty file, a quota) in the words the server used.
 */
const FolderUploadTray=forwardRef<TrayHandle,Props>(function FolderUploadTray(p,ref){
    const [queue,setQueue]=useState<UploadItem[]>([]);
    const [running,setRunning]=useState(false);
    const [collapsed,setCollapsed]=useState(false);
    const activeXhr=useRef<XMLHttpRequest|null>(null);
    const activeUploadId=useRef<string|null>(null);
    const cancelled=useRef(false);
    const paused=useRef(false);

    const chunkSize=Math.max(5,Math.min(20,p.uploadSettings.chunk_size_mb||10))*1024*1024;
    const chunkThreshold=Math.max(5,p.uploadSettings.chunk_threshold_mb||50)*1024*1024;
    const retryCount=Math.max(1,Math.min(10,p.uploadSettings.retry_count||3));

    const patch=(id:string,next:Partial<UploadItem>)=>setQueue(q=>q.map(i=>i.id===id?{...i,...next}:i));

    const run=async(items:UploadItem[],folderId:number|null)=>{
        setRunning(true);
        cancelled.current=false;
        paused.current=false;
        let done=0,failed=0;

        for(const item of items){
            if(cancelled.current)break;
            // Pausing holds the queue between files; the chunk sequence of the
            // file already in flight is left to finish or be cancelled.
            while(paused.current&&!cancelled.current)await new Promise(r=>setTimeout(r,250));
            if(cancelled.current)break;

            patch(item.id,{status:'uploading',uploadedBytes:0,uploadProgress:0});
            const fields:UploadFields={
                access_policy:'protected',
                download_allowed:true,
                folder_id:folderId,
                // Metadata comes later, from the inspector.
                defer_metadata:true,
            };
            const onBytes=(bytes:number)=>patch(item.id,{
                uploadedBytes:bytes,
                uploadProgress:item.size?Math.min(100,Math.round((bytes/item.size)*100)):0,
            });

            try{
                if(item.size>=chunkThreshold){
                    patch(item.id,{isChunked:true});
                    await uploadChunked(item,fields,onBytes,activeXhr,activeUploadId,chunkSize,retryCount);
                }else{
                    await uploadSimple(item,fields,onBytes,activeXhr);
                }
                patch(item.id,{status:'success',uploadProgress:100,uploadedBytes:item.size});
                done++;
            }catch(e){
                if(e instanceof DOMException&&e.name==='AbortError'){
                    patch(item.id,{status:'cancelled'});
                    break;
                }
                failed++;
                patch(item.id,{status:'error',error:e instanceof Error?e.message:'Upload failed.'});
            }
        }

        setRunning(false);
        if(done)p.onFinished(`${done} file${done===1?'':'s'} uploaded as draft${done===1?'':'s'}. Select them and use Describe to fill in the metadata.`);
        if(failed)p.onError(`${failed} file${failed===1?'':'s'} could not be uploaded. The reason is shown beside each one.`);
    };

    useImperativeHandle(ref,()=>({
        enqueue(files,folderId){
            const items:UploadItem[]=files.map(file=>({
                id:crypto.randomUUID(),
                file,
                name:file.name,
                type:fileKind(file.name),
                size:file.size,
                status:'pending',
                relativePath:relativePathOf(file),
            }));
            if(!items.length)return;
            setQueue(items);
            setCollapsed(false);
            void run(items,folderId);
        },
    }),[chunkSize,chunkThreshold,retryCount]);

    if(!queue.length)return null;

    const total=queue.reduce((s,i)=>s+i.size,0);
    const moved=queue.reduce((s,i)=>s+(i.status==='success'?i.size:(i.uploadedBytes||0)),0);
    const percent=total?Math.round((moved/total)*100):0;
    const finished=queue.filter(i=>i.status==='success').length;
    const folderCount=new Set(queue.map(i=>i.relativePath?.split('/').slice(0,-1).join('/')).filter(Boolean)).size;

    const cancelAll=()=>{
        cancelled.current=true;
        paused.current=false;
        activeXhr.current?.abort();
        if(activeUploadId.current){
            fetch(`/files/chunk/${activeUploadId.current}`,{
                method:'DELETE',
                headers:{'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')||'','Accept':'application/json'},
            }).catch(()=>{});
            activeUploadId.current=null;
        }
    };

    return (
        <div className="exp-tray" role="status" aria-live="polite">
            <div className="exp-tray-head">
                <UploadCloud size={16} style={{color:'var(--color-primary)'}}/>
                <b>
                    {running?`Uploading ${finished} of ${queue.length}`:`${finished} of ${queue.length} uploaded`}
                    {folderCount>0&&` · ${folderCount} folder${folderCount===1?'':'s'}`}
                </b>
                <span style={{fontSize:11,color:'var(--text-muted)',fontVariantNumeric:'tabular-nums'}}>{percent}%</span>
                <button className="icon-btn" onClick={()=>setCollapsed(c=>!c)} title={collapsed?'Show':'Hide'}>
                    {collapsed?<ChevronUp size={14}/>:<ChevronDown size={14}/>}
                </button>
                {!running&&<button className="icon-btn" onClick={()=>setQueue([])} title="Close"><X size={14}/></button>}
            </div>

            {!collapsed&&(
                <div className="exp-tray-list">
                    {queue.map(item=>(
                        <div className="exp-tray-item" key={item.id}>
                            <span className="nm">{item.name}</span>
                            <span style={{fontSize:10.5,color:'var(--text-muted)',display:'flex',alignItems:'center',gap:4}}>
                                {item.status==='success'&&<CheckCircle2 size={12} style={{color:'var(--color-success)'}}/>}
                                {item.status==='error'&&<AlertTriangle size={12} style={{color:'var(--color-danger)'}}/>}
                                {item.status==='uploading'&&<Loader2 size={12} className="spin"/>}
                                {item.status==='uploading'
                                    ? `${formatBytes(item.uploadedBytes||0)} / ${formatBytes(item.size)}`
                                    : item.status==='error'?'failed':item.status==='cancelled'?'cancelled':formatBytes(item.size)}
                            </span>
                            {(item.relativePath||item.error)&&(
                                <span className={`pth${item.error?' failed':''}`}>
                                    {item.error||item.relativePath!.split('/').slice(0,-1).join(' / ')}
                                </span>
                            )}
                            {item.status==='uploading'&&(
                                <span className="track"><i style={{width:`${item.uploadProgress||0}%`}}/></span>
                            )}
                        </div>
                    ))}
                </div>
            )}

            <div className="exp-tray-foot">
                {running?<>
                    <button className="btn btn-secondary" onClick={()=>{paused.current=!paused.current;setQueue(q=>[...q]);}}>
                        {paused.current?'Resume':'Pause'}
                    </button>
                    <button className="btn btn-secondary" onClick={cancelAll}>Cancel</button>
                    <span style={{fontSize:11,color:'var(--text-muted)'}}>
                        {formatBytes(moved)} of {formatBytes(total)}
                    </span>
                </>:(
                    <span style={{fontSize:11,color:'var(--text-muted)'}}>
                        Everything landed as a draft. Select the files and choose Describe.
                    </span>
                )}
            </div>
        </div>
    );
});

export default FolderUploadTray;
