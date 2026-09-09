import { useMemo, useState } from 'react';
import { X, Layers3 } from 'lucide-react';
import type { AccessPolicy, Category, Department, MasterDataMap, UserRole } from '../../types';

interface Props {
    ids:number[]; categories:Category[]; departments:Department[]; masterData:MasterDataMap; role:UserRole;
    onClose:()=>void; onSuccess:(message:string)=>void; onError:(message:string)=>void;
}
const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')||'';

export default function BulkEditModal({ids,categories,departments,masterData,role,onClose,onSuccess,onError}:Props){
    const [busy,setBusy]=useState(false);
    const [categoryId,setCategoryId]=useState(''),[subcategoryId,setSubcategoryId]=useState('');
    const [tagsMode,setTagsMode]=useState(''),[tags,setTags]=useState('');
    const [archiveMode,setArchiveMode]=useState(''),[accessPolicy,setAccessPolicy]=useState(''),[downloadMode,setDownloadMode]=useState('');
    const [departmentId,setDepartmentId]=useState('');
    const [year,setYear]=useState(''),[countryId,setCountryId]=useState(''),[stateId,setStateId]=useState(''),[cityId,setCityId]=useState(''),[mandirId,setMandirId]=useState('');
    const [eventId,setEventId]=useState(''),[personId,setPersonId]=useState(''),[languageId,setLanguageId]=useState(''),[mediaTypeId,setMediaTypeId]=useState('');
    const [sourceType,setSourceType]=useState('');
    const category=categories.find(x=>x.id===Number(categoryId));
    const active=(key:keyof MasterDataMap)=>masterData[key]?.filter(x=>x.is_active)||[];
    const countries=active('country');
    const states=active('state').filter(x=>!countryId||x.parent_id===Number(countryId));
    const cities=active('city').filter(x=>!stateId||x.parent_id===Number(stateId));
    const mandirs=active('mandir').filter(x=>!cityId||x.parent_id===Number(cityId));
    const hasChanges=useMemo(()=>Boolean(categoryId||subcategoryId||tagsMode||archiveMode||accessPolicy||downloadMode||departmentId||year||countryId||stateId||cityId||mandirId||eventId||personId||languageId||mediaTypeId||sourceType),[categoryId,subcategoryId,tagsMode,archiveMode,accessPolicy,downloadMode,departmentId,year,countryId,stateId,cityId,mandirId,eventId,personId,languageId,mediaTypeId,sourceType]);

    const submit=async()=>{
        if(!hasChanges)return onError('Choose at least one bulk change.');
        setBusy(true);
        const metadata:Record<string,unknown>={};
        if(year)metadata.year=Number(year); if(countryId)metadata.country_id=Number(countryId); if(stateId)metadata.state_id=Number(stateId); if(cityId)metadata.city_id=Number(cityId); if(mandirId)metadata.mandir_id=Number(mandirId);
        if(eventId)metadata.event_id=Number(eventId); if(personId)metadata.person_id=Number(personId); if(languageId)metadata.language_id=Number(languageId); if(mediaTypeId)metadata.media_type_id=Number(mediaTypeId); if(sourceType)metadata.source_type=sourceType;
        const body:Record<string,unknown>={ids}; if(Object.keys(metadata).length)body.metadata=metadata;
        if(tagsMode){body.tags_mode=tagsMode;body.tags=tags.split(',').map(x=>x.trim()).filter(Boolean)}
        if(categoryId)body.category_id=Number(categoryId); if(subcategoryId)body.subcategory_id=Number(subcategoryId); if(archiveMode)body.archive_mode=archiveMode; if(accessPolicy)body.access_policy=accessPolicy as AccessPolicy;
        if(downloadMode)body.download_allowed=downloadMode==='allow'; if(departmentId)body.department_id=Number(departmentId);
        try{const r=await fetch('/files/bulk-edit',{method:'PATCH',headers:{'X-CSRF-TOKEN':csrf(),'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)});const data=await r.json();if(!r.ok)throw new Error(data.message||Object.values(data.errors||{}).flat().join('; ')||'Bulk edit failed.');onSuccess(data.message||`${ids.length} files updated.`);onClose();}
        catch(e){onError(e instanceof Error?e.message:'Bulk edit failed.')}finally{setBusy(false)}
    };
    const masterSelect=(label:string,value:string,setter:(v:string)=>void,items:any[])=><label className="form-group"><span className="form-label">{label}</span><select className="form-select" value={value} onChange={e=>setter(e.target.value)}><option value="">No change</option>{items.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label>;
    return <div className="modal-overlay active" onClick={e=>e.target===e.currentTarget&&onClose()}><div className="modal-container" style={{maxWidth:920,width:'94vw',display:'block'}}><div className="modal-info-pane" style={{width:'100%',maxHeight:'86vh',overflow:'auto'}}>
      <div className="modal-header"><div><h2 className="modal-title"><Layers3 size={20}/> Bulk Edit {ids.length} Files</h2><p className="page-subtitle">Only fields selected below are changed. Department ownership rules are enforced on the server.</p></div><button className="modal-close-btn" onClick={onClose}><X size={20}/></button></div>
      <div style={{display:'grid',gap:18,padding:'18px 0'}}>
       <div className="settings-card"><h3 className="settings-card-title">Move & Tags</h3><div className="settings-grid"><label className="form-group"><span className="form-label">Category</span><select className="form-select" value={categoryId} onChange={e=>{setCategoryId(e.target.value);setSubcategoryId('')}}><option value="">No change</option>{categories.map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select></label><label className="form-group"><span className="form-label">Subcategory</span><select className="form-select" value={subcategoryId} onChange={e=>setSubcategoryId(e.target.value)} disabled={!categoryId}><option value="">No change</option>{category?.subcategories.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></label><label className="form-group"><span className="form-label">Tags action</span><select className="form-select" value={tagsMode} onChange={e=>setTagsMode(e.target.value)}><option value="">No change</option><option value="add">Add tags</option><option value="remove">Remove tags</option><option value="replace">Replace all tags</option></select></label><label className="form-group"><span className="form-label">Tags</span><input className="form-input" value={tags} onChange={e=>setTags(e.target.value)} placeholder="event, person, approved" disabled={!tagsMode}/></label></div></div>
       <div className="settings-card"><h3 className="settings-card-title">Structured Metadata</h3><div className="settings-grid"><label className="form-group"><span className="form-label">Year</span><input className="form-input" type="number" value={year} onChange={e=>setYear(e.target.value)} placeholder="No change"/></label>{masterSelect('Country',countryId,v=>{setCountryId(v);setStateId('');setCityId('');setMandirId('')},countries)}{masterSelect('State',stateId,v=>{setStateId(v);setCityId('');setMandirId('')},states)}{masterSelect('City',cityId,v=>{setCityId(v);setMandirId('')},cities)}{masterSelect('Mandir',mandirId,setMandirId,mandirs)}{masterSelect('Event / Prasang',eventId,setEventId,active('event'))}{masterSelect('Guruji / Person',personId,setPersonId,active('person'))}{masterSelect('Language',languageId,setLanguageId,active('language'))}{masterSelect('Media Type',mediaTypeId,setMediaTypeId,active('media_type'))}<label className="form-group"><span className="form-label">Source</span><select className="form-select" value={sourceType} onChange={e=>setSourceType(e.target.value)}><option value="">No change</option><option value="local">Local</option><option value="nas">NAS</option><option value="google-drive">Google Drive</option><option value="youtube">YouTube</option></select></label></div></div>
       <div className="settings-card"><h3 className="settings-card-title">Access & Archive</h3><div className="settings-grid"><label className="form-group"><span className="form-label">Access Policy</span><select className="form-select" value={accessPolicy} onChange={e=>setAccessPolicy(e.target.value)}><option value="">No change</option><option value="public">Public</option><option value="protected">Protected</option><option value="private">Private</option></select></label><label className="form-group"><span className="form-label">Download Permission</span><select className="form-select" value={downloadMode} onChange={e=>setDownloadMode(e.target.value)}><option value="">No change</option><option value="allow">Allow</option><option value="deny">Disable</option></select></label>{role!=='department-operator'&&<label className="form-group"><span className="form-label">Archive</span><select className="form-select" value={archiveMode} onChange={e=>setArchiveMode(e.target.value)}><option value="">No change</option><option value="archive">Archive</option><option value="unarchive">Unarchive</option></select></label>}{role==='super-admin'&&<label className="form-group"><span className="form-label">Transfer Owner Department</span><select className="form-select" value={departmentId} onChange={e=>setDepartmentId(e.target.value)}><option value="">No change</option>{departments.filter(d=>d.is_active&&!d.is_system).map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></label>}</div></div>
      </div>
      <div style={{display:'flex',justifyContent:'flex-end',gap:10,paddingTop:10}}><button className="btn btn-secondary" onClick={onClose} disabled={busy}>Cancel</button><button className="btn btn-primary" onClick={submit} disabled={busy||!hasChanges}>{busy?'Applying…':'Apply Bulk Changes'}</button></div>
    </div></div></div>;
}
