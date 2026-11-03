import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    ArrowLeft, ArrowRight, ArrowUp, RotateCw, Search, FolderPlus, UploadCloud, Pencil, Trash2,
    FolderInput, Tag, Globe2, ChevronRight, ChevronDown, Folder, FolderOpen, Image as ImageIcon,
    Video, Music, FileText, LayoutGrid, List, Columns3, X, Loader2,
} from 'lucide-react';
import type { AccessPolicy, Category, MasterDataMap, MetadataSettings, UploadSettings, User, VisibilityRole } from '../../types';
import { formatBytes } from '../../format';
import { getCsrf } from '../../upload';
import FolderUploadTray, { type TrayHandle } from '../media/FolderUploadTray';
import FolderInspector from '../media/FolderInspector';

export interface FolderNode {
    id:number; parent_id:number|null; name:string; description:string|null;
    is_active:boolean; depth:number; file_count:number;
}
export interface FolderFile {
    id:number; name:string; type:'image'|'video'|'audio'|'document'; size:number;
    asset_status:string; access_policy:AccessPolicy; needs_metadata:boolean; created_at:string|null;
}
interface Props {
    active:boolean;
    currentUser:User;
    categories:Category[];
    masterData:MasterDataMap;
    metadataSettings:MetadataSettings;
    uploadSettings:UploadSettings;
    visibilityRoles:VisibilityRole[];
    canUpload:boolean;
    onSuccess:(msg:string)=>void;
    onError:(msg:string)=>void;
}
type ViewMode='icons'|'list'|'details';
type MenuState={x:number;y:number;kind:'folder'|'file'|'blank';folderId?:number;fileId?:number}|null;

/** Admins own the folder structure; operators organise what is inside it. */
const canAdminFolders=(role:string)=>role==='super-admin'||role==='department-admin';

const KIND_ICON={image:ImageIcon,video:Video,audio:Music,document:FileText} as const;

async function json(url:string,method='GET',body?:unknown){
    const r=await fetch(url,{
        method,
        headers:{'X-CSRF-TOKEN':getCsrf(),'Accept':'application/json',...(body?{'Content-Type':'application/json'}:{})},
        body:body?JSON.stringify(body):undefined,
    });
    const data=await r.json().catch(()=>({}));
    if(!r.ok)throw new Error(data.message||`HTTP ${r.status}`);
    return data;
}

export default function FoldersPanel(p:Props){
    const [folders,setFolders]=useState<FolderNode[]>([]);
    const [files,setFiles]=useState<FolderFile[]>([]);
    const [folderId,setFolderId]=useState<number|null>(null);
    const [departments,setDepartments]=useState<{id:number;name:string}[]>([]);
    const [departmentId,setDepartmentId]=useState<number|null>(null);
    const [loading,setLoading]=useState(false);
    const [expanded,setExpanded]=useState<Set<number>>(new Set());
    const [selected,setSelected]=useState<Set<number>>(new Set());
    const [selectedFolder,setSelectedFolder]=useState<number|null>(null);
    const [view,setView]=useState<ViewMode>('icons');
    const [filter,setFilter]=useState('');
    const [menu,setMenu]=useState<MenuState>(null);
    const [inspector,setInspector]=useState<'metadata'|'publish'|null>(null);
    // Assets whose thumbnail has not been generated yet (or failed) show their
    // type icon rather than a blank frame.
    const [noThumb,setNoThumb]=useState<Set<number>>(new Set());
    const [history,setHistory]=useState<(number|null)[]>([null]);
    const [historyAt,setHistoryAt]=useState(0);
    const trayRef=useRef<TrayHandle|null>(null);
    const fileInput=useRef<HTMLInputElement>(null);
    const folderInput=useRef<HTMLInputElement>(null);

    const isAdmin=canAdminFolders(p.currentUser.role);

    const load=useCallback(async(target:number|null,department:number|null)=>{
        setLoading(true);
        try{
            const query=new URLSearchParams();
            if(target)query.set('folder_id',String(target));
            if(department)query.set('department_id',String(department));
            const data=await json(`/folders${query.toString()?`?${query}`:''}`);
            setFolders(data.folders||[]);
            setFiles(data.files||[]);
            setDepartments(data.departments||[]);
            // The server decides which department a Super Admin lands in.
            setDepartmentId(data.department_id??null);
        }catch(e){p.onError(e instanceof Error?e.message:'Could not read the folder.');}
        finally{setLoading(false);}
    },[p]);

    const reload=useCallback(()=>load(folderId,departmentId),[load,folderId,departmentId]);

    useEffect(()=>{if(p.active)void load(folderId,departmentId);
        // departmentId is set from the response, so it must not re-trigger the load.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    },[p.active,folderId]);
    useEffect(()=>{setSelected(new Set());},[folderId]);

    // One click anywhere else closes the context menu, as it does in Explorer.
    useEffect(()=>{
        if(!menu)return;
        const close=()=>setMenu(null);
        window.addEventListener('click',close);
        window.addEventListener('scroll',close,true);
        return()=>{window.removeEventListener('click',close);window.removeEventListener('scroll',close,true);};
    },[menu]);

    const foldersRef=useRef<FolderNode[]>([]);
    useEffect(()=>{foldersRef.current=folders;},[folders]);

    const byId=useMemo(()=>new Map(folders.map(f=>[f.id,f])),[folders]);
    const childrenOf=useCallback((parent:number|null)=>folders.filter(f=>f.parent_id===parent),[folders]);
    const current=folderId?byId.get(folderId)||null:null;

    const trail=useMemo(()=>{
        const out:FolderNode[]=[];
        let node=current;
        let guard=0;
        while(node&&guard++<20){out.unshift(node);node=node.parent_id?byId.get(node.parent_id)||null:null;}
        return out;
    },[current,byId]);

    const navigate=useCallback((target:number|null)=>{
        setHistory(h=>{const next=h.slice(0,historyAt+1);next.push(target);return next;});
        setHistoryAt(i=>i+1);
        setFolderId(target);
        setSelectedFolder(null);
        setInspector(null);
        // Reveal where you landed: the tree opens every folder above it.
        if(target){
            setExpanded(prev=>{
                const next=new Set(prev);
                next.add(target);
                let node=foldersRef.current.find(f=>f.id===target);
                let guard=0;
                while(node?.parent_id&&guard++<20){
                    next.add(node.parent_id);
                    node=foldersRef.current.find(f=>f.id===node!.parent_id);
                }
                return next;
            });
        }
    },[historyAt]);

    const goHistory=(delta:number)=>{
        const at=historyAt+delta;
        if(at<0||at>=history.length)return;
        setHistoryAt(at);
        setFolderId(history[at]);
        setSelectedFolder(null);
    };

    const toggleExpand=(id:number)=>setExpanded(x=>{const n=new Set(x);n.has(id)?n.delete(id):n.add(id);return n;});

    const visibleFolders=useMemo(()=>{
        const q=filter.trim().toLowerCase();
        const kids=childrenOf(folderId);
        return q?kids.filter(f=>f.name.toLowerCase().includes(q)):kids;
    },[childrenOf,folderId,filter]);

    const visibleFiles=useMemo(()=>{
        const q=filter.trim().toLowerCase();
        return q?files.filter(f=>f.name.toLowerCase().includes(q)):files;
    },[files,filter]);

    const selectedFiles=useMemo(()=>visibleFiles.filter(f=>selected.has(f.id)),[visibleFiles,selected]);
    const draftCount=visibleFiles.filter(f=>f.needs_metadata).length;

    const clickFile=(e:React.MouseEvent,file:FolderFile)=>{
        setSelectedFolder(null);
        setSelected(prev=>{
            const next=new Set(prev);
            if(e.ctrlKey||e.metaKey){next.has(file.id)?next.delete(file.id):next.add(file.id);return next;}
            if(e.shiftKey&&prev.size){
                const ids=visibleFiles.map(f=>f.id);
                const last=ids.findIndex(id=>prev.has(id));
                const now=ids.indexOf(file.id);
                const [from,to]=last<now?[last,now]:[now,last];
                return new Set(ids.slice(from,to+1));
            }
            return new Set([file.id]);
        });
    };

    const createFolder=async()=>{
        const name=window.prompt('Name of the new folder');
        if(!name?.trim())return;
        try{
            await json('/folders','POST',{name:name.trim(),parent_id:folderId,department_id:departmentId});
            p.onSuccess('Folder created.');
            await reload();
        }catch(e){p.onError(e instanceof Error?e.message:'Could not create the folder.');}
    };

    const renameFolder=async(id:number)=>{
        const node=byId.get(id);
        const name=window.prompt('New name',node?.name||'');
        if(!name?.trim()||name===node?.name)return;
        try{
            await json(`/folders/${id}`,'PATCH',{name:name.trim()});
            p.onSuccess('Folder renamed.');
            await reload();
        }catch(e){p.onError(e instanceof Error?e.message:'Could not rename the folder.');}
    };

    const deleteFolder=async(id:number)=>{
        const node=byId.get(id);
        if(!window.confirm(`Delete “${node?.name}”? It must already be empty.`))return;
        try{
            await json(`/folders/${id}`,'DELETE');
            p.onSuccess('Folder deleted.');
            await reload();
        }catch(e){p.onError(e instanceof Error?e.message:'Could not delete the folder. It may still hold files.');}
    };

    const moveSelection=async(target:number|null)=>{
        if(!selected.size)return;
        try{
            const data=await json('/folders/move-files','POST',{media_ids:[...selected],folder_id:target});
            p.onSuccess(data.message);
            setSelected(new Set());
            await reload();
        }catch(e){p.onError(e instanceof Error?e.message:'Could not move the assets.');}
    };

    const moveViaPrompt=()=>{
        const options=folders.map(f=>`${f.id} — ${'  '.repeat(f.depth)}${f.name}`).join('\n');
        const answer=window.prompt(`Move ${selected.size} item(s) to which folder?\nType the number, or 0 for the library root.\n\n${options}`);
        if(answer===null)return;
        const id=Number(answer.trim());
        if(Number.isNaN(id))return p.onError('That is not a folder number.');
        void moveSelection(id===0?null:id);
    };

    const onDropOnFolder=(target:number)=>{if(selected.size)void moveSelection(target);};

    const pickFiles=(mode:'files'|'folder')=>{
        if(mode==='folder')folderInput.current?.click();
        else fileInput.current?.click();
    };

    const queueUpload=(list:FileList|null)=>{
        if(!list?.length)return;
        trayRef.current?.enqueue(Array.from(list),folderId);
    };

    const treeNode=(node:FolderNode)=>{
        const kids=childrenOf(node.id);
        const open=expanded.has(node.id);
        return (
            <div key={node.id}>
                <button
                    className={`exp-node${folderId===node.id?' active':''}`}
                    style={{paddingLeft:4+node.depth*14}}
                    onClick={()=>navigate(node.id)}
                    onContextMenu={e=>{e.preventDefault();setMenu({x:e.clientX,y:e.clientY,kind:'folder',folderId:node.id});}}
                    onDragOver={e=>{if(selected.size)e.preventDefault();}}
                    onDrop={e=>{e.preventDefault();onDropOnFolder(node.id);}}
                >
                    <span
                        className="twisty"
                        role="button"
                        tabIndex={-1}
                        onClick={e=>{e.stopPropagation();if(kids.length)toggleExpand(node.id);}}
                    >{kids.length?(open?<ChevronDown size={13}/>:<ChevronRight size={13}/>):null}</span>
                    {folderId===node.id?<FolderOpen size={15}/>:<Folder size={15}/>}
                    <span className="label">{node.name}</span>
                    {node.file_count>0&&<span className="count">{node.file_count}</span>}
                </button>
                {open&&kids.map(treeNode)}
            </div>
        );
    };

    if(!p.active)return null;

    return (
        <section className="module-panel active" id="folders-panel">
            <div className="page-title-section">
                <div>
                    <h1 className="page-title"><Folder size={28} style={{color:'var(--color-primary)'}}/><span>Folders</span></h1>
                    <p className="page-subtitle">Upload a file or a whole folder, then describe what you uploaded — one asset, a selection, or the whole folder.</p>
                </div>
            </div>

            <div className={`exp-shell${inspector?' with-inspector':''}`}>
                <div className="explorer">

                    {/* command bar */}
                    <div className="exp-command">
                        {isAdmin&&<button className="exp-cmd primary" onClick={createFolder}><FolderPlus size={15}/> New folder</button>}
                        {p.canUpload&&<>
                            <button className="exp-cmd" onClick={()=>pickFiles('files')}><UploadCloud size={15}/> Upload files</button>
                            <button className="exp-cmd" onClick={()=>pickFiles('folder')}><FolderInput size={15}/> Upload folder</button>
                        </>}
                        <span className="exp-sep"/>
                        <button className="exp-cmd" disabled={!selected.size} onClick={moveViaPrompt}><FolderInput size={15}/> Move to…</button>
                        <button className="exp-cmd" disabled={!selected.size} onClick={()=>setInspector('metadata')}><Tag size={15}/> Describe</button>
                        {isAdmin&&<button className="exp-cmd" disabled={!current} onClick={()=>setInspector('publish')}><Globe2 size={15}/> Publish folder</button>}
                        <span className="exp-grow"/>
                        <button className="exp-cmd icon" title="Refresh" onClick={()=>void load(folderId)}>
                            {loading?<Loader2 size={15} className="spin"/>:<RotateCw size={15}/>}
                        </button>
                    </div>

                    {/* address bar */}
                    <div className="exp-address">
                        <button className="exp-nav-btn" title="Back" disabled={historyAt===0} onClick={()=>goHistory(-1)}><ArrowLeft size={16}/></button>
                        <button className="exp-nav-btn" title="Forward" disabled={historyAt>=history.length-1} onClick={()=>goHistory(1)}><ArrowRight size={16}/></button>
                        <button className="exp-nav-btn" title="Up one level" disabled={!current} onClick={()=>navigate(current?.parent_id??null)}><ArrowUp size={16}/></button>
                        <div className="exp-crumbs">
                            <button className={`exp-crumb${!current?' current':''}`} onClick={()=>navigate(null)}>Library</button>
                            {trail.map((node,i)=>(
                                <span key={node.id} style={{display:'inline-flex',alignItems:'center'}}>
                                    <ChevronRight size={12} className="exp-crumb-sep"/>
                                    <button
                                        className={`exp-crumb${i===trail.length-1?' current':''}`}
                                        onClick={()=>navigate(node.id)}
                                    >{node.name}</button>
                                </span>
                            ))}
                        </div>
                        <label className="exp-search">
                            <Search size={14} style={{color:'var(--text-muted)'}}/>
                            <input
                                id="folder-filter"
                                value={filter}
                                placeholder={current?`Search ${current.name}`:'Search this folder'}
                                onChange={e=>setFilter(e.target.value)}
                            />
                            {filter&&<button onClick={()=>setFilter('')} title="Clear"><X size={13}/></button>}
                        </label>
                    </div>

                    {/* panes */}
                    <div className="exp-body">
                        <div className="exp-tree">
                            {departments.length>1&&(
                                <div className="form-group" style={{padding:'2px 4px 8px'}}>
                                    <label className="form-label" htmlFor="folder-department">Department</label>
                                    <select
                                        id="folder-department"
                                        className="form-select"
                                        value={departmentId??''}
                                        onChange={e=>{
                                            const next=Number(e.target.value);
                                            setDepartmentId(next);
                                            setFolderId(null);
                                            setHistory([null]);
                                            setHistoryAt(0);
                                            void load(null,next);
                                        }}
                                    >
                                        {departments.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}
                                    </select>
                                </div>
                            )}
                            <div className="exp-tree-group">Folders</div>
                            <button
                                className={`exp-node${!folderId?' active':''}`}
                                onClick={()=>navigate(null)}
                                onDragOver={e=>{if(selected.size)e.preventDefault();}}
                                onDrop={e=>{e.preventDefault();if(selected.size)void moveSelection(null);}}
                            >
                                <span className="twisty"/>
                                <Folder size={15}/>
                                <span className="label">Library root</span>
                            </button>
                            {childrenOf(null).map(treeNode)}
                        </div>

                        <div
                            className="exp-content"
                            onContextMenu={e=>{e.preventDefault();setMenu({x:e.clientX,y:e.clientY,kind:'blank'});}}
                            onClick={e=>{if(e.target===e.currentTarget){setSelected(new Set());setSelectedFolder(null);}}}
                        >
                            {visibleFolders.length>0&&<>
                                <div className="exp-group-head"><ChevronDown size={12} className="caret"/> Folders <span className="count">({visibleFolders.length})</span></div>
                                {view==='icons'?(
                                    <div className="exp-tiles">
                                        {visibleFolders.map(f=>(
                                            <button
                                                key={f.id}
                                                className={`exp-tile${selectedFolder===f.id?' selected':''}`}
                                                onClick={()=>setSelectedFolder(f.id)}
                                                onDoubleClick={()=>navigate(f.id)}
                                                onContextMenu={e=>{e.preventDefault();setSelectedFolder(f.id);setMenu({x:e.clientX,y:e.clientY,kind:'folder',folderId:f.id});}}
                                                onDragOver={e=>{if(selected.size)e.preventDefault();}}
                                                onDrop={e=>{e.preventDefault();onDropOnFolder(f.id);}}
                                            >
                                                <span className="glyph"><Folder size={44}/></span>
                                                <span className="name">{f.name}</span>
                                                <span className="sub">{f.file_count} item{f.file_count===1?'':'s'}</span>
                                            </button>
                                        ))}
                                    </div>
                                ):(
                                    <div className="exp-rows">
                                        {visibleFolders.map(f=>(
                                            <button
                                                key={f.id}
                                                className={`exp-row ${view==='details'?'exp-cols-details':'exp-cols-list'}${selectedFolder===f.id?' selected':''}`}
                                                onClick={()=>setSelectedFolder(f.id)}
                                                onDoubleClick={()=>navigate(f.id)}
                                                onContextMenu={e=>{e.preventDefault();setSelectedFolder(f.id);setMenu({x:e.clientX,y:e.clientY,kind:'folder',folderId:f.id});}}
                                            >
                                                <span className="name"><Folder size={15} style={{color:'var(--color-primary)'}}/><span>{f.name}</span></span>
                                                {view==='details'&&<>
                                                    <span className="muted col-hide">File folder</span>
                                                    <span className="muted col-hide">—</span>
                                                    <span className="muted col-hide">{f.file_count}</span>
                                                    <span className="muted col-hide"/>
                                                </>}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </>}

                            <div className="exp-group-head"><ChevronDown size={12} className="caret"/> Files <span className="count">({visibleFiles.length})</span></div>

                            {visibleFiles.length===0&&!loading&&(
                                <p className="setting-help" style={{padding:'14px 2px'}}>
                                    {filter?'Nothing here matches that.':'This folder is empty. Upload files or a folder, or drag a selection onto it from another folder.'}
                                </p>
                            )}

                            {view==='icons'?(
                                <div className="exp-tiles">
                                    {visibleFiles.map(f=>{
                                        const Icon=KIND_ICON[f.type]||FileText;
                                        return (
                                            <button
                                                key={f.id}
                                                className={`exp-tile${selected.has(f.id)?' selected':''}`}
                                                draggable
                                                onDragStart={()=>{if(!selected.has(f.id))setSelected(new Set([f.id]));}}
                                                onClick={e=>clickFile(e,f)}
                                                onContextMenu={e=>{e.preventDefault();if(!selected.has(f.id))setSelected(new Set([f.id]));setMenu({x:e.clientX,y:e.clientY,kind:'file',fileId:f.id});}}
                                            >
                                                <span className="glyph">
                                                    {f.type==='image'&&!noThumb.has(f.id)
                                                        ? <img
                                                            src={`/files/${f.id}/thumbnail`}
                                                            alt=""
                                                            loading="lazy"
                                                            onError={()=>setNoThumb(x=>new Set(x).add(f.id))}
                                                          />
                                                        : <Icon size={40}/>}
                                                </span>
                                                <span className="name">{f.name}</span>
                                                <span className="sub">
                                                    {f.needs_metadata&&<span className="exp-draft-dot" style={{display:'inline-block',marginRight:5}}/>}
                                                    {formatBytes(f.size)}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            ):(
                                <div className="exp-rows">
                                    {view==='details'&&(
                                        <div className="exp-row header exp-cols-details">
                                            <span>Name</span>
                                            <span className="col-hide">Type</span>
                                            <span className="col-hide">Added</span>
                                            <span className="col-hide">Size</span>
                                            <span>Status</span>
                                        </div>
                                    )}
                                    {visibleFiles.map(f=>{
                                        const Icon=KIND_ICON[f.type]||FileText;
                                        return (
                                            <button
                                                key={f.id}
                                                className={`exp-row ${view==='details'?'exp-cols-details':'exp-cols-list'}${selected.has(f.id)?' selected':''}`}
                                                draggable
                                                onDragStart={()=>{if(!selected.has(f.id))setSelected(new Set([f.id]));}}
                                                onClick={e=>clickFile(e,f)}
                                                onContextMenu={e=>{e.preventDefault();if(!selected.has(f.id))setSelected(new Set([f.id]));setMenu({x:e.clientX,y:e.clientY,kind:'file',fileId:f.id});}}
                                            >
                                                <span className="name"><Icon size={15} style={{color:'var(--color-primary)'}}/><span>{f.name}</span></span>
                                                {view==='details'&&<>
                                                    <span className="muted col-hide">{f.type}</span>
                                                    <span className="muted col-hide">{f.created_at?new Date(f.created_at).toLocaleDateString():'—'}</span>
                                                    <span className="muted col-hide">{formatBytes(f.size)}</span>
                                                    <span><span className={`exp-status-chip ${f.needs_metadata?'draft':'active'}`}>{f.needs_metadata?'Draft':f.asset_status}</span></span>
                                                </>}
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* status bar */}
                    <div className="exp-status">
                        <span>{visibleFolders.length+visibleFiles.length} item{visibleFolders.length+visibleFiles.length===1?'':'s'}</span>
                        {selected.size>0&&<span>{selected.size} selected</span>}
                        {draftCount>0&&<span style={{color:'var(--color-warning)'}}>{draftCount} still need describing</span>}
                        <span className="view-toggle">
                            <button className={view==='icons'?'on':''} title="Large icons" onClick={()=>setView('icons')}><LayoutGrid size={14}/></button>
                            <button className={view==='list'?'on':''} title="List" onClick={()=>setView('list')}><List size={14}/></button>
                            <button className={view==='details'?'on':''} title="Details" onClick={()=>setView('details')}><Columns3 size={14}/></button>
                        </span>
                    </div>
                </div>

                {inspector&&(
                    <FolderInspector
                        mode={inspector}
                        folder={current}
                        selectedFiles={selectedFiles}
                        categories={p.categories}
                        masterData={p.masterData}
                        metadataSettings={p.metadataSettings}
                        visibilityRoles={p.visibilityRoles}
                        canPublish={isAdmin}
                        onClose={()=>setInspector(null)}
                        onDone={async msg=>{p.onSuccess(msg);setInspector(null);await reload();}}
                        onError={p.onError}
                    />
                )}
            </div>

            {/* hidden pickers: one for loose files, one for a whole folder */}
            <input ref={fileInput} type="file" multiple hidden onChange={e=>{queueUpload(e.target.files);e.currentTarget.value='';}}/>
            <input
                ref={folderInput}
                type="file"
                multiple
                hidden
                // @ts-expect-error — directory pickers are not in the DOM typings yet
                webkitdirectory=""
                directory=""
                onChange={e=>{queueUpload(e.target.files);e.currentTarget.value='';}}
            />

            <FolderUploadTray
                ref={trayRef}
                uploadSettings={p.uploadSettings}
                onFinished={async msg=>{p.onSuccess(msg);await reload();}}
                onError={p.onError}
            />

            {menu&&(
                <div
                    className="exp-menu"
                    style={{left:Math.min(menu.x,window.innerWidth-240),top:Math.min(menu.y,window.innerHeight-280)}}
                    onClick={e=>e.stopPropagation()}
                >
                    {menu.kind==='folder'&&menu.folderId&&<>
                        <button onClick={()=>{navigate(menu.folderId!);setMenu(null);}}><FolderOpen size={14}/> Open</button>
                        {isAdmin&&<button onClick={()=>{setMenu(null);void renameFolder(menu.folderId!);}}><Pencil size={14}/> Rename</button>}
                        <hr/>
                        <button onClick={()=>{navigate(menu.folderId!);setInspector('metadata');setMenu(null);}}><Tag size={14}/> Describe folder…</button>
                        {isAdmin&&<button onClick={()=>{navigate(menu.folderId!);setInspector('publish');setMenu(null);}}><Globe2 size={14}/> Publish folder…</button>}
                        <hr/>
                        {p.canUpload&&<button onClick={()=>{navigate(menu.folderId!);setMenu(null);pickFiles('files');}}><UploadCloud size={14}/> Upload into this folder</button>}
                        {isAdmin&&<button className="danger" onClick={()=>{setMenu(null);void deleteFolder(menu.folderId!);}}><Trash2 size={14}/> Delete</button>}
                    </>}
                    {menu.kind==='file'&&<>
                        <button onClick={()=>{setMenu(null);moveViaPrompt();}}><FolderInput size={14}/> Move to…</button>
                        <button onClick={()=>{setInspector('metadata');setMenu(null);}}><Tag size={14}/> Describe {selected.size>1?`${selected.size} items`:'this asset'}…</button>
                        <hr/>
                        <button onClick={()=>{setMenu(null);void moveSelection(null);}}><ArrowUp size={14}/> Move to library root</button>
                    </>}
                    {menu.kind==='blank'&&<>
                        {isAdmin&&<button onClick={()=>{setMenu(null);void createFolder();}}><FolderPlus size={14}/> New folder</button>}
                        {p.canUpload&&<>
                            <button onClick={()=>{setMenu(null);pickFiles('files');}}><UploadCloud size={14}/> Upload files</button>
                            <button onClick={()=>{setMenu(null);pickFiles('folder');}}><FolderInput size={14}/> Upload folder</button>
                        </>}
                        <hr/>
                        <button onClick={()=>{setMenu(null);void reload();}}><RotateCw size={14}/> Refresh</button>
                    </>}
                </div>
            )}
        </section>
    );
}
