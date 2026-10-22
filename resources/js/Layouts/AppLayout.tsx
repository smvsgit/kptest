import { useEffect, useRef, useState } from 'react';
import { router, Link } from '@inertiajs/react';
import { Folders, UploadCloud, ShieldCheck, SlidersHorizontal, Sun, Moon, Search, Menu, PlusCircle, FolderPlus, Pencil, LogOut, ChevronRight, Database, Bell, BarChart3, Activity, BookOpen } from 'lucide-react';
import type { Category, PortalNotification, SearchSettings, User, UserRole } from '../types';
import { usePortalBranding } from '../branding';
import { applySessionTheme, writeModeOverride } from '../theme';
type ActiveTab='browse-panel'|'upload-panel'|'access-panel'|'settings-panel'|'notifications-panel'|'reports-panel'|'integrations-panel'|'guide-panel';
export type CategoryModalMode='add-category'|'add-subcategory'|'edit-category'|'edit-subcategory';
export type CategoryModalState={mode:CategoryModalMode;categoryId?:number|null;subcategoryId?:number|null};
interface Props { activeTab:ActiveTab;onTabSwitch:(t:ActiveTab)=>void;categories:Category[];simulatedRole:UserRole;currentUser:User;onRoleChange:(r:UserRole)=>void;onSearch:(q:string)=>void;onCategorySelect:(c:number|null,s:number|null)=>void;onCategoryModal:(s:CategoryModalState)=>void;canUpload:boolean;canManageRoles:boolean;canViewAccess:boolean;canViewReports:boolean;canConfigCategories:boolean;canViewSettings:boolean;canViewIntegrations:boolean;canViewUserGuide:boolean;selectedCategoryId:number|null;selectedSubcategoryId:number|null;searchSettings:SearchSettings;initialSearch?:string;notifications:PortalNotification[];unreadNotifications:number;children:React.ReactNode; }
export default function AppLayout(p:Props){
 const {branding}=usePortalBranding();
 const [theme,setTheme]=useState<'dark'|'light'>(()=>applySessionTheme(p.currentUser.ui_theme).mode);
 const [sidebarOpen,setSidebarOpen]=useState(false),[expanded,setExpanded]=useState<Set<number>>(new Set()),[searchValue,setSearchValue]=useState(p.initialSearch||''),[notificationOpen,setNotificationOpen]=useState(false),[categoryQuery,setCategoryQuery]=useState(''); const timer=useRef<ReturnType<typeof setTimeout>|null>(null);
 useEffect(()=>{setSearchValue(p.initialSearch||'')},[p.initialSearch]);
 useEffect(()=>{setTheme(applySessionTheme(p.currentUser.ui_theme).mode)},[p.currentUser.ui_theme]);
 const toggleTheme=()=>{const t=theme==='dark'?'light':'dark';setTheme(t);writeModeOverride(t);document.documentElement.setAttribute('data-theme',t);document.documentElement.style.colorScheme=t};
 const onInput=(e:React.ChangeEvent<HTMLInputElement>)=>{const v=e.target.value;setSearchValue(v);if(!p.searchSettings.search_as_you_type)return;if(timer.current)clearTimeout(timer.current);timer.current=setTimeout(()=>p.onSearch(v),300)};
 const toggleExpand=(id:number)=>setExpanded(x=>{const n=new Set(x);n.has(id)?n.delete(id):n.add(id);return n});
 const initials=p.currentUser.name.split(' ').map(n=>n[0]).join('').slice(0,3), totalFiles=p.categories.reduce((a,c)=>a+c.files_count,0);
 // Category/sub-category search filters the drawer tree; a sub-category hit keeps its main category visible.
 const q=categoryQuery.trim().toLowerCase();
 const visibleCategories=!q?p.categories.map(c=>({category:c,subcategories:c.subcategories,forceOpen:false}))
  :p.categories.flatMap(c=>{const catHit=c.name.toLowerCase().includes(q),subHits=c.subcategories.filter(s=>s.name.toLowerCase().includes(q));
   if(catHit)return [{category:c,subcategories:c.subcategories,forceOpen:subHits.length>0}];
   if(subHits.length)return [{category:c,subcategories:subHits,forceOpen:true}];
   return []});
 return <div className="app-container"><aside className={`sidebar${sidebarOpen?' active':''}`}><div className="sidebar-logo-container"><div className="sidebar-logo"><img src={branding.logo_url} alt={branding.portal_name} onError={e=>{if(!e.currentTarget.src.endsWith('/logo.svg'))e.currentTarget.src='/logo.svg';else e.currentTarget.style.display='none'}}/><span>{branding.portal_name}</span></div></div><nav className="sidebar-nav">
  <button className={`nav-item${p.activeTab==='browse-panel'?' active':''}`} onClick={()=>{p.onTabSwitch('browse-panel');setSidebarOpen(false)}}><Folders/><span>Browse Files</span></button>
  {p.canUpload&&<button className={`nav-item${p.activeTab==='upload-panel'?' active':''}`} onClick={()=>{p.onTabSwitch('upload-panel');setSidebarOpen(false)}}><UploadCloud/><span>Batch Upload</span></button>}
  {p.canViewAccess&&<button className={`nav-item${p.activeTab==='access-panel'?' active':''}`} onClick={()=>{p.onTabSwitch('access-panel');setSidebarOpen(false)}}><ShieldCheck/><span>Access Requests</span></button>}
  {p.canViewReports&&<button className={`nav-item${p.activeTab==='reports-panel'?' active':''}`} onClick={()=>{p.onTabSwitch('reports-panel');setSidebarOpen(false)}}><BarChart3/><span>Dashboards & Reports</span></button>}
  {p.canViewIntegrations&&<button className={`nav-item${p.activeTab==='integrations-panel'?' active':''}`} onClick={()=>{p.onTabSwitch('integrations-panel');setSidebarOpen(false)}}><Activity/><span>Integrations & Health</span></button>}
  {p.canViewUserGuide&&<button className={`nav-item${p.activeTab==='guide-panel'?' active':''}`} onClick={()=>{p.onTabSwitch('guide-panel');setSidebarOpen(false)}}><BookOpen/><span>User Guide Manual</span></button>}
  {p.canViewSettings&&<button className={`nav-item${p.activeTab==='settings-panel'?' active':''}`} onClick={()=>{p.onTabSwitch('settings-panel');setSidebarOpen(false)}}><SlidersHorizontal/><span>Settings</span></button>}
 </nav>
 <div className="sidebar-category-header">
  <span className="sidebar-category-title">Categories</span>
  {p.canConfigCategories&&<div className="category-header-actions">
   <button className="add-category-btn" title="Add Main Category" aria-label="Add Main Category" onClick={()=>p.onCategoryModal({mode:'add-category'})}><PlusCircle/></button>
   <button className="add-category-btn" title="Add Sub-Category" aria-label="Add Sub-Category" onClick={()=>p.onCategoryModal({mode:'add-subcategory'})}><FolderPlus/></button>
  </div>}
 </div>
 <div className="category-search-wrapper">
  <Search size={13} className="category-search-icon"/>
  <input className="category-search-input" value={categoryQuery} onChange={e=>setCategoryQuery(e.target.value)} placeholder="Search category / sub-category…" aria-label="Search categories"/>
 </div>
 <div className="category-tree">
  {!q&&<div className="category-item"><div className={`category-row${p.selectedCategoryId===null?' active':''}`} onClick={()=>p.onCategorySelect(null,null)}><div className="category-info"><Database size={16}/><span className="category-name">All Files</span></div><span className="category-count">{totalFiles}</span></div></div>}
  {visibleCategories.map(({category:c,subcategories,forceOpen})=>{
   const ex=forceOpen||expanded.has(c.id),active=p.selectedCategoryId===c.id&&p.selectedSubcategoryId===null;
   return <div className="category-item" key={c.id}>
    <div className={`category-row${active?' active':''}`} onClick={()=>{p.onCategorySelect(c.id,null);toggleExpand(c.id)}}>
     <div className="category-info"><ChevronRight className={`category-toggle-icon${ex?' expanded':''}`}/><Folders size={16}/><span className="category-name">{c.name}</span></div>
     <div className="category-row-actions">
      {p.canConfigCategories&&<>
       <button className="category-action-btn" title={`Add sub-category under ${c.name}`} aria-label={`Add sub-category under ${c.name}`} onClick={e=>{e.stopPropagation();p.onCategoryModal({mode:'add-subcategory',categoryId:c.id})}}><FolderPlus size={13}/></button>
       <button className="category-action-btn" title={`Rename ${c.name}`} aria-label={`Rename ${c.name}`} onClick={e=>{e.stopPropagation();p.onCategoryModal({mode:'edit-category',categoryId:c.id})}}><Pencil size={13}/></button>
      </>}
      <span className="category-count">{c.files_count}</span>
     </div>
    </div>
    {ex&&<div className="sub-category-list expanded">
     {subcategories.length===0&&<div className="sub-category-empty">No sub-category yet</div>}
     {subcategories.map(s=><div key={s.id} className={`sub-category-row${p.selectedSubcategoryId===s.id?' active':''}`} onClick={e=>{e.stopPropagation();p.onCategorySelect(c.id,s.id)}}>
      <span>{s.name}</span>
      <div className="category-row-actions">
       {p.canConfigCategories&&<button className="category-action-btn" title={`Edit ${s.name}`} aria-label={`Edit ${s.name}`} onClick={e=>{e.stopPropagation();p.onCategoryModal({mode:'edit-subcategory',categoryId:c.id,subcategoryId:s.id})}}><Pencil size={12}/></button>}
       <span className="sub-category-count">{s.files_count}</span>
      </div>
     </div>)}
    </div>}
   </div>})}
  {q&&visibleCategories.length===0&&<div className="category-search-empty">No category or sub-category matches “{categoryQuery}”.</div>}
 </div></aside>
 <header className="header"><div className="header-left"><button className="menu-toggle" onClick={()=>setSidebarOpen(x=>!x)}><Menu/></button>{p.searchSettings.search_ui_enabled&&<div className="search-wrapper"><Search className="search-icon"/><input className="search-input" value={searchValue} placeholder="Search files, events, people, locations…" onChange={onInput} onKeyDown={e=>{if(e.key==='Enter')p.onSearch(searchValue)}}/><span style={{position:'absolute',right:10,fontSize:9,color:'var(--text-muted)',textTransform:'uppercase'}}>{p.searchSettings.engine_pipeline_enabled?(p.searchSettings.mode==='hybrid'?'Hybrid':'Meili'):'DB'}</span></div>}</div><div className="header-right"><div style={{position:'relative'}}><button className="notification-button" onClick={()=>setNotificationOpen(x=>!x)} aria-label="Notifications"><Bell size={18}/>{p.unreadNotifications>0&&<span className="notification-count">{Math.min(p.unreadNotifications,99)}</span>}</button>{notificationOpen&&<div className="notification-menu"><div style={{padding:'12px 14px',display:'flex',alignItems:'center',justifyContent:'space-between',gap:8}}><strong style={{fontSize:13}}>Notifications</strong><div style={{display:'flex',gap:8}}><button style={{fontSize:10,color:'var(--color-primary)',fontWeight:700}} onClick={()=>{p.onTabSwitch('notifications-panel');setNotificationOpen(false)}}>View all</button><button style={{fontSize:10,color:'var(--color-primary)',fontWeight:700}} onClick={async()=>{await fetch('/notifications/read-all',{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=\"csrf-token\"]')?.getAttribute('content')||'','Accept':'application/json'}});router.reload({only:['notifications','unreadNotifications']})}}>Mark all read</button></div></div>{p.notifications.length===0?<div style={{padding:18,color:'var(--text-muted)',fontSize:12}}>No notifications.</div>:p.notifications.map(n=><button key={n.id} className={`notification-item${n.read_at?'':' unread'}`} onClick={async()=>{if(!n.read_at)await fetch(`/notifications/${n.id}/read`,{method:'PATCH',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=\"csrf-token\"]')?.getAttribute('content')||'','Accept':'application/json'}});router.reload({only:['notifications','unreadNotifications']})}}><div style={{fontWeight:700,fontSize:12}}>{n.title}</div><div style={{fontSize:11,color:'var(--text-secondary)',marginTop:3,lineHeight:1.35}}>{n.message}</div><div style={{fontSize:9,color:'var(--text-muted)',marginTop:5}}>{new Date(n.created_at).toLocaleString()}</div></button>)}</div>}</div>{p.currentUser.role==='super-admin'&&<div className="role-switcher-wrapper"><span>Simulate:</span><select className="role-select" value={p.simulatedRole} onChange={e=>p.onRoleChange(e.target.value as UserRole)}><option value="super-admin">Super Admin</option><option value="department-admin">Department Admin</option><option value="department-operator">Department Operator</option><option value="viewer">Viewer</option></select></div>}<button className="theme-toggle" onClick={toggleTheme}>{theme==='dark'?<Sun/>:<Moon/>}</button><Link href="/profile" className="user-profile"><div className="user-avatar">{initials}</div><div className="user-info"><span className="user-name">{p.currentUser.name}</span><span className="user-role-tag">{p.currentUser.portal_role?.name||p.currentUser.role.replaceAll('-',' ')}</span></div></Link><button className="logout-btn" onClick={()=>router.post('/logout')}><LogOut/><span>Logout</span></button></div></header><main className="main-content">{p.children}</main></div>
}
