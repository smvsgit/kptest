import { useMemo, useState } from 'react';
import { X, Layers3 } from 'lucide-react';
import type { AccessPolicy, Category, Department, MasterDataMap, UserRole } from '../../types';
import { useT } from '../../i18n';

interface Props {
    ids:number[]; categories:Category[]; departments:Department[]; masterData:MasterDataMap; role:UserRole;
    onClose:()=>void; onSuccess:(message:string)=>void; onError:(message:string)=>void;
}
const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')||'';

    const t=useT();
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
        if(!hasChanges)return onError(t('bulk.choose_one'));
        setBusy(true);
        const metadata:Record<string,unknown>={};
        if(year)metadata.year=Number(year); if(countryId)metadata.country_id=Number(countryId); if(stateId)metadata.state_id=Number(stateId); if(cityId)metadata.city_id=Number(cityId); if(mandirId)metadata.mandir_id=Number(mandirId);
        if(eventId)metadata.event_id=Number(eventId); if(personId)metadata.person_id=Number(personId); if(languageId)metadata.language_id=Number(languageId); if(mediaTypeId)metadata.media_type_id=Number(mediaTypeId); if(sourceType)metadata.source_type=sourceType;
        const body:Record<string,unknown>={ids}; if(Object.keys(metadata).length)body.metadata=metadata;
        if(tagsMode){body.tags_mode=tagsMode;body.tags=tags.split(',').map(x=>x.trim()).filter(Boolean)}
        if(categoryId)body.category_id=Number(categoryId); if(subcategoryId)body.subcategory_id=Number(subcategoryId); if(archiveMode)body.archive_mode=archiveMode; if(accessPolicy)body.access_policy=accessPolicy as AccessPolicy;
        if(downloadMode)body.download_allowed=downloadMode==='allow'; if(departmentId)body.department_id=Number(departmentId);
        try{const r=await fetch('/files/bulk-edit',{method:'PATCH',headers:{'X-CSRF-TOKEN':csrf(),'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(body)});const data=await r.json();if(!r.ok)throw new Error(data.message||Object.values(data.errors||{}).flat().join('; ')||t('bulk.failed'));onSuccess(data.message||t('bulk.updated',{count:ids.length}));onClose();}
        catch(e){onError(e instanceof Error?e.message:t('bulk.failed'))}finally{setBusy(false)}
    };
    const masterSelect=(label:string,value:string,setter:(v:string)=>void,items:any[])=><label className="form-group"><span className="form-label">{label}</span><select className="form-select" value={value} onChange={e=>setter(e.target.value)}><option value="">{t('bulk.no_change')}</option>{items.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label>;
    return <div className="modal-overlay active" onClick={e=>e.target===e.currentTarget&&onClose()}><div className="modal-container" style={{maxWidth:920,width:'94vw',display:'block'}}><div className="modal-info-pane" style={{width:'100%',maxHeight:'86vh',overflow:'auto'}}>
      <div className="modal-header"><div><h2 className="modal-title"><Layers3 size={20}/> {t('bulk.title',{count:ids.length})}</h2><p className="page-subtitle">{t('bulk.subtitle')}</p></div><button className="modal-close-btn" onClick={onClose}><X size={20}/></button></div>
      <div style={{display:'grid',gap:18,padding:'18px 0'}}>
       <div className="settings-card"><h3 className="settings-card-title">{t('bulk.move_tags')}</h3><div className="settings-grid"><label className="form-group"><span className="form-label">{t('fields.category')}</span><select className="form-select" value={categoryId} onChange={e=>{setCategoryId(e.target.value);setSubcategoryId('')}}><option value="">{t('bulk.no_change')}</option>{categories.map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select></label><label className="form-group"><span className="form-label">{t('fields.subcategory')}</span><select className="form-select" value={subcategoryId} onChange={e=>setSubcategoryId(e.target.value)} disabled={!categoryId}><option value="">{t('bulk.no_change')}</option>{category?.subcategories.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></label><label className="form-group"><span className="form-label">{t('bulk.tags_action')}</span><select className="form-select" value={tagsMode} onChange={e=>setTagsMode(e.target.value)}><option value="">{t('bulk.no_change')}</option><option value="add">{t('bulk.add_tags')}</option><option value="remove">{t('bulk.remove_tags')}</option><option value="replace">{t('bulk.replace_tags')}</option></select></label><label className="form-group"><span className="form-label">{t('upload.tags')}</span><input className="form-input" value={tags} onChange={e=>setTags(e.target.value)} placeholder={t('bulk.tags_placeholder')} disabled={!tagsMode}/></label></div></div>
       <div className="settings-card"><h3 className="settings-card-title">{t('bulk.structured')}</h3><div className="settings-grid"><label className="form-group"><span className="form-label">{t('fields.year')}</span><input className="form-input" type="number" value={year} onChange={e=>setYear(e.target.value)} placeholder={t('bulk.no_change')}/></label>{masterSelect(t('fields.country'),countryId,v=>{setCountryId(v);setStateId('');setCityId('');setMandirId('')},countries)}{masterSelect(t('fields.state'),stateId,v=>{setStateId(v);setCityId('');setMandirId('')},states)}{masterSelect(t('fields.city'),cityId,v=>{setCityId(v);setMandirId('')},cities)}{masterSelect(t('fields.mandir'),mandirId,setMandirId,mandirs)}{masterSelect(t('fields.event'),eventId,setEventId,active('event'))}{masterSelect(t('fields.person'),personId,setPersonId,active('person'))}{masterSelect(t('fields.language'),languageId,setLanguageId,active('language'))}{masterSelect(t('fields.media_type'),mediaTypeId,setMediaTypeId,active('media_type'))}<label className="form-group"><span className="form-label">{t('fields.source')}</span><select className="form-select" value={sourceType} onChange={e=>setSourceType(e.target.value)}><option value="">{t('bulk.no_change')}</option><option value="local">{t('source.local')}</option><option value="nas">{t('source.nas')}</option><option value="google-drive">{t('source.google-drive')}</option><option value="youtube">{t('source.youtube')}</option></select></label></div></div>
       <div className="settings-card"><h3 className="settings-card-title">{t('bulk.access_archive')}</h3><div className="settings-grid"><label className="form-group"><span className="form-label">{t('inspector.access_policy')}</span><select className="form-select" value={accessPolicy} onChange={e=>setAccessPolicy(e.target.value)}><option value="">{t('bulk.no_change')}</option><option value="public">{t('policy.public')}</option><option value="protected">{t('policy.protected')}</option><option value="private">{t('policy.private')}</option></select></label><label className="form-group"><span className="form-label">{t('bulk.download_permission')}</span><select className="form-select" value={downloadMode} onChange={e=>setDownloadMode(e.target.value)}><option value="">{t('bulk.no_change')}</option><option value="allow">{t('bulk.allow')}</option><option value="deny">{t('bulk.deny')}</option></select></label>{role!=='department-operator'&&<label className="form-group"><span className="form-label">{t('bulk.archive')}</span><select className="form-select" value={archiveMode} onChange={e=>setArchiveMode(e.target.value)}><option value="">{t('bulk.no_change')}</option><option value="archive">{t('bulk.archive')}</option><option value="unarchive">{t('bulk.unarchive')}</option></select></label>}{role==='super-admin'&&<label className="form-group"><span className="form-label">{t('bulk.transfer_department')}</span><select className="form-select" value={departmentId} onChange={e=>setDepartmentId(e.target.value)}><option value="">{t('bulk.no_change')}</option>{departments.filter(d=>d.is_active&&!d.is_system).map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></label>}</div></div>
      </div>
      <div style={{display:'flex',justifyContent:'flex-end',gap:10,paddingTop:10}}><button className="btn btn-secondary" onClick={onClose} disabled={busy}>{t('common.cancel')}</button><button className="btn btn-primary" onClick={submit} disabled={busy||!hasChanges}>{busy?t('bulk.applying'):t('bulk.apply')}</button></div>
    </div></div></div>;
}
