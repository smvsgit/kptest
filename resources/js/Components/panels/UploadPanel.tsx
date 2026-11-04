import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { UploadCloud, Upload, Video, Image, Music, FileText, Trash, XCircle, Plus } from 'lucide-react';
import type { AccessPolicy, Category, UserRole, MasterDataMap, MasterDataType, MetadataSettings, UploadSettings, VisibilityRole } from '../../types';
import { useT } from '../../i18n';
import { DEFAULT_ACCEPT as SHARED_ACCEPT, fileKind, uploadChunked, uploadSimple, type UploadFields, type UploadItem } from '../../upload';

interface Props { active:boolean; categories:Category[]; simulatedRole:UserRole; visibilityRoles:VisibilityRole[]; masterData:MasterDataMap; metadataSettings:MetadataSettings; uploadSettings:UploadSettings; onSuccess:(msg:string)=>void; onError:(msg:string)=>void; }
interface MetadataForm { year:string; country_id:string; state_id:string; city_id:string; mandir_id:string; event_id:string; person_id:string; language_id:string; media_type_id:string; description:string; internal_remarks:string; source_type:string; asset_status:string; }
type QueueItem = UploadItem;
const DEFAULT_ACCEPT = SHARED_ACCEPT;
const getFileType = fileKind;
function formatBytes(bytes:number, precision=2){ if(!bytes)return'0 B'; const k=1024,s=['B','KB','MB','GB','TB']; const i=Math.min(s.length-1,Math.floor(Math.log(bytes)/Math.log(k))); return `${(bytes/Math.pow(k,i)).toFixed(i===0?0:precision)} ${s[i]}`; }

/** The upload form still asks for metadata, so it is sent with every file. */
function fieldsFor(cat:string,sub:string,tags:string,accessPolicy:AccessPolicy,downloadAllowed:boolean,meta:MetadataForm,roleIds:number[]):UploadFields{
    return {category_id:cat,subcategory_id:sub||null,tags,access_policy:accessPolicy,download_allowed:downloadAllowed,visible_role_ids:roleIds,metadata:meta as unknown as Record<string,string>};
}

export default function UploadPanel({active,categories,simulatedRole,visibilityRoles,masterData,metadataSettings,uploadSettings,onSuccess,onError}:Props){
    const t=useT();
    const [queue,setQueue]=useState<QueueItem[]>([]),[isDragging,setIsDragging]=useState(false),[isUploading,setIsUploading]=useState(false);
    const [selectedCat,setSelectedCat]=useState(''),[selectedSub,setSelectedSub]=useState(''),[tags,setTags]=useState('');
    const [quickOpen,setQuickOpen]=useState<Partial<Record<keyof MetadataForm,boolean>>>({}),[quickDraft,setQuickDraft]=useState<Partial<Record<keyof MetadataForm,string>>>({}),[quickBusy,setQuickBusy]=useState<keyof MetadataForm|null>(null);
    const [accessPolicy,setAccessPolicy]=useState<AccessPolicy>('public'),[downloadAllowed,setDownloadAllowed]=useState(true),[visibleRoleIds,setVisibleRoleIds]=useState<number[]>([]);
    const [meta,setMeta]=useState<MetadataForm>({year:String(new Date().getFullYear()),country_id:'',state_id:'',city_id:'',mandir_id:'',event_id:'',person_id:'',language_id:'',media_type_id:'',description:'',internal_remarks:'',source_type:'local',asset_status:'draft'});
    const inputRef=useRef<HTMLInputElement>(null), activeXhr=useRef<XMLHttpRequest|null>(null), activeUploadId=useRef<string|null>(null), cancelRequested=useRef(false);
    if(!active)return null;
    const canGrantRoles=['super-admin','department-admin'].includes(simulatedRole)&&visibilityRoles.length>0;
    const canUpload=simulatedRole!=='viewer', selectedCatObj=categories.find(c=>c.id===Number(selectedCat));
    const totalBytes=queue.reduce((s,q)=>s+q.size,0), uploadedBytes=queue.reduce((s,q)=>s+(q.status==='success'?q.size:(q.uploadedBytes||0)),0), progress=totalBytes?Math.round((uploadedBytes/totalBytes)*100):0;
    const chunkSize=Math.max(5,Math.min(20,uploadSettings.chunk_size_mb||10))*1024*1024, chunkThreshold=Math.max(5,uploadSettings.chunk_threshold_mb||50)*1024*1024, retryCount=Math.max(1,Math.min(10,uploadSettings.retry_count||3));
    const accept=(uploadSettings.allowed_extensions?.length?uploadSettings.allowed_extensions.map(e=>`.${e}`).join(','):DEFAULT_ACCEPT);
    const addFiles=(files:FileList)=>{const incoming=Array.from(files);if(uploadSettings.max_batch_count>0&&queue.length+incoming.length>uploadSettings.max_batch_count){onError(`Maximum ${uploadSettings.max_batch_count} files are allowed per batch.`);return;}setQueue(prev=>[...prev,...incoming.map(f=>{const type=getFileType(f.name);return{id:crypto.randomUUID(),file:f,name:f.name,type,size:f.size,status:'pending' as const,previewUrl:type==='image'?URL.createObjectURL(f):undefined,uploadedBytes:0};})])};
    const remove=(id:string)=>setQueue(prev=>{const x=prev.find(q=>q.id===id);if(x?.previewUrl)URL.revokeObjectURL(x.previewUrl);return prev.filter(q=>q.id!==id);});
    const cancelUpload=()=>{cancelRequested.current=true;activeXhr.current?.abort();if(activeUploadId.current){fetch(`/files/chunk/${activeUploadId.current}`,{method:'DELETE',headers:{'X-CSRF-TOKEN':getCsrf(),'Accept':'application/json'}}).catch(()=>{});activeUploadId.current=null;}};
    const startUpload=async()=>{
        if(!canUpload)return onError(t('upload.permission_denied')); if(!selectedCat)return onError(t('upload.choose_category')); const required=[...(metadataSettings.required_fields||[]),...(metadataSettings.person_required?['person_id']:[])]; const missing=required.filter(k=>!(meta as unknown as Record<string,string>)[k]); if(missing.length)return onError(`Required metadata missing: ${missing.join(', ')}`); const pending=queue.filter(q=>q.status==='pending'||q.status==='error'||q.status==='cancelled'); if(!pending.length)return onError(t('upload.no_files'));
        try{const r=await fetch('/files/upload-preflight',{method:'POST',headers:{'X-CSRF-TOKEN':getCsrf(),'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({files:pending.map(q=>({name:q.name,size:q.size}))})});const data=await r.json();if(!r.ok||!data.ok)throw new Error(data.message||data.files?.flatMap((x:any)=>x.errors||[]).join('; ')||'Upload policy check failed.');const dupes=(data.files||[]).filter((x:any)=>(x.possible_duplicates||[]).length);if(dupes.length&&!confirm(`${dupes.length} file(s) may already exist with the same name and size. Continue upload?`))return;}catch(e){return onError(e instanceof Error?e.message:'Upload preflight failed.');}
        setIsUploading(true);cancelRequested.current=false;let success=0;
        for(const item of pending){
            if(cancelRequested.current)break; const isChunked=item.size>=chunkThreshold;
            setQueue(prev=>prev.map(q=>q.id===item.id?{...q,status:'uploading',uploadedBytes:0,uploadProgress:0,isChunked}:q));
            try{
                const onBytes=(bytes:number)=>setQueue(prev=>prev.map(q=>q.id===item.id?{...q,uploadedBytes:bytes,uploadProgress:Math.min(100,Math.round(bytes/item.size*100))}:q));
                const fields=fieldsFor(selectedCat,selectedSub,tags,accessPolicy,downloadAllowed,meta,visibleRoleIds);
                if(isChunked)await uploadChunked(item,fields,onBytes,activeXhr,activeUploadId,chunkSize,retryCount);else await uploadSimple(item,fields,onBytes,activeXhr);
                setQueue(prev=>prev.map(q=>q.id===item.id?{...q,status:'success',uploadedBytes:item.size,uploadProgress:100}:q));success++;
            }catch(e){const cancelled=e instanceof DOMException&&e.name==='AbortError';setQueue(prev=>prev.map(q=>q.id===item.id?{...q,status:cancelled?'cancelled':'error'}:q));if(!cancelled)onError(`${item.name}: ${e instanceof Error?e.message:'Upload failed'}`);if(cancelled)break;}
        }
        setIsUploading(false);activeXhr.current=null;
        if(success){
            onSuccess(`Successfully uploaded ${success} file${success>1?'s':''}.`);
            router.reload({only:['files','stats','categories']});
            // Clear what actually landed and keep anything that failed, so the next
            // batch starts from an empty form instead of re-submitting stale values.
            let leftover=0;
            setQueue(prev=>{const done=prev.filter(q=>q.status==='success');done.forEach(q=>q.previewUrl&&URL.revokeObjectURL(q.previewUrl));const rest=prev.filter(q=>q.status!=='success');leftover=rest.length;return rest;});
            if(!leftover)resetCommonFields();
        }
    };
    /** Resets the metadata shared by a batch; the queue is cleared separately. */
    const resetCommonFields=()=>{
        setSelectedCat('');setSelectedSub('');setTags('');
        setAccessPolicy('public');setDownloadAllowed(true);setVisibleRoleIds([]);
        setMeta({year:String(new Date().getFullYear()),country_id:'',state_id:'',city_id:'',mandir_id:'',event_id:'',person_id:'',language_id:'',media_type_id:'',description:'',internal_remarks:'',source_type:'local',asset_status:'draft'});
    };
    const activeValues=(type:keyof MasterDataMap)=>masterData[type]?.filter(x=>x.is_active)||[];
    const countries=activeValues('country'), states=activeValues('state').filter(x=>!meta.country_id||x.parent_id===Number(meta.country_id)), cities=activeValues('city').filter(x=>!meta.state_id||x.parent_id===Number(meta.state_id)), mandirs=activeValues('mandir').filter(x=>!meta.city_id||x.parent_id===Number(meta.city_id));
    const setM=(key:keyof MetadataForm,value:string)=>setMeta(m=>({...m,[key]:value}));
    const labelReq=(field:string)=>metadataSettings.required_fields?.includes(field)||(field==='person_id'&&metadataSettings.person_required);
    /**
     * UAT C19: when a needed value is missing from a dropdown it can be added
     * right here. ADM-01 keeps master data admin-controlled, so the affordance
     * only appears for the roles allowed to administer controlled lists.
     */
    const canAddMaster=['super-admin','department-admin'].includes(simulatedRole);
    const quickAdd=async(type:MasterDataType,field:keyof MetadataForm,parentId:string|null)=>{
        const name=(quickDraft[field]||'').trim();
        if(!name)return onError(t('upload.quick_add_name'));
        if(['state','city','mandir'].includes(type)&&!parentId)return onError(t('upload.quick_add_parent'));
        setQuickBusy(field);
        try{
            const r=await fetch('/master-data/quick',{method:'POST',headers:{'X-CSRF-TOKEN':getCsrf(),'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({type,name,parent_id:parentId?Number(parentId):null})});
            const d=await r.json().catch(()=>({}));
            if(!r.ok)throw new Error(d.message||`HTTP ${r.status}`);
            onSuccess(d.message||`Added "${name}".`);
            setQuickDraft(x=>({...x,[field]:''}));
            setQuickOpen(x=>({...x,[field]:false}));
            // Pick the value straight away, then refresh the list behind it.
            if(d.value?.id)setM(field,String(d.value.id));
            router.reload({only:['masterData']});
        }catch(e){onError(e instanceof Error?e.message:t('upload.quick_add_failed'));}
        finally{setQuickBusy(null);}
    };

    const masterSelect=(label:string,field:keyof MetadataForm,values:any[],type?:MasterDataType,parentId?:string|null)=> <div className="form-group"><label className="form-label">{label}{labelReq(String(field))?' *':''}</label>
        <div className="master-quick-row">
            <select className="form-select" value={meta[field]} onChange={e=>setM(field,e.target.value)}><option value="">{t('upload.select_value')}</option>{values.map((x:any)=><option key={x.id} value={x.id}>{x.name}{x.code?` (${x.code})`:''}</option>)}</select>
            {canAddMaster&&type&&<button type="button" className="master-quick-btn" title={t('upload.add_new',{field:label})} aria-label={t('upload.add_new',{field:label})} onClick={()=>setQuickOpen(x=>({...x,[field]:!x[field]}))}><Plus size={14}/></button>}
        </div>
        {canAddMaster&&type&&quickOpen[field]&&<div className="master-quick-add">
            <input className="form-input" placeholder={t('upload.new_name',{field:label})} value={quickDraft[field]||''} onChange={e=>setQuickDraft(x=>({...x,[field]:e.target.value}))} onKeyDown={e=>e.key==='Enter'&&quickAdd(type,field,parentId??null)} autoFocus/>
            <button type="button" className="btn btn-primary" disabled={quickBusy===field} onClick={()=>quickAdd(type,field,parentId??null)}>{quickBusy===field?t('common.loading'):t('common.save')}</button>
            <button type="button" className="btn btn-secondary" onClick={()=>setQuickOpen(x=>({...x,[field]:false}))}>{t('common.cancel')}</button>
        </div>}
    </div>;
    const icon=(t:QueueItem['type'])=>t==='video'?<Video size={16}/>:t==='image'?<Image size={16}/>:t==='audio'?<Music size={16}/>:<FileText size={16}/>;
    return <section className="module-panel active" id="upload-panel">
        <div className="page-title-section"><div><h1 className="page-title"><UploadCloud size={28} style={{color:'var(--color-primary)'}}/>{t('upload.title')}</h1><p className="page-subtitle">{t('upload.subtitle')}</p></div></div>
        <div className="upload-container"><div>
            <div className={`dropzone${isDragging?' active':''}`} onDragOver={e=>{e.preventDefault();setIsDragging(true)}} onDragLeave={()=>setIsDragging(false)} onDrop={e=>{e.preventDefault();setIsDragging(false);if(e.dataTransfer.files.length)addFiles(e.dataTransfer.files)}} onClick={()=>!isUploading&&inputRef.current?.click()}>
                <div className="dropzone-icon"><Upload size={28}/></div><h3 className="dropzone-title">{t('upload.dropzone')}</h3><p className="dropzone-subtitle">{t('upload.dropzone_hint',{threshold:uploadSettings.chunk_threshold_mb,chunk:uploadSettings.chunk_size_mb,retries:uploadSettings.retry_count})}</p><input ref={inputRef} type="file" multiple accept={accept} style={{display:'none'}} onChange={e=>{if(e.target.files)addFiles(e.target.files);e.currentTarget.value=''}}/>
            </div>
            {!!queue.length&&<div className="upload-queue"><div className="queue-header"><div><h2 className="queue-title">Upload Queue ({queue.length})</h2><span className="queue-total-size">{formatBytes(totalBytes)}</span></div><div style={{display:'flex',gap:8}}>{isUploading?<button className="btn btn-secondary" onClick={cancelUpload}><XCircle size={14}/> Cancel Upload</button>:<button className="btn btn-secondary" onClick={()=>{queue.forEach(q=>q.previewUrl&&URL.revokeObjectURL(q.previewUrl));setQueue([])}}>{t('upload.clear_queue')}</button>}</div></div>
                {isUploading&&<div style={{marginBottom:16,background:'var(--bg-card)',border:'1px solid var(--border-color)',padding:16,borderRadius:'var(--radius-md)'}}><div style={{display:'flex',justifyContent:'space-between',fontWeight:600,fontSize:13,marginBottom:6}}><span>{t('upload.progress',{done:formatBytes(uploadedBytes),total:formatBytes(totalBytes)})}</span><span>{progress}%</span></div><div className="progress-bar-container"><div className="progress-bar-fill" style={{width:`${progress}%`}}/></div></div>}
                <div className="queue-list">{queue.map(item=><div key={item.id} className="queue-item" style={{flexWrap:'wrap'}}><div className="queue-item-left"><div className="queue-file-icon">{item.previewUrl?<img src={item.previewUrl} alt="" style={{width:36,height:36,objectFit:'cover',borderRadius:6}}/>:icon(item.type)}</div><div className="queue-file-details"><span className="queue-file-name">{item.name}</span><span className="queue-file-size">{item.status==='uploading'?`${formatBytes(item.uploadedBytes||0)} / ${formatBytes(item.size)}`:formatBytes(item.size)}{item.isChunked&&<b style={{marginLeft:6,fontSize:9,color:'var(--color-primary)'}}>CHUNKED</b>}</span></div></div><div className="queue-item-right"><span className={`queue-status-tag status-${item.status}`}>{item.status==='uploading'?`${item.uploadProgress||0}%`:item.status}</span>{!isUploading&&item.status!=='success'&&<button className="queue-remove-btn" onClick={()=>remove(item.id)}><Trash size={14}/></button>}</div>{item.status==='uploading'&&<div style={{width:'100%',paddingTop:6}}><div className="progress-bar-container"><div className="progress-bar-fill" style={{width:`${item.uploadProgress||0}%`}}/></div></div>}</div>)}</div>
            </div>}
        </div><div className="upload-sidebar-card"><h2 className="sidebar-card-title">{t('upload.batch_settings')}</h2><div className="form-group"><label className="form-label">{t('upload.category_required')}</label><select className="form-select" value={selectedCat} onChange={e=>{setSelectedCat(e.target.value);setSelectedSub('')}}><option value="">{t('upload.select_category')}</option>{categories.map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select></div><div className="form-group"><label className="form-label">{t('fields.subcategory')}</label><select className="form-select" value={selectedSub} onChange={e=>setSelectedSub(e.target.value)} disabled={!selectedCatObj}><option value="">{t('upload.select_subcategory')}</option>{selectedCatObj?.subcategories.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></div><div className="form-group"><label className="form-label">{t('upload.tags')}</label><input className="form-input" value={tags} onChange={e=>setTags(e.target.value)} placeholder={t('upload.tags_placeholder')}/></div><div style={{borderTop:'1px solid var(--border-color)',paddingTop:10,marginTop:10}}><div style={{fontWeight:800,fontSize:12,marginBottom:8}}>{t('upload.required_metadata')}</div><div className="form-group"><label className="form-label">{t('fields.year')}{labelReq('year')?' *':''}</label><input className="form-input" type="number" min={metadataSettings.years_min} max={metadataSettings.years_max} value={meta.year} onChange={e=>setM('year',e.target.value)}/></div>{masterSelect(t('fields.country'),'country_id',countries,'country')}{masterSelect(t('fields.state'),'state_id',states,'state',meta.country_id)}{masterSelect(t('upload.city_location'),'city_id',cities,'city',meta.state_id)}{masterSelect(t('fields.mandir'),'mandir_id',mandirs,'mandir',meta.city_id)}{masterSelect(t('fields.event'),'event_id',activeValues('event'),'event')}{masterSelect(t('fields.person'),'person_id',activeValues('person'),'person')}{masterSelect(t('fields.language'),'language_id',activeValues('language'),'language')}{masterSelect(t('fields.media_type'),'media_type_id',activeValues('media_type'),'media_type')}<div className="form-group"><label className="form-label">{t('access.description')}{labelReq('description')?' *':''}</label><textarea className="form-input" rows={3} value={meta.description} onChange={e=>setM('description',e.target.value)} placeholder={t('upload.description')}/></div><div className="form-group"><label className="form-label">{t('upload.internal_remarks')}</label><textarea className="form-input" rows={2} value={meta.internal_remarks} onChange={e=>setM('internal_remarks',e.target.value)} placeholder={t('upload.internal_remarks_placeholder')}/></div><div className="form-group"><label className="form-label">{t('fields.source')}</label><select className="form-select" value={meta.source_type} onChange={e=>setM('source_type',e.target.value)}><option value="local">{t('source.local')}</option><option value="nas">{t('source.nas')}</option><option value="google-drive">{t('source.google-drive')}</option><option value="youtube">{t('source.youtube')}</option></select></div><div className="form-group"><label className="form-label">{t('upload.initial_status')}</label><select className="form-select" value={meta.asset_status} onChange={e=>setM('asset_status',e.target.value)}><option value="draft">{t('upload.status_draft')}</option><option value="active">{t('upload.status_active')}</option></select><span style={{fontSize:10,color:'var(--text-muted)'}}>{t('upload.lifecycle_note')}</span></div></div><div className="form-group"><label className="form-label">{t('upload.access_policy_required')}</label><select className="form-select" value={accessPolicy} onChange={e=>setAccessPolicy(e.target.value as AccessPolicy)}><option value="public">{t('upload.policy_public')}</option><option value="protected">{t('upload.policy_protected')}</option><option value="private">{t('upload.policy_private')}</option></select><span style={{fontSize:10,color:'var(--text-muted)'}}>{accessPolicy==='public'?t('upload.policy_public_help'):accessPolicy==='protected'?t('upload.policy_protected_help'):t('upload.policy_private_help')}</span></div><label style={{display:'flex',gap:8,alignItems:'center',fontSize:12,color:'var(--text-secondary)',marginBottom:8}}><input type="checkbox" checked={downloadAllowed} onChange={e=>setDownloadAllowed(e.target.checked)}/> Allow downloads when the user is otherwise authorized</label>
{canGrantRoles&&<div className="form-group"><label className="form-label">{t('upload.visible_roles')}</label>
 <div className="role-grant-list">{visibilityRoles.map(r=><label key={r.id} className="role-grant-option"><input type="checkbox" checked={visibleRoleIds.includes(r.id)} onChange={e=>setVisibleRoleIds(ids=>e.target.checked?[...ids,r.id]:ids.filter(x=>x!==r.id))}/><span>{r.name}</span></label>)}</div>
 <span style={{fontSize:10,color:'var(--text-muted)'}}>{visibleRoleIds.length===0?t('upload.visible_roles_none'):`Anyone holding ${visibleRoleIds.length===1?'this role':'these roles'} can open these files, in any department, without an access request.`}</span></div>}<button className="btn btn-primary" style={{width:'100%',height:44}} disabled={!canUpload||!queue.some(q=>q.status!=='success')||!selectedCat||isUploading} onClick={startUpload}>{isUploading?t('upload.uploading',{percent:progress}):t('upload.start')}</button></div></div>
    </section>;
}
