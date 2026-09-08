import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { UploadCloud, Upload, Video, Image, Music, FileText, Trash, XCircle } from 'lucide-react';
import type { AccessPolicy, Category, UserRole, MasterDataMap, MetadataSettings, UploadSettings } from '../../types';

interface QueueItem {
    id:string; file:File; name:string; type:'image'|'video'|'audio'|'document'; size:number;
    status:'pending'|'uploading'|'success'|'error'|'cancelled'; previewUrl?:string;
    uploadProgress?:number; uploadedBytes?:number; isChunked?:boolean;
}
interface Props { active:boolean; categories:Category[]; simulatedRole:UserRole; masterData:MasterDataMap; metadataSettings:MetadataSettings; uploadSettings:UploadSettings; onSuccess:(msg:string)=>void; onError:(msg:string)=>void; }
interface MetadataForm { year:string; country_id:string; state_id:string; city_id:string; mandir_id:string; event_id:string; person_id:string; language_id:string; media_type_id:string; description:string; internal_remarks:string; source_type:string; asset_status:string; }
const IMAGE_EXTS=['jpg','jpeg','png','webp','gif','svg','bmp','tiff','tif','ico','heic','heif','avif','raw'];
const VIDEO_EXTS=['mp4','mov','avi','mkv','webm','flv','wmv','m4v','3gp'];
const AUDIO_EXTS=['mp3','wav','aac','ogg','m4a','flac','wma','opus'];
const DOC_EXTS=['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf','zip','rar','7z'];
const DEFAULT_ACCEPT=[...IMAGE_EXTS,...VIDEO_EXTS,...AUDIO_EXTS,...DOC_EXTS].map(e=>`.${e}`).join(',');

function getFileType(name:string):QueueItem['type'] { const ext=name.split('.').pop()?.toLowerCase()||''; if(VIDEO_EXTS.includes(ext))return'video'; if(IMAGE_EXTS.includes(ext))return'image'; if(AUDIO_EXTS.includes(ext))return'audio'; return'document'; }
function formatBytes(bytes:number, precision=2){ if(!bytes)return'0 B'; const k=1024,s=['B','KB','MB','GB','TB']; const i=Math.min(s.length-1,Math.floor(Math.log(bytes)/Math.log(k))); return `${(bytes/Math.pow(k,i)).toFixed(i===0?0:precision)} ${s[i]}`; }
function getCsrf(){ return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')||''; }

function xhrUpload(url:string, fd:FormData, onProgress:(loaded:number,total:number)=>void, activeRef:React.MutableRefObject<XMLHttpRequest|null>):Promise<void>{
    return new Promise((resolve,reject)=>{
        const xhr=new XMLHttpRequest(); activeRef.current=xhr; xhr.open('POST',url,true); xhr.setRequestHeader('X-CSRF-TOKEN',getCsrf()); xhr.setRequestHeader('Accept','application/json');
        xhr.upload.onprogress=e=>{ if(e.lengthComputable) onProgress(e.loaded,e.total); };
        xhr.onload=()=>{ activeRef.current=null; if(xhr.status>=200&&xhr.status<300)resolve(); else { let msg=`HTTP ${xhr.status}`; try{msg=JSON.parse(xhr.responseText)?.message||msg}catch{} reject(new Error(msg)); } };
        xhr.onerror=()=>{activeRef.current=null;reject(new Error('Network error during upload.'));};
        xhr.onabort=()=>{activeRef.current=null;reject(new DOMException('Upload cancelled','AbortError'));}; xhr.send(fd);
    });
}

function appendMetadata(fd:FormData,meta:MetadataForm){ Object.entries(meta).forEach(([k,v])=>{if(v!==''&&v!=null)fd.append(k,String(v))}); }
async function uploadSimple(item:QueueItem,cat:string,sub:string,tags:string,accessPolicy:AccessPolicy,downloadAllowed:boolean,meta:MetadataForm,onProgress:(bytes:number)=>void,activeRef:React.MutableRefObject<XMLHttpRequest|null>){
    const fd=new FormData(); fd.append('file',item.file);fd.append('category_id',cat);if(sub)fd.append('subcategory_id',sub);if(tags)fd.append('tags',tags);fd.append('access_policy',accessPolicy);fd.append('download_allowed',downloadAllowed?'1':'0');appendMetadata(fd,meta);
    await xhrUpload('/files',fd,(loaded,total)=>onProgress(Math.min(item.size,total?Math.round((loaded/total)*item.size):loaded)),activeRef); onProgress(item.size);
}
async function uploadChunked(item:QueueItem,cat:string,sub:string,tags:string,accessPolicy:AccessPolicy,downloadAllowed:boolean,meta:MetadataForm,onProgress:(bytes:number)=>void,activeRef:React.MutableRefObject<XMLHttpRequest|null>,uploadIdRef:React.MutableRefObject<string|null>,chunkSize:number,retryCount:number){
    const resumeKey=`karyalay-upload:${item.name}:${item.size}:${item.file.lastModified}`;
    let uploadId=localStorage.getItem(resumeKey); if(!uploadId){uploadId=crypto.randomUUID();localStorage.setItem(resumeKey,uploadId);} uploadIdRef.current=uploadId;
    const totalChunks=Math.ceil(item.file.size/chunkSize);
    let received:number[]=[];
    try{const r=await fetch(`/files/chunk/${uploadId}/status`,{headers:{Accept:'application/json'}});if(r.ok)received=(await r.json()).received||[];}catch{}
    let restored=0; for(const i of received){restored+=Math.min(chunkSize,Math.max(0,item.file.size-i*chunkSize));} onProgress(Math.min(restored,item.file.size));
    for(let i=0;i<totalChunks;i++){
        const start=i*chunkSize,end=Math.min(start+chunkSize,item.file.size),blob=item.file.slice(start,end),isLast=i===totalChunks-1;
        if(received.includes(i)&&!isLast){onProgress(end);continue;}
        const fd=new FormData(); fd.append('upload_id',uploadId);fd.append('chunk_index',String(i));fd.append('total_chunks',String(totalChunks));fd.append('total_size',String(item.file.size));fd.append('chunk',blob,item.name);fd.append('is_last_chunk',isLast?'true':'false');fd.append('filename',item.name);
        if(isLast){fd.append('category_id',cat);if(sub)fd.append('subcategory_id',sub);if(tags)fd.append('tags',tags);fd.append('access_policy',accessPolicy);fd.append('download_allowed',downloadAllowed?'1':'0');appendMetadata(fd,meta);}
        let lastError:unknown;
        for(let attempt=1;attempt<=retryCount;attempt++){
            try { await xhrUpload('/files/chunk',fd,(loaded,total)=>{const chunkLoaded=total?Math.min(blob.size,Math.round((loaded/total)*blob.size)):Math.min(blob.size,loaded);onProgress(start+chunkLoaded);},activeRef); lastError=undefined; break; }
            catch(e){ lastError=e; if(e instanceof DOMException&&e.name==='AbortError') throw e; if(attempt<retryCount) await new Promise(r=>setTimeout(r,600*attempt)); }
        }
        if(lastError)throw lastError; onProgress(end);
    }
    localStorage.removeItem(resumeKey); uploadIdRef.current=null;
}

export default function UploadPanel({active,categories,simulatedRole,masterData,metadataSettings,uploadSettings,onSuccess,onError}:Props){
    const [queue,setQueue]=useState<QueueItem[]>([]),[isDragging,setIsDragging]=useState(false),[isUploading,setIsUploading]=useState(false);
    const [selectedCat,setSelectedCat]=useState(''),[selectedSub,setSelectedSub]=useState(''),[tags,setTags]=useState('');
    const [accessPolicy,setAccessPolicy]=useState<AccessPolicy>('public'),[downloadAllowed,setDownloadAllowed]=useState(true);
    const [meta,setMeta]=useState<MetadataForm>({year:String(new Date().getFullYear()),country_id:'',state_id:'',city_id:'',mandir_id:'',event_id:'',person_id:'',language_id:'',media_type_id:'',description:'',internal_remarks:'',source_type:'local',asset_status:'active'});
    const inputRef=useRef<HTMLInputElement>(null), activeXhr=useRef<XMLHttpRequest|null>(null), activeUploadId=useRef<string|null>(null), cancelRequested=useRef(false);
    if(!active)return null;
    const canUpload=simulatedRole!=='viewer', selectedCatObj=categories.find(c=>c.id===Number(selectedCat));
    const totalBytes=queue.reduce((s,q)=>s+q.size,0), uploadedBytes=queue.reduce((s,q)=>s+(q.status==='success'?q.size:(q.uploadedBytes||0)),0), progress=totalBytes?Math.round((uploadedBytes/totalBytes)*100):0;
    const chunkSize=Math.max(5,Math.min(20,uploadSettings.chunk_size_mb||10))*1024*1024, chunkThreshold=Math.max(5,uploadSettings.chunk_threshold_mb||50)*1024*1024, retryCount=Math.max(1,Math.min(10,uploadSettings.retry_count||3));
    const accept=(uploadSettings.allowed_extensions?.length?uploadSettings.allowed_extensions.map(e=>`.${e}`).join(','):DEFAULT_ACCEPT);
    const addFiles=(files:FileList)=>{const incoming=Array.from(files);if(uploadSettings.max_batch_count>0&&queue.length+incoming.length>uploadSettings.max_batch_count){onError(`Maximum ${uploadSettings.max_batch_count} files are allowed per batch.`);return;}setQueue(prev=>[...prev,...incoming.map(f=>{const type=getFileType(f.name);return{id:crypto.randomUUID(),file:f,name:f.name,type,size:f.size,status:'pending' as const,previewUrl:type==='image'?URL.createObjectURL(f):undefined,uploadedBytes:0};})])};
    const remove=(id:string)=>setQueue(prev=>{const x=prev.find(q=>q.id===id);if(x?.previewUrl)URL.revokeObjectURL(x.previewUrl);return prev.filter(q=>q.id!==id);});
    const cancelUpload=()=>{cancelRequested.current=true;activeXhr.current?.abort();if(activeUploadId.current){fetch(`/files/chunk/${activeUploadId.current}`,{method:'DELETE',headers:{'X-CSRF-TOKEN':getCsrf(),'Accept':'application/json'}}).catch(()=>{});activeUploadId.current=null;}};
    const startUpload=async()=>{
        if(!canUpload)return onError('Permission denied.'); if(!selectedCat)return onError('Please select a category.'); const required=[...(metadataSettings.required_fields||[]),...(metadataSettings.person_required?['person_id']:[])]; const missing=required.filter(k=>!(meta as unknown as Record<string,string>)[k]); if(missing.length)return onError(`Required metadata missing: ${missing.join(', ')}`); const pending=queue.filter(q=>q.status==='pending'||q.status==='error'||q.status==='cancelled'); if(!pending.length)return onError('No files pending.');
        try{const r=await fetch('/files/upload-preflight',{method:'POST',headers:{'X-CSRF-TOKEN':getCsrf(),'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({files:pending.map(q=>({name:q.name,size:q.size}))})});const data=await r.json();if(!r.ok||!data.ok)throw new Error(data.message||data.files?.flatMap((x:any)=>x.errors||[]).join('; ')||'Upload policy check failed.');const dupes=(data.files||[]).filter((x:any)=>(x.possible_duplicates||[]).length);if(dupes.length&&!confirm(`${dupes.length} file(s) may already exist with the same name and size. Continue upload?`))return;}catch(e){return onError(e instanceof Error?e.message:'Upload preflight failed.');}
        setIsUploading(true);cancelRequested.current=false;let success=0;
        for(const item of pending){
            if(cancelRequested.current)break; const isChunked=item.size>=chunkThreshold;
            setQueue(prev=>prev.map(q=>q.id===item.id?{...q,status:'uploading',uploadedBytes:0,uploadProgress:0,isChunked}:q));
            try{
                const onBytes=(bytes:number)=>setQueue(prev=>prev.map(q=>q.id===item.id?{...q,uploadedBytes:bytes,uploadProgress:Math.min(100,Math.round(bytes/item.size*100))}:q));
                if(isChunked)await uploadChunked(item,selectedCat,selectedSub,tags,accessPolicy,downloadAllowed,meta,onBytes,activeXhr,activeUploadId,chunkSize,retryCount);else await uploadSimple(item,selectedCat,selectedSub,tags,accessPolicy,downloadAllowed,meta,onBytes,activeXhr);
                setQueue(prev=>prev.map(q=>q.id===item.id?{...q,status:'success',uploadedBytes:item.size,uploadProgress:100}:q));success++;
            }catch(e){const cancelled=e instanceof DOMException&&e.name==='AbortError';setQueue(prev=>prev.map(q=>q.id===item.id?{...q,status:cancelled?'cancelled':'error'}:q));if(!cancelled)onError(`${item.name}: ${e instanceof Error?e.message:'Upload failed'}`);if(cancelled)break;}
        }
        setIsUploading(false);activeXhr.current=null;if(success){onSuccess(`Successfully uploaded ${success} file${success>1?'s':''}.`);router.reload({only:['files','stats','categories']});}
    };
    const activeValues=(type:keyof MasterDataMap)=>masterData[type]?.filter(x=>x.is_active)||[];
    const countries=activeValues('country'), states=activeValues('state').filter(x=>!meta.country_id||x.parent_id===Number(meta.country_id)), cities=activeValues('city').filter(x=>!meta.state_id||x.parent_id===Number(meta.state_id)), mandirs=activeValues('mandir').filter(x=>!meta.city_id||x.parent_id===Number(meta.city_id));
    const setM=(key:keyof MetadataForm,value:string)=>setMeta(m=>({...m,[key]:value}));
    const labelReq=(field:string)=>metadataSettings.required_fields?.includes(field)||(field==='person_id'&&metadataSettings.person_required);
    const masterSelect=(label:string,field:keyof MetadataForm,values:any[])=> <div className="form-group"><label className="form-label">{label}{labelReq(String(field))?' *':''}</label><select className="form-select" value={meta[field]} onChange={e=>setM(field,e.target.value)}><option value="">-- Select --</option>{values.map((x:any)=><option key={x.id} value={x.id}>{x.name}{x.code?` (${x.code})`:''}</option>)}</select></div>;
    const icon=(t:QueueItem['type'])=>t==='video'?<Video size={16}/>:t==='image'?<Image size={16}/>:t==='audio'?<Music size={16}/>:<FileText size={16}/>;
    return <section className="module-panel active" id="upload-panel">
        <div className="page-title-section"><div><h1 className="page-title"><UploadCloud size={28} style={{color:'var(--color-primary)'}}/>Batch Upload</h1><p className="page-subtitle">Resumable chunk uploads with real transferred size and percentage.</p></div></div>
        <div className="upload-container"><div>
            <div className={`dropzone${isDragging?' active':''}`} onDragOver={e=>{e.preventDefault();setIsDragging(true)}} onDragLeave={()=>setIsDragging(false)} onDrop={e=>{e.preventDefault();setIsDragging(false);if(e.dataTransfer.files.length)addFiles(e.dataTransfer.files)}} onClick={()=>!isUploading&&inputRef.current?.click()}>
                <div className="dropzone-icon"><Upload size={28}/></div><h3 className="dropzone-title">Drag & drop files here or click to browse</h3><p className="dropzone-subtitle">Files {uploadSettings.chunk_threshold_mb} MB+ use {uploadSettings.chunk_size_mb} MB chunks · failed chunks retry up to {uploadSettings.retry_count} times</p><input ref={inputRef} type="file" multiple accept={accept} style={{display:'none'}} onChange={e=>{if(e.target.files)addFiles(e.target.files);e.currentTarget.value=''}}/>
            </div>
            {!!queue.length&&<div className="upload-queue"><div className="queue-header"><div><h2 className="queue-title">Upload Queue ({queue.length})</h2><span className="queue-total-size">{formatBytes(totalBytes)}</span></div><div style={{display:'flex',gap:8}}>{isUploading?<button className="btn btn-secondary" onClick={cancelUpload}><XCircle size={14}/> Cancel Upload</button>:<button className="btn btn-secondary" onClick={()=>{queue.forEach(q=>q.previewUrl&&URL.revokeObjectURL(q.previewUrl));setQueue([])}}>Clear Queue</button>}</div></div>
                {isUploading&&<div style={{marginBottom:16,background:'var(--bg-card)',border:'1px solid var(--border-color)',padding:16,borderRadius:'var(--radius-md)'}}><div style={{display:'flex',justifyContent:'space-between',fontWeight:600,fontSize:13,marginBottom:6}}><span>Uploading {formatBytes(uploadedBytes)} / {formatBytes(totalBytes)}</span><span>{progress}%</span></div><div className="progress-bar-container"><div className="progress-bar-fill" style={{width:`${progress}%`}}/></div></div>}
                <div className="queue-list">{queue.map(item=><div key={item.id} className="queue-item" style={{flexWrap:'wrap'}}><div className="queue-item-left"><div className="queue-file-icon">{item.previewUrl?<img src={item.previewUrl} alt="" style={{width:36,height:36,objectFit:'cover',borderRadius:6}}/>:icon(item.type)}</div><div className="queue-file-details"><span className="queue-file-name">{item.name}</span><span className="queue-file-size">{item.status==='uploading'?`${formatBytes(item.uploadedBytes||0)} / ${formatBytes(item.size)}`:formatBytes(item.size)}{item.isChunked&&<b style={{marginLeft:6,fontSize:9,color:'var(--color-primary)'}}>CHUNKED</b>}</span></div></div><div className="queue-item-right"><span className={`queue-status-tag status-${item.status}`}>{item.status==='uploading'?`${item.uploadProgress||0}%`:item.status}</span>{!isUploading&&item.status!=='success'&&<button className="queue-remove-btn" onClick={()=>remove(item.id)}><Trash size={14}/></button>}</div>{item.status==='uploading'&&<div style={{width:'100%',paddingTop:6}}><div className="progress-bar-container"><div className="progress-bar-fill" style={{width:`${item.uploadProgress||0}%`}}/></div></div>}</div>)}</div>
            </div>}
        </div><div className="upload-sidebar-card"><h2 className="sidebar-card-title">Batch Settings</h2><div className="form-group"><label className="form-label">Category *</label><select className="form-select" value={selectedCat} onChange={e=>{setSelectedCat(e.target.value);setSelectedSub('')}}><option value="">-- Select Category --</option>{categories.map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select></div><div className="form-group"><label className="form-label">Sub-category</label><select className="form-select" value={selectedSub} onChange={e=>setSelectedSub(e.target.value)} disabled={!selectedCatObj}><option value="">-- Select Sub-category --</option>{selectedCatObj?.subcategories.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></div><div className="form-group"><label className="form-label">Tags</label><input className="form-input" value={tags} onChange={e=>setTags(e.target.value)} placeholder="event, location, person"/></div><div style={{borderTop:'1px solid var(--border-color)',paddingTop:10,marginTop:10}}><div style={{fontWeight:800,fontSize:12,marginBottom:8}}>Required Metadata</div><div className="form-group"><label className="form-label">Year{labelReq('year')?' *':''}</label><input className="form-input" type="number" min={metadataSettings.years_min} max={metadataSettings.years_max} value={meta.year} onChange={e=>setM('year',e.target.value)}/></div>{masterSelect('Country','country_id',countries)}{masterSelect('State','state_id',states)}{masterSelect('City / Location','city_id',cities)}{masterSelect('Mandir','mandir_id',mandirs)}{masterSelect('Event / Prasang','event_id',activeValues('event'))}{masterSelect('Guruji / Person','person_id',activeValues('person'))}{masterSelect('Language','language_id',activeValues('language'))}{masterSelect('Media Type','media_type_id',activeValues('media_type'))}<div className="form-group"><label className="form-label">Description{labelReq('description')?' *':''}</label><textarea className="form-input" rows={3} value={meta.description} onChange={e=>setM('description',e.target.value)} placeholder="Asset description"/></div><div className="form-group"><label className="form-label">Internal Remarks</label><textarea className="form-input" rows={2} value={meta.internal_remarks} onChange={e=>setM('internal_remarks',e.target.value)} placeholder="Internal operational note"/></div><div className="form-group"><label className="form-label">Source</label><select className="form-select" value={meta.source_type} onChange={e=>setM('source_type',e.target.value)}><option value="local">Local Upload</option><option value="nas">NAS</option><option value="google-drive">Google Drive</option><option value="youtube">YouTube</option></select></div><div className="form-group"><label className="form-label">Asset Status</label><select className="form-select" value={meta.asset_status} onChange={e=>setM('asset_status',e.target.value)}><option value="active">Active</option><option value="draft">Draft</option><option value="review">Review</option><option value="approved">Approved</option><option value="published">Published</option><option value="archived">Archived</option></select></div></div><div className="form-group"><label className="form-label">Access Policy *</label><select className="form-select" value={accessPolicy} onChange={e=>setAccessPolicy(e.target.value as AccessPolicy)}><option value="public">Public — internal users can access</option><option value="protected">Protected — approval required</option><option value="private">Private — owner department only</option></select><span style={{fontSize:10,color:'var(--text-muted)'}}>{accessPolicy==='public'?'Visible to registered internal users.':accessPolicy==='protected'?'Metadata visible; content remains locked until approved.':'Hidden from users outside the owner department.'}</span></div><label style={{display:'flex',gap:8,alignItems:'center',fontSize:12,color:'var(--text-secondary)',marginBottom:8}}><input type="checkbox" checked={downloadAllowed} onChange={e=>setDownloadAllowed(e.target.checked)}/> Allow downloads when the user is otherwise authorized</label><button className="btn btn-primary" style={{width:'100%',height:44}} disabled={!canUpload||!queue.some(q=>q.status!=='success')||!selectedCat||isUploading} onClick={startUpload}>{isUploading?`Uploading ${progress}%`:'Start Batch Upload'}</button></div></div>
    </section>;
}
