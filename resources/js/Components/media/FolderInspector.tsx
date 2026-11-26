import { useMemo, useState } from 'react';
import { X, Tag, Globe2, Loader2, Sparkles, Plus } from 'lucide-react';
import type { MetadataFieldDefinition, AiTaggingState, AccessPolicy, Category, MasterDataMap, MasterDataType, MasterDataValue, MetadataSettings, VisibilityRole } from '../../types';
import { getCsrf } from '../../upload';
import { useTx } from '../../i18n';
import type { FolderFile, FolderNode } from '../panels/FoldersPanel';

interface Props {
    mode:'metadata'|'publish';
    folder:FolderNode|null;
    selectedFiles:FolderFile[];
    categories:Category[];
    masterData:MasterDataMap;
    metadataSettings:MetadataSettings;
    visibilityRoles:VisibilityRole[];
    canPublish:boolean;
    /** Whether on-demand tag suggestions exist and this role may spend on them. (F08.) */
    aiTagging:AiTaggingState;
    metadataFields:MetadataFieldDefinition[];
    onClose:()=>void;
    onDone:(message:string)=>void;
    onError:(message:string)=>void;
}

type Draft=Record<string,string>;

const METADATA_FIELDS=['category_id','subcategory_id','year','event_id','person_id','country_id','state_id','city_id','mandir_id','language_id','media_type_id','description'] as const;

const byParent=(items:MasterDataValue[]|undefined,parent:string)=>
    (items||[]).filter(x=>x.is_active&&(!parent||x.parent_id===Number(parent)));

async function post(url:string,body:unknown,method='POST'){
    const r=await fetch(url,{
        method,
        headers:{'X-CSRF-TOKEN':getCsrf(),'Accept':'application/json','Content-Type':'application/json'},
        body:JSON.stringify(body),
    });
    const data=await r.json().catch(()=>({}));
    if(!r.ok)throw new Error(data.message||`HTTP ${r.status}`);
    return data;
}

/**
 * The panel that replaces the upload form's metadata questions.
 *
 * It describes whatever is chosen — a selection of assets, or the open folder
 * and everything under it — and in publish mode sets the policy for a folder.
 * Only the fields actually filled in are sent, so a second pass can add one
 * more value without clearing what an earlier pass wrote.
 */
export default function FolderInspector(p:Props){
    const {t,tc}=useTx();
    const [draft,setDraft]=useState<Draft>({});
    const [custom,setCustom]=useState<Record<string,string>>({});
    const [tags,setTags]=useState('');
    // On-demand tag suggestions. They live here until the person adds one;
    // nothing reaches the asset on its own. (Review F08.)
    const [suggested,setSuggested]=useState<string[]>([]);
    const [suggesting,setSuggesting]=useState(false);
    const [suggestNote,setSuggestNote]=useState('');

    // The administrator's own fields, narrowed to the types actually selected.
    // A field that applies to images only is not asked for a document. (F09b.)
    const selectedTypes=Array.from(new Set(p.selectedFiles.map(f=>f.type)));
    const customFields=p.metadataFields.filter(field=>{
        if(!field.is_active)return false;
        const applies=field.applies_to||[];
        if(!applies.length)return true;
        if(!selectedTypes.length)return true;
        return selectedTypes.some(type=>applies.includes(type));
    });
    const tagList=()=>tags.split(',').map(x=>x.trim()).filter(Boolean);
    const hasTag=(tag:string)=>tagList().some(x=>x.toLowerCase()===tag.toLowerCase());
    const toggleTag=(tag:string)=>{
        const list=tagList();
        const next=hasTag(tag)?list.filter(x=>x.toLowerCase()!==tag.toLowerCase()):[...list,tag];
        setTags(next.join(', '));
    };
    const addAllSuggested=()=>{
        const list=tagList();
        const next=[...list];
        for(const tag of suggested) if(!next.some(x=>x.toLowerCase()===tag.toLowerCase())) next.push(tag);
        setTags(next.join(', '));
    };
    const askForTags=async()=>{
        const only=p.selectedFiles.length===1?p.selectedFiles[0]:null;
        if(!only)return setSuggestNote(t('inspector.ai_tags_one_image'));
        setSuggesting(true);setSuggestNote('');
        try{
            const data=await post(`/files/${only.id}/ai-tags`,{});
            setSuggested(data.tags||[]);
            if(!(data.tags||[]).length)setSuggestNote(data.message||'');
        }catch(e){setSuggestNote(e instanceof Error?e.message:'')}
        finally{setSuggesting(false)}
    };
    const [tagsMode,setTagsMode]=useState<'add'|'replace'>('add');
    const [recursive,setRecursive]=useState(true);
    const [onlyEmpty,setOnlyEmpty]=useState(false);
    const [policy,setPolicy]=useState<AccessPolicy|''>('');
    const [downloadAllowed,setDownloadAllowed]=useState<''|'1'|'0'>('');
    const [assetStatus,setAssetStatus]=useState<''|'draft'|'active'>('');
    const [roleIds,setRoleIds]=useState<number[]>([]);
    const [busy,setBusy]=useState(false);
    const [blocked,setBlocked]=useState<{id:number;name:string;missing:string[]}[]>([]);

    const hasSelection=p.selectedFiles.length>0;
    // A selection wins over the folder, so "Describe" after picking files does
    // what it says rather than quietly touching every sibling.
    const target:'selection'|'folder'=hasSelection?'selection':'folder';

    const set=(field:string,value:string)=>setDraft(d=>{
        const next={...d,[field]:value};
        // Clearing a parent clears what hangs off it, as the server requires.
        if(field==='country_id'){delete next.state_id;delete next.city_id;delete next.mandir_id;}
        if(field==='state_id'){delete next.city_id;delete next.mandir_id;}
        if(field==='city_id')delete next.mandir_id;
        if(field==='category_id')delete next.subcategory_id;
        if(!value)delete next[field];
        return next;
    });

    const subcategories=useMemo(()=>{
        const cat=p.categories.find(c=>String(c.id)===draft.category_id);
        return cat?.subcategories||[];
    },[p.categories,draft.category_id]);

    const years=useMemo(()=>{
        const{years_min:min,years_max:max}=p.metadataSettings;
        return Array.from({length:Math.max(0,max-min+1)},(_,i)=>max-i);
    },[p.metadataSettings]);

    const metadataPayload=useMemo(()=>{
        const out:Record<string,string|number>={};
        METADATA_FIELDS.forEach(field=>{if(draft[field])out[field]=field==='year'?Number(draft[field]):draft[field];});
        return out;
    },[draft]);

    const applyMetadata=async()=>{
        const customValues=()=>Object.fromEntries(Object.entries(custom).filter(([,v])=>String(v).trim()!==''));
        if(!Object.keys(metadataPayload).length&&!tags.trim()&&!Object.keys(customValues()).length){
            return p.onError(t('inspector.nothing_filled'));
        }
        setBusy(true);
        try{
            const tagList=tags.split(',').map(t=>t.trim()).filter(Boolean);
            if(target==='selection'){
                const data=await post('/files/bulk-edit',{
                    ids:p.selectedFiles.map(f=>f.id),
                    metadata:metadataPayload,
                    ...(draft.category_id?{category_id:Number(draft.category_id)}:{}),
                    ...(draft.subcategory_id?{subcategory_id:Number(draft.subcategory_id)}:{}),
                    ...(Object.keys(customValues()).length?{custom_metadata:customValues()}:{}),
                    ...(tagList.length?{tags:tagList,tags_mode:tagsMode}:{}),
                },'PATCH');
                p.onDone(data.message);
            }else{
                if(!p.folder)return p.onError(t('inspector.open_folder_first'));
                const data=await post(`/folders/${p.folder.id}/metadata`,{
                    metadata:metadataPayload,
                    ...(Object.keys(customValues()).length?{custom_metadata:customValues()}:{}),
                    ...(tagList.length?{tags:tagList,tags_mode:tagsMode}:{}),
                    include_subfolders:recursive,
                    only_empty:onlyEmpty,
                });
                p.onDone(data.message);
            }
        }catch(e){p.onError(e instanceof Error?e.message:t('inspector.apply_failed'));}
        finally{setBusy(false);}
    };

    const publish=async()=>{
        if(!p.folder)return p.onError(t('inspector.open_folder_to_publish'));
        if(!policy&&!assetStatus&&downloadAllowed===''&&!roleIds.length){
            return p.onError(t('inspector.choose_something'));
        }
        setBusy(true);
        setBlocked([]);
        try{
            const data=await post(`/folders/${p.folder.id}/publish`,{
                ...(policy?{access_policy:policy}:{}),
                ...(downloadAllowed!==''?{download_allowed:downloadAllowed==='1'}:{}),
                ...(assetStatus?{asset_status:assetStatus}:{}),
                ...(roleIds.length?{visible_role_ids:roleIds}:{}),
                include_subfolders:recursive,
            });
            if(data.blocked?.length){
                // Keep the panel open so the list of what is missing can be read.
                setBlocked(data.blocked);
                p.onError(data.message);
                return;
            }
            p.onDone(data.message);
        }catch(e){p.onError(e instanceof Error?e.message:t('inspector.publish_failed'));}
        finally{setBusy(false);}
    };

    const masterSelect=(field:string,label:string,type:MasterDataType,parentField?:string)=>{
        const parent=parentField?draft[parentField]||'':'';
        const options=byParent(p.masterData[type],parentField?parent:'');
        const disabled=Boolean(parentField&&!parent);
        return (
            <div className="form-group" key={field}>
                <label className="form-label" htmlFor={`fi-${field}`}>{label}</label>
                <select
                    id={`fi-${field}`}
                    className="form-select"
                    value={draft[field]||''}
                    disabled={disabled}
                    onChange={e=>set(field,e.target.value)}
                >
                    <option value="">{disabled?t('inspector.choose_parent_first',{field:parentField?.replace('_id','')||''}):t('inspector.leave_unchanged')}</option>
                    {options.map(o=><option key={o.id} value={o.id}>{o.name}</option>)}
                </select>
            </div>
        );
    };

    // Publishing always acts on the open folder; only describing can narrow to
    // a selection. Saying "1 selected asset" on the publish panel claimed a
    // scope the action does not have.
    const folderScope=p.folder
        ? (recursive?t('inspector.scope_folder_deep',{name:p.folder.name}):t('inspector.scope_folder_only',{name:p.folder.name}))
        : t('inspector.scope_root');
    const scopeLine=p.mode==='publish'
        ? folderScope
        : target==='selection'
            ? tc('inspector.scope_selection',p.selectedFiles.length)
            : folderScope;

    return (
        <aside className="exp-inspector">
            <div className="exp-inspector-head">
                <div>
                    <b>{p.mode==='publish'?t('inspector.publish_title'):t('inspector.describe_title')}</b>
                    <div className="setting-help">{t('inspector.applies_to',{scope:scopeLine})}</div>
                </div>
                <button className="icon-btn" onClick={p.onClose} title={t('common.close')}><X size={15}/></button>
            </div>

            <div className="exp-inspector-body">
                {p.mode==='metadata'?<>
                    <div className="form-group">
                        <label className="form-label" htmlFor="fi-category">{t('fields.category')}</label>
                        <select id="fi-category" className="form-select" value={draft.category_id||''} onChange={e=>set('category_id',e.target.value)}>
                            <option value="">{t('inspector.leave_unchanged')}</option>
                            {p.categories.map(c=><option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </div>
                    <div className="form-group">
                        <label className="form-label" htmlFor="fi-subcategory">{t('fields.subcategory')}</label>
                        <select id="fi-subcategory" className="form-select" value={draft.subcategory_id||''} disabled={!draft.category_id} onChange={e=>set('subcategory_id',e.target.value)}>
                            <option value="">{draft.category_id?t('inspector.leave_unchanged'):t('inspector.choose_category_first')}</option>
                            {subcategories.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                    </div>
                    <div className="form-group">
                        <label className="form-label" htmlFor="fi-year">{t('fields.year')}</label>
                        <select id="fi-year" className="form-select" value={draft.year||''} onChange={e=>set('year',e.target.value)}>
                            <option value="">{t('inspector.leave_unchanged')}</option>
                            {years.map(y=><option key={y} value={y}>{y}</option>)}
                        </select>
                    </div>
                    {masterSelect('event_id',t('fields.event'),'event')}
                    {masterSelect('person_id',t('fields.person'),'person')}
                    {masterSelect('country_id',t('fields.country'),'country')}
                    {masterSelect('state_id',t('fields.state'),'state','country_id')}
                    {masterSelect('city_id',t('fields.city'),'city','state_id')}
                    {masterSelect('mandir_id',t('fields.mandir'),'mandir','city_id')}
                    {masterSelect('language_id',t('fields.language'),'language')}
                    {masterSelect('media_type_id',t('fields.media_type'),'media_type')}

                    <div className="form-group">
                        <label className="form-label" htmlFor="fi-description">{t('access.description')}</label>
                        <textarea id="fi-description" className="form-input" rows={3} value={draft.description||''} onChange={e=>set('description',e.target.value)} placeholder={t('inspector.leave_unchanged')}/>
                    </div>

                    {customFields.length>0&&<div className="form-group">
                        <span className="form-label">{t('inspector.custom_fields')}</span>
                        {customFields.map(field=><div className="form-group" key={field.key} style={{marginTop:8}}>
                            <label className="form-label" htmlFor={'fi-c-'+field.key}>
                                {field.label}{field.is_required?' *':''}{field.is_locked?' 🔒':''}
                            </label>
                            {field.input_type==='textarea'
                                ? <textarea id={'fi-c-'+field.key} className="form-input" rows={2} style={{resize:'vertical'}}
                                    value={custom[field.key]??''} onChange={e=>setCustom({...custom,[field.key]:e.target.value})}/>
                                : field.input_type==='select'
                                ? <select id={'fi-c-'+field.key} className="form-select" value={custom[field.key]??''}
                                    onChange={e=>setCustom({...custom,[field.key]:e.target.value})}>
                                    <option value="">{t('inspector.leave_unchanged')}</option>
                                    {(field.options||[]).map(option=><option key={option} value={option}>{option}</option>)}
                                  </select>
                                : field.input_type==='checkbox'
                                ? <select id={'fi-c-'+field.key} className="form-select" value={custom[field.key]??''}
                                    onChange={e=>setCustom({...custom,[field.key]:e.target.value})}>
                                    <option value="">{t('inspector.leave_unchanged')}</option>
                                    <option value="1">{t('inspector.yes')}</option>
                                    <option value="0">{t('inspector.no')}</option>
                                  </select>
                                : <input id={'fi-c-'+field.key} className="form-input"
                                    type={field.input_type==='number'?'number':field.input_type==='date'?'date':'text'}
                                    value={custom[field.key]??''} onChange={e=>setCustom({...custom,[field.key]:e.target.value})}/>}
                            {(field.help||field.is_locked)&&<div className="setting-help">
                                {field.help}{field.help&&field.is_locked?' · ':''}{field.is_locked?t('inspector.locked_hint'):''}
                            </div>}
                        </div>)}
                    </div>}

                    <div className="form-group">
                        <label className="form-label" htmlFor="fi-tags">{t('upload.tags')}</label>
                        <input id="fi-tags" className="form-input" value={tags} onChange={e=>setTags(e.target.value)} placeholder={t('inspector.tags_placeholder')}/>
                        <label className="check-row" style={{marginTop:6}}>
                            <input type="checkbox" checked={tagsMode==='replace'} onChange={e=>setTagsMode(e.target.checked?'replace':'add')}/>
                            {t('inspector.replace_tags')}
                        </label>
                        {/* Suggestions are asked for one image at a time and are
                            only words on screen until they are added. (F08.) */}
                        {p.aiTagging.available&&p.aiTagging.permitted&&<div className="ai-tag-box">
                            <button type="button" className="btn btn-secondary" disabled={suggesting} onClick={askForTags} title={t('inspector.ai_tags_hint')}>
                                {suggesting?<Loader2 size={14} className="spin"/>:<Sparkles size={14}/>} {suggesting?t('inspector.ai_tags_working'):t('inspector.ai_tags')}
                            </button>
                            {suggestNote&&<div className="setting-help" style={{marginTop:6}}>{suggestNote}</div>}
                            {suggested.length>0&&<>
                                <div className="setting-help" style={{marginTop:8}}>{t('inspector.ai_tags_suggestions')}</div>
                                <div className="ai-tag-chips">
                                    {suggested.map(tag=><button type="button" key={tag}
                                        className={`ai-tag-chip${hasTag(tag)?' on':''}`} onClick={()=>toggleTag(tag)}>
                                        {hasTag(tag)?'✓':<Plus size={12}/>} {tag}</button>)}
                                </div>
                                <div style={{display:'flex',gap:7,marginTop:7}}>
                                    <button type="button" className="btn btn-secondary" onClick={addAllSuggested}>{t('inspector.ai_tags_add_all')}</button>
                                    <button type="button" className="btn btn-secondary" onClick={()=>{setSuggested([]);setSuggestNote('')}}>{t('inspector.ai_tags_dismiss')}</button>
                                </div>
                            </>}
                        </div>}
                    </div>

                    {target==='folder'&&<>
                        <label className="check-row">
                            <input type="checkbox" checked={recursive} onChange={e=>setRecursive(e.target.checked)}/>
                            {t('inspector.include_subfolders')}
                        </label>
                        <label className="check-row">
                            <input type="checkbox" checked={onlyEmpty} onChange={e=>setOnlyEmpty(e.target.checked)}/>
                            {t('inspector.only_empty')}
                        </label>
                    </>}
                </>:<>
                    <div className="form-group">
                        <label className="form-label" htmlFor="fi-policy">{t('inspector.access_policy')}</label>
                        <select id="fi-policy" className="form-select" value={policy} onChange={e=>setPolicy(e.target.value as AccessPolicy|'')}>
                            <option value="">{t('inspector.leave_unchanged')}</option>
                            <option value="public">{t('policy.public')}</option>
                            <option value="protected">{t('policy.protected')}</option>
                            <option value="private">{t('policy.private')}</option>
                        </select>
                    </div>
                    <div className="form-group">
                        <label className="form-label" htmlFor="fi-download">{t('inspector.downloadable')}</label>
                        <select id="fi-download" className="form-select" value={downloadAllowed} onChange={e=>setDownloadAllowed(e.target.value as ''|'1'|'0')}>
                            <option value="">{t('inspector.leave_unchanged')}</option>
                            <option value="1">{t('inspector.yes')}</option>
                            <option value="0">{t('inspector.no')}</option>
                        </select>
                    </div>
                    <div className="form-group">
                        <label className="form-label" htmlFor="fi-status">{t('fields.status')}</label>
                        <select id="fi-status" className="form-select" value={assetStatus} onChange={e=>setAssetStatus(e.target.value as ''|'draft'|'active')}>
                            <option value="">{t('inspector.leave_unchanged')}</option>
                            <option value="active">{t('inspector.status_active')}</option>
                            <option value="draft">{t('inspector.status_draft')}</option>
                        </select>
                        <div className="setting-help">{t('inspector.status_help')}</div>
                    </div>
                    <div className="form-group">
                        <label className="form-label" htmlFor="fi-roles">{t('inspector.visible_roles')}</label>
                        <select
                            id="fi-roles"
                            className="form-select"
                            multiple
                            size={Math.min(5,Math.max(3,p.visibilityRoles.length))}
                            value={roleIds.map(String)}
                            onChange={e=>setRoleIds(Array.from(e.target.selectedOptions).map(o=>Number(o.value)))}
                        >
                            {p.visibilityRoles.map(r=><option key={r.id} value={r.id}>{r.name}</option>)}
                        </select>
                        <div className="setting-help">
                            {roleIds.length
                                ? t('inspector.visible_roles_some')
                                : t('inspector.visible_roles_none')}
                        </div>
                    </div>
                    <label className="check-row">
                        <input type="checkbox" checked={recursive} onChange={e=>setRecursive(e.target.checked)}/>
                        {t('inspector.include_subfolders')}
                    </label>

                    {blocked.length>0&&(
                        <div className="admin-guide" style={{borderColor:'var(--color-warning)'}}>
                            <b>{tc('inspector.blocked_title',blocked.length)}</b>
                            <ul style={{margin:'6px 0 0 18px'}}>
                                {blocked.slice(0,8).map(b=><li key={b.id}>{t('inspector.blocked_missing',{name:b.name,fields:b.missing.join(', ')})}</li>)}
                            </ul>
                            {blocked.length>8&&<div className="setting-help">{t('inspector.blocked_more',{count:blocked.length-8})}</div>}
                        </div>
                    )}
                </>}
            </div>

            <div className="exp-inspector-foot">
                {p.mode==='metadata'?(
                    <button className="btn btn-primary" disabled={busy} onClick={applyMetadata}>
                        {busy?<Loader2 size={14} className="spin"/>:<Tag size={14}/>} {t('inspector.apply')}
                    </button>
                ):(
                    <button className="btn btn-primary" disabled={busy||!p.canPublish} onClick={publish}>
                        {busy?<Loader2 size={14} className="spin"/>:<Globe2 size={14}/>} {t('inspector.publish')}
                    </button>
                )}
                <button className="btn btn-secondary" onClick={p.onClose}>{t('common.cancel')}</button>
            </div>
        </aside>
    );
}
