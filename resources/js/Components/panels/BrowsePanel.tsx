import { useMemo, useState } from 'react';
import { Archive, Clock3, Database, FileText, Files, Image, Music, RotateCcw, Save, Search, Shield, SlidersHorizontal, Star, Trash2, Video, X } from 'lucide-react';
import type { AccessPolicy, Department, FilterOwner, MasterDataMap, MasterDataValue, MediaFile, MetadataSettings, PaginatedResult, QuickViewCounts, SavedSearch, SearchSettings, Stats, User, UserRole } from '../../types';
import MediaCard from '../media/MediaCard';

interface BrowseFilters {
    search?: string; scope?: 'everywhere'|'current_department'; type?: string; sort?: string;
    department_id?: number|null; category_id?: number|null; subcategory_id?: number|null; access_policy?: AccessPolicy|null;
    year?: number|null; country_id?: number|null; state_id?: number|null; city_id?: number|null; mandir_id?: number|null;
    event_id?: number|null; person_id?: number|null; language_id?: number|null; media_type_id?: number|null;
    asset_status?: string|null; source_type?: string|null; uploaded_by?: number|null; date_from?: string|null; date_to?: string|null;
    quick_view?: 'favorites'|'recent'|null; page?: number;
}

interface Props {
    active: boolean;
    files: PaginatedResult<MediaFile>;
    stats: Stats;
    filters: BrowseFilters;
    departments: Department[];
    currentUser: User;
    filterOwners: FilterOwner[];
    masterData: MasterDataMap;
    metadataSettings: MetadataSettings;
    savedSearches: SavedSearch[];
    quickViewCounts: QuickViewCounts;
    searchSettings: SearchSettings;
    simulatedRole: UserRole;
    selectedFiles: Set<number>;
    onFilterType: (type: string) => void;
    onFilterChange: (filters: Record<string, unknown>) => void;
    onSort: (sort: string) => void;
    onSelectAll: () => void;
    onToggleSelect: (id: number) => void;
    onToggleFavorite: (file: MediaFile) => void;
    onOpenPreview: (file: MediaFile) => void;
    onPageChange: (page: number) => void;
    onSaveSearch: (name: string) => void;
    onApplySavedSearch: (saved: SavedSearch) => void;
    onDeleteSavedSearch: (saved: SavedSearch) => void;
}

export function BrowsePanelSkeleton({ active }: { active: boolean }) {
    if (!active) return null;
    return <section className="module-panel active" id="browse-panel">
        <div className="page-title-section"><div style={{display:'flex',flexDirection:'column',gap:10}}><div className="skeleton" style={{width:300,height:32,borderRadius:'var(--radius-sm)'}}/><div className="skeleton" style={{width:220,height:16,borderRadius:'var(--radius-xs)'}}/></div></div>
        <div className="stats-grid">{[1,2,3].map(i=><div className="stat-card" key={i}><div style={{display:'flex',flexDirection:'column',gap:10}}><div className="skeleton" style={{width:110,height:12,borderRadius:'var(--radius-xs)'}}/><div className="skeleton" style={{width:80,height:28,borderRadius:'var(--radius-xs)'}}/></div><div className="skeleton" style={{width:48,height:48,borderRadius:'var(--radius-md)'}}/></div>)}</div>
        <div className="filter-bar"><div className="skeleton" style={{width:'100%',height:44,borderRadius:'var(--radius-md)'}}/></div>
        <div className="media-grid">{Array.from({length:8}).map((_,i)=><div className="media-card" key={i}><div className="card-preview-wrapper"><div className="skeleton" style={{position:'absolute',inset:0}}/></div><div className="card-details"><div className="skeleton" style={{height:16}}/><div className="skeleton" style={{height:12,width:'55%',marginTop:6}}/></div></div>)}</div>
    </section>;
}

function buildPageWindows(current:number,last:number):(number|'…')[]{
    if(last<=7)return Array.from({length:last},(_,i)=>i+1); const pages:(number|'…')[]=[]; const add=(n:number|'…')=>{if(pages[pages.length-1]!==n)pages.push(n)};
    add(1);if(current>3)add('…');for(let p=Math.max(2,current-1);p<=Math.min(last-1,current+1);p++)add(p);if(current<last-2)add('…');add(last);return pages;
}
function formatBytes(bytes:number){if(!bytes)return'0 B';const k=1024,s=['B','KB','MB','GB','TB'],i=Math.floor(Math.log(bytes)/Math.log(k));return parseFloat((bytes/Math.pow(k,i)).toFixed(1))+' '+s[i]}
const byParent=(items:MasterDataValue[]|undefined,parent:number|null|undefined)=> (items||[]).filter(x=>x.is_active&&(parent==null||x.parent_id===parent));

export default function BrowsePanel(p:Props){
    const {active,files,stats,filters,departments,currentUser,filterOwners,masterData,metadataSettings,savedSearches,quickViewCounts,searchSettings,simulatedRole,selectedFiles}=p;
    const [advancedOpen,setAdvancedOpen]=useState(false); const [saveName,setSaveName]=useState('');
    if(!active)return null;
    const activeType=filters.type||'all';
    const filterTypes=[{key:'all',label:'All Files',icon:null},{key:'image',label:'Images',icon:<Image size={14}/>},{key:'video',label:'Videos',icon:<Video size={14}/>},{key:'audio',label:'Audio',icon:<Music size={14}/>},{key:'document',label:'Sheets/Docs',icon:<FileText size={14}/>}];
    const typeLabel=filterTypes.find(ft=>ft.key===activeType)?.label||'All Files';
    let subtitle=filters.quick_view==='favorites'?'Your favorite assets':filters.quick_view==='recent'?'Recently viewed assets':'Showing all files across categories';
    if(filters.search)subtitle=`Search results for “${filters.search}”`;
    else if(filters.subcategory_id&&filters.type)subtitle=`${typeLabel} in selected sub-category`; else if(filters.category_id&&filters.type)subtitle=`${typeLabel} in selected category`; else if(filters.type)subtitle=`Showing all ${typeLabel.toLowerCase()}`;

    const countries=byParent(masterData.country,undefined), states=byParent(masterData.state,filters.country_id), cities=byParent(masterData.city,filters.state_id), mandirs=byParent(masterData.mandir,filters.city_id);
    const years=Array.from({length:Math.max(0,metadataSettings.years_max-metadataSettings.years_min+1)},(_,i)=>metadataSettings.years_max-i);
    const pageWindows=buildPageWindows(files.current_page,files.last_page);
    const activeFilterEntries=useMemo(()=>{
        const masterName=(type:keyof MasterDataMap,id:unknown)=>masterData[type]?.find(x=>x.id===Number(id))?.name||String(id??'');
        const entries:{key:keyof BrowseFilters;label:string;value:string}[]=[];
        const add=(key:keyof BrowseFilters,label:string,value?:string)=>{const raw=filters[key];if(raw!==undefined&&raw!==null&&raw!=='')entries.push({key,label,value:value||String(raw)})};
        add('department_id','Department',departments.find(x=>x.id===Number(filters.department_id))?.name);
        add('access_policy','Access',filters.access_policy?String(filters.access_policy).replace(/^./,c=>c.toUpperCase()):undefined);
        add('year','Year'); add('country_id','Country',masterName('country',filters.country_id)); add('state_id','State',masterName('state',filters.state_id)); add('city_id','City',masterName('city',filters.city_id)); add('mandir_id','Mandir',masterName('mandir',filters.mandir_id));
        add('event_id','Event',masterName('event',filters.event_id)); add('person_id','Person',masterName('person',filters.person_id)); add('language_id','Language',masterName('language',filters.language_id)); add('media_type_id','Media Type',masterName('media_type',filters.media_type_id));
        add('source_type','Source'); add('asset_status','Status'); add('uploaded_by','Uploader',filterOwners.find(x=>x.id===Number(filters.uploaded_by))?.name); add('date_from','From'); add('date_to','To');
        return entries;
    },[filters,departments,masterData,filterOwners]);
    const clearKeys={department_id:undefined,access_policy:undefined,year:undefined,country_id:undefined,state_id:undefined,city_id:undefined,mandir_id:undefined,event_id:undefined,person_id:undefined,language_id:undefined,media_type_id:undefined,source_type:undefined,asset_status:undefined,uploaded_by:undefined,date_from:undefined,date_to:undefined,quick_view:undefined};

    return <section className="module-panel active" id="browse-panel">
        <div className="page-title-section"><div><h1 className="page-title"><Search size={28} style={{color:'var(--color-primary)'}}/><span>Search & Discovery</span></h1><p className="page-subtitle">{subtitle}</p></div></div>
        <div className="stats-grid">
            <div className="stat-card"><div className="stat-info"><span className="stat-label">Total Storage Used</span><span className="stat-value">{formatBytes(stats.total_size)}</span></div><div className="stat-icon-wrapper"><Database size={22}/></div></div>
            <div className="stat-card"><div className="stat-info"><span className="stat-label">Total Files</span><span className="stat-value">{stats.total_files.toLocaleString()}</span></div><div className="stat-icon-wrapper accent"><Files size={22}/></div></div>
            <div className="stat-card"><div className="stat-info"><span className="stat-label">Current Session Role</span><span className="stat-value" style={{fontSize:16,textTransform:'capitalize'}}>{simulatedRole.replaceAll('-',' ')}</span></div><div className="stat-icon-wrapper success"><Shield size={22}/></div></div>
        </div>

        <div className="discovery-toolbar">
            <div className="quick-view-group">
                <button className={`filter-chip${!filters.quick_view?' active':''}`} onClick={()=>p.onFilterChange({quick_view:undefined})}><Files size={14}/> All</button>
                <button className={`filter-chip${filters.quick_view==='favorites'?' active':''}`} onClick={()=>p.onFilterChange({quick_view:'favorites'})}><Star size={14}/> Favorites <span className="chip-count">{quickViewCounts.favorites}</span></button>
                <button className={`filter-chip${filters.quick_view==='recent'?' active':''}`} onClick={()=>p.onFilterChange({quick_view:'recent'})}><Clock3 size={14}/> Recently Viewed <span className="chip-count">{quickViewCounts.recent}</span></button>
            </div>
            <div className="search-scope-group" title="Search Everywhere searches all content you can see. Current Department limits search to your assigned department.">
                <span className="filter-label">Scope</span>
                <button className={`scope-btn${(filters.scope||'everywhere')==='everywhere'?' active':''}`} onClick={()=>p.onFilterChange({scope:'everywhere',department_id:undefined})}>Everywhere</button>
                <button className={`scope-btn${filters.scope==='current_department'?' active':''}`} disabled={!currentUser.department_id} onClick={()=>p.onFilterChange({scope:'current_department',department_id:undefined})}>Current Department{currentUser.department?.name?` · ${currentUser.department.name}`:''}</button>
            </div>
        </div>

        <div className="saved-search-bar">
            <div className="saved-search-select-wrap"><Save size={15}/><select className="sort-select" value="" onChange={e=>{const s=savedSearches.find(x=>x.id===Number(e.target.value));if(s)p.onApplySavedSearch(s)}}><option value="">Apply a saved search…</option>{savedSearches.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></div>
            <div className="saved-search-create"><input className="compact-input" value={saveName} maxLength={120} placeholder="Name current search…" onChange={e=>setSaveName(e.target.value)}/><button className="btn btn-secondary" disabled={!saveName.trim()} onClick={()=>{p.onSaveSearch(saveName.trim());setSaveName('')}}><Save size={14}/> Save</button></div>
            {savedSearches.length>0&&<details className="saved-search-manage"><summary>Manage ({savedSearches.length})</summary><div className="saved-search-menu">{savedSearches.map(s=><div className="saved-search-row" key={s.id}><button onClick={()=>p.onApplySavedSearch(s)}>{s.name}</button><button className="icon-danger" title="Delete saved search" onClick={()=>p.onDeleteSavedSearch(s)}><Trash2 size={14}/></button></div>)}</div></details>}
        </div>

        <div className="filter-bar discovery-filter-bar">
            <div className="filter-left">{filterTypes.map(ft=><button key={ft.key} className={`filter-chip${activeType===ft.key?' active':''}`} onClick={()=>p.onFilterType(ft.key)}>{ft.icon}{ft.label}</button>)}</div>
            <div className="filter-right">
                {searchSettings.department_filter&&filters.scope!=='current_department'&&<select className="sort-select" value={filters.department_id||''} onChange={e=>p.onFilterChange({department_id:e.target.value?Number(e.target.value):undefined})}><option value="">All Departments</option>{departments.filter(d=>d.is_active).map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select>}
                <select className="sort-select" value={filters.access_policy||''} onChange={e=>p.onFilterChange({access_policy:e.target.value||undefined})}><option value="">All Access Policies</option><option value="public">Public</option><option value="protected">Protected</option><option value="private">Private</option></select>
                <button className={`btn btn-secondary${advancedOpen?' active':''}`} onClick={()=>setAdvancedOpen(x=>!x)}><SlidersHorizontal size={15}/> Advanced Filters</button>
                <select className="sort-select" value={filters.sort||(filters.search&&searchSettings.best_match_sort?'best':'newest')} onChange={e=>p.onSort(e.target.value)}>{filters.search&&searchSettings.best_match_sort&&<option value="best">Best Match</option>}<option value="newest">Newest</option><option value="updated">Recently Updated</option><option value="oldest">Oldest</option><option value="name_asc">Name A–Z</option><option value="name_desc">Name Z–A</option><option value="size_desc">Largest</option></select>
                <button className="btn btn-secondary" onClick={p.onSelectAll}>Select All</button>
            </div>
        </div>

        {advancedOpen&&<div className="advanced-filter-panel">
            <div className="advanced-filter-header"><div><strong>Advanced metadata filters</strong><span>Combine fields to narrow results. Empty fields are ignored.</span></div><button className="icon-btn" onClick={()=>setAdvancedOpen(false)}><X size={16}/></button></div>
            <div className="advanced-filter-grid">
                <label>Year<select value={filters.year||''} onChange={e=>p.onFilterChange({year:e.target.value?Number(e.target.value):undefined})}><option value="">Any year</option>{years.map(y=><option key={y} value={y}>{y}</option>)}</select></label>
                <label>Country<select value={filters.country_id||''} onChange={e=>p.onFilterChange({country_id:e.target.value?Number(e.target.value):undefined,state_id:undefined,city_id:undefined,mandir_id:undefined})}><option value="">Any country</option>{countries.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
                <label>State<select value={filters.state_id||''} disabled={!filters.country_id} onChange={e=>p.onFilterChange({state_id:e.target.value?Number(e.target.value):undefined,city_id:undefined,mandir_id:undefined})}><option value="">Any state</option>{states.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
                <label>City<select value={filters.city_id||''} disabled={!filters.state_id} onChange={e=>p.onFilterChange({city_id:e.target.value?Number(e.target.value):undefined,mandir_id:undefined})}><option value="">Any city</option>{cities.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
                <label>Mandir<select value={filters.mandir_id||''} disabled={!filters.city_id} onChange={e=>p.onFilterChange({mandir_id:e.target.value?Number(e.target.value):undefined})}><option value="">Any mandir</option>{mandirs.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
                <label>Event / Prasang<select value={filters.event_id||''} onChange={e=>p.onFilterChange({event_id:e.target.value?Number(e.target.value):undefined})}><option value="">Any event</option>{byParent(masterData.event,undefined).map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
                <label>Guruji / Person<select value={filters.person_id||''} onChange={e=>p.onFilterChange({person_id:e.target.value?Number(e.target.value):undefined})}><option value="">Any person</option>{byParent(masterData.person,undefined).map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
                <label>Language<select value={filters.language_id||''} onChange={e=>p.onFilterChange({language_id:e.target.value?Number(e.target.value):undefined})}><option value="">Any language</option>{byParent(masterData.language,undefined).map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
                <label>Media Type<select value={filters.media_type_id||''} onChange={e=>p.onFilterChange({media_type_id:e.target.value?Number(e.target.value):undefined})}><option value="">Any media type</option>{byParent(masterData.media_type,undefined).map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
                <label>Uploaded By<select value={filters.uploaded_by||''} onChange={e=>p.onFilterChange({uploaded_by:e.target.value?Number(e.target.value):undefined})}><option value="">Any uploader</option>{filterOwners.map(x=><option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
                <label>Source<select value={filters.source_type||''} onChange={e=>p.onFilterChange({source_type:e.target.value||undefined})}><option value="">Any source</option><option value="local">Local Upload</option><option value="nas">NAS</option><option value="google-drive">Google Drive</option><option value="youtube">YouTube</option></select></label>
                <label>Status<select value={filters.asset_status||''} onChange={e=>p.onFilterChange({asset_status:e.target.value||undefined})}><option value="">Any status</option><option value="active">Active</option><option value="draft">Draft</option><option value="review">Review</option><option value="approved">Approved</option><option value="published">Published</option><option value="archived">Archived</option><option value="inactive">Inactive</option><option value="broken">Broken</option></select></label>
                <label>Uploaded From<input type="date" value={filters.date_from||''} onChange={e=>p.onFilterChange({date_from:e.target.value||undefined})}/></label>
                <label>Uploaded To<input type="date" value={filters.date_to||''} onChange={e=>p.onFilterChange({date_to:e.target.value||undefined})}/></label>
            </div>
            <div className="advanced-filter-footer"><button className="btn btn-secondary" onClick={()=>p.onFilterChange(clearKeys)}><RotateCcw size={14}/> Clear Metadata Filters</button></div>
        </div>}

        {(activeFilterEntries.length>0||filters.quick_view)&&<div className="active-filter-strip"><span>Active:</span>{filters.quick_view&&<button onClick={()=>p.onFilterChange({quick_view:undefined})}>{filters.quick_view==='favorites'?'Favorites':'Recently Viewed'} <X size={12}/></button>}{activeFilterEntries.map(item=><button key={String(item.key)} onClick={()=>p.onFilterChange({[item.key]:undefined})}>{item.label}: {item.value} <X size={12}/></button>)}<button className="clear-all" onClick={()=>p.onFilterChange(clearKeys)}>Clear all</button></div>}

        <div className="media-grid">{files.data.length===0?<div className="search-empty-state"><Search size={48}/><h3>No matching files</h3><p>Try a broader search, remove filters, or switch the search scope.</p><button className="btn btn-secondary" onClick={()=>p.onFilterChange({...clearKeys,search:undefined,type:undefined,scope:'everywhere'})}><RotateCcw size={14}/> Reset Search</button></div>:files.data.map(file=><MediaCard key={file.id} file={file} isSelected={selectedFiles.has(file.id)} onSelect={()=>p.onToggleSelect(file.id)} onFavorite={()=>p.onToggleFavorite(file)} onOpen={()=>p.onOpenPreview(file)}/>)}</div>

        {files.last_page>1&&<div className="pagination-bar"><span className="pagination-info">Showing {files.from??0}–{files.to??0} of {files.total.toLocaleString()} files</span><div className="pagination-controls"><button className="pagination-btn" disabled={files.current_page===1} onClick={()=>p.onPageChange(files.current_page-1)}>← Prev</button>{pageWindows.map((n,i)=>n==='…'?<span key={`e${i}`} className="pagination-ellipsis">…</span>:<button key={n} className={`pagination-btn${n===files.current_page?' active':''}`} onClick={()=>p.onPageChange(n as number)}>{n}</button>)}<button className="pagination-btn" disabled={files.current_page===files.last_page} onClick={()=>p.onPageChange(files.current_page+1)}>Next →</button></div></div>}
    </section>;
}
