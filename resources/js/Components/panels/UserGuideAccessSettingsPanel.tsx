import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { BookOpen, Plus, Save, Trash2 } from 'lucide-react';
import type { Department, User } from '../../types';
import { useT } from '../../i18n';

interface GuideSettings {
 enabled:boolean;
 role_pages:{'department-admin':number;'department-operator':number;viewer:number};
 department_pages:Record<string,number>;
 user_pages:Record<string,number>;
}
interface Props { settings?:Record<string,unknown>; departments:Department[]; users:User[]; onSuccess:(m:string)=>void; onError:(m:string)=>void; }
const clamp=(v:number)=>Math.max(0,Math.min(22,Number.isFinite(v)?v:0));
function normalized(raw?:Record<string,unknown>):GuideSettings{
 const role=(raw?.role_pages||{}) as Record<string,unknown>;
 return {enabled:raw?.enabled!==false,role_pages:{'department-admin':clamp(Number(role['department-admin']??14)),'department-operator':clamp(Number(role['department-operator']??10)),viewer:clamp(Number(role.viewer??5))},department_pages:{...((raw?.department_pages||{}) as Record<string,number>)},user_pages:{...((raw?.user_pages||{}) as Record<string,number>)}};
}
export default function UserGuideAccessSettingsPanel({settings,departments,users,onSuccess,onError}:Props){
 const t=useT();
 const [form,setForm]=useState<GuideSettings>(()=>normalized(settings));
 const [deptId,setDeptId]=useState(''); const [userId,setUserId]=useState('');
 const availableDepartments=useMemo(()=>departments.filter(d=>!Object.hasOwn(form.department_pages,String(d.id))),[departments,form.department_pages]);
 const availableUsers=useMemo(()=>users.filter(u=>u.role!=='super-admin'&&!Object.hasOwn(form.user_pages,String(u.id))),[users,form.user_pages]);
 const save=()=>router.patch('/settings/feature-completion',{section:'user_guide',settings:form},{preserveScroll:true,onSuccess:()=>onSuccess(t('guideaccess.saved')),onError:e=>onError(String(Object.values(e)[0]||t('guideaccess.save_failed')))});
 const setRole=(role:keyof GuideSettings['role_pages'],v:number)=>setForm(x=>({...x,role_pages:{...x.role_pages,[role]:clamp(v)}}));
 const addDept=()=>{if(!deptId)return;setForm(x=>({...x,department_pages:{...x.department_pages,[deptId]:14}}));setDeptId('')};
 const addUser=()=>{if(!userId)return;setForm(x=>({...x,user_pages:{...x.user_pages,[userId]:10}}));setUserId('')};
 return <div style={{display:'grid',gap:18}}>
  <div className="settings-card"><h2 className="settings-card-title"><BookOpen size={18}/> {t('guideaccess.title')}</h2><div className="admin-guide">{t('guideaccess.guide')}</div><div className="setting-row"><div className="setting-copy"><div className="setting-title">{t('guideaccess.enable')}</div><div className="setting-help">{t('guideaccess.enable_help')}</div></div><label className="toggle-switch"><input type="checkbox" checked={form.enabled} onChange={e=>setForm({...form,enabled:e.target.checked})}/><span className="toggle-track"/></label></div>
   <h3 style={{fontSize:13,margin:'16px 0 8px'}}>{t('guideaccess.role_defaults')}</h3><div className="settings-grid">{(['department-admin','department-operator','viewer'] as const).map(role=><div className="form-group" key={role}><label className="form-label">{t('guideaccess.allowed_pages',{role:t('roles.'+role)})}</label><input className="form-input" type="number" min={0} max={22} value={form.role_pages[role]} onChange={e=>setRole(role,Number(e.target.value))}/><div className="setting-help">{t('guideaccess.pages_help')}</div></div>)}</div>
  </div>
  <div className="settings-card"><h2 className="settings-card-title">{t('guideaccess.department_overrides')}</h2><div className="admin-guide">{t('guideaccess.department_guide')}</div><div className="guide-access-add"><select className="form-select" value={deptId} onChange={e=>setDeptId(e.target.value)}><option value="">{t('guideaccess.select_department')}</option>{availableDepartments.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select><button className="btn btn-secondary" disabled={!deptId} onClick={addDept}><Plus size={14}/> {t('guideaccess.add')}</button></div><div className="guide-access-rows">{Object.entries(form.department_pages).length===0?<div className="setting-help">{t('guideaccess.no_department_overrides')}</div>:Object.entries(form.department_pages).map(([id,pages])=>{const d=departments.find(x=>String(x.id)===id);return <div className="guide-access-row" key={id}><div><strong>{d?.name||t('guideaccess.department_number',{id})}</strong><div className="setting-help">{t('guideaccess.department_override')}</div></div><input className="form-input" type="number" min={0} max={22} value={pages} onChange={e=>setForm(x=>({...x,department_pages:{...x.department_pages,[id]:clamp(Number(e.target.value))}}))}/><button className="queue-remove-btn" title={t('guideaccess.remove_override')} onClick={()=>setForm(x=>{const next={...x.department_pages};delete next[id];return {...x,department_pages:next}})}><Trash2 size={14}/></button></div>})}</div></div>
  <div className="settings-card"><h2 className="settings-card-title">{t('guideaccess.user_overrides')}</h2><div className="admin-guide">{t('guideaccess.user_guide')}</div><div className="guide-access-add"><select className="form-select" value={userId} onChange={e=>setUserId(e.target.value)}><option value="">{t('guideaccess.select_user')}</option>{availableUsers.map(u=><option key={u.id} value={u.id}>{u.name} · {u.email}</option>)}</select><button className="btn btn-secondary" disabled={!userId} onClick={addUser}><Plus size={14}/> {t('guideaccess.add')}</button></div><div className="guide-access-rows">{Object.entries(form.user_pages).length===0?<div className="setting-help">{t('guideaccess.no_user_overrides')}</div>:Object.entries(form.user_pages).map(([id,pages])=>{const u=users.find(x=>String(x.id)===id);return <div className="guide-access-row" key={id}><div><strong>{u?.name||t('guideaccess.user_number',{id})}</strong><div className="setting-help">{u?.email||t('guideaccess.user_override')}</div></div><input className="form-input" type="number" min={0} max={22} value={pages} onChange={e=>setForm(x=>({...x,user_pages:{...x.user_pages,[id]:clamp(Number(e.target.value))}}))}/><button className="queue-remove-btn" title={t('guideaccess.remove_override')} onClick={()=>setForm(x=>{const next={...x.user_pages};delete next[id];return {...x,user_pages:next}})}><Trash2 size={14}/></button></div>})}</div></div>
  <div style={{display:'flex',justifyContent:'flex-end'}}><button className="btn btn-primary" onClick={save}><Save size={15}/> {t('guideaccess.save')}</button></div>
 </div>
}
