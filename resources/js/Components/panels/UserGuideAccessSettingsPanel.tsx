import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { BookOpen, Plus, Save, Trash2 } from 'lucide-react';
import type { Department, User } from '../../types';

interface GuideSettings {
 enabled:boolean;
 role_pages:{'department-admin':number;'department-operator':number;viewer:number};
 department_pages:Record<string,number>;
 user_pages:Record<string,number>;
}
interface Props { settings?:Record<string,unknown>; departments:Department[]; users:User[]; onSuccess:(m:string)=>void; onError:(m:string)=>void; }
const clamp=(v:number)=>Math.max(0,Math.min(18,Number.isFinite(v)?v:0));
function normalized(raw?:Record<string,unknown>):GuideSettings{
 const role=(raw?.role_pages||{}) as Record<string,unknown>;
 return {enabled:raw?.enabled!==false,role_pages:{'department-admin':clamp(Number(role['department-admin']??14)),'department-operator':clamp(Number(role['department-operator']??10)),viewer:clamp(Number(role.viewer??5))},department_pages:{...((raw?.department_pages||{}) as Record<string,number>)},user_pages:{...((raw?.user_pages||{}) as Record<string,number>)}};
}
export default function UserGuideAccessSettingsPanel({settings,departments,users,onSuccess,onError}:Props){
 const [form,setForm]=useState<GuideSettings>(()=>normalized(settings));
 const [deptId,setDeptId]=useState(''); const [userId,setUserId]=useState('');
 const availableDepartments=useMemo(()=>departments.filter(d=>!Object.hasOwn(form.department_pages,String(d.id))),[departments,form.department_pages]);
 const availableUsers=useMemo(()=>users.filter(u=>u.role!=='super-admin'&&!Object.hasOwn(form.user_pages,String(u.id))),[users,form.user_pages]);
 const save=()=>router.patch('/settings/feature-completion',{section:'user_guide',settings:form},{preserveScroll:true,onSuccess:()=>onSuccess('User Guide access policy saved.'),onError:e=>onError(String(Object.values(e)[0]||'User Guide access policy could not be saved.'))});
 const setRole=(role:keyof GuideSettings['role_pages'],v:number)=>setForm(x=>({...x,role_pages:{...x.role_pages,[role]:clamp(v)}}));
 const addDept=()=>{if(!deptId)return;setForm(x=>({...x,department_pages:{...x.department_pages,[deptId]:14}}));setDeptId('')};
 const addUser=()=>{if(!userId)return;setForm(x=>({...x,user_pages:{...x.user_pages,[userId]:10}}));setUserId('')};
 return <div style={{display:'grid',gap:18}}>
  <div className="settings-card"><h2 className="settings-card-title"><BookOpen size={18}/> User Guide Manual Access</h2><div className="admin-guide">0 pages = User Guide menu hidden. Resolution order: specific User override → Department override → Role default. Super Admin always receives all 18 pages to prevent governance lockout.</div><div className="setting-row"><div className="setting-copy"><div className="setting-title">Enable User Guide for non-Super-Admin accounts</div><div className="setting-help">When OFF, only Super Admin can open the manual.</div></div><label className="toggle-switch"><input type="checkbox" checked={form.enabled} onChange={e=>setForm({...form,enabled:e.target.checked})}/><span className="toggle-track"/></label></div>
   <h3 style={{fontSize:13,margin:'16px 0 8px'}}>Role defaults</h3><div className="settings-grid">{([['department-admin','Department Admin'],['department-operator','Department Operator'],['viewer','Viewer']] as const).map(([role,label])=><div className="form-group" key={role}><label className="form-label">{label} - allowed pages</label><input className="form-input" type="number" min={0} max={18} value={form.role_pages[role]} onChange={e=>setRole(role,Number(e.target.value))}/><div className="setting-help">0 = hidden; 18 = full manual.</div></div>)}</div>
  </div>
  <div className="settings-card"><h2 className="settings-card-title">Department Overrides</h2><div className="admin-guide">Optional. A department override replaces the role default for users in that department unless a user-specific override exists.</div><div className="guide-access-add"><select className="form-select" value={deptId} onChange={e=>setDeptId(e.target.value)}><option value="">-- Select department --</option>{availableDepartments.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select><button className="btn btn-secondary" disabled={!deptId} onClick={addDept}><Plus size={14}/> Add</button></div><div className="guide-access-rows">{Object.entries(form.department_pages).length===0?<div className="setting-help">No department overrides.</div>:Object.entries(form.department_pages).map(([id,pages])=>{const d=departments.find(x=>String(x.id)===id);return <div className="guide-access-row" key={id}><div><strong>{d?.name||`Department #${id}`}</strong><div className="setting-help">Department override</div></div><input className="form-input" type="number" min={0} max={18} value={pages} onChange={e=>setForm(x=>({...x,department_pages:{...x.department_pages,[id]:clamp(Number(e.target.value))}}))}/><button className="queue-remove-btn" title="Remove override" onClick={()=>setForm(x=>{const next={...x.department_pages};delete next[id];return {...x,department_pages:next}})}><Trash2 size={14}/></button></div>})}</div></div>
  <div className="settings-card"><h2 className="settings-card-title">Specific User Overrides</h2><div className="admin-guide">Optional. User override has highest priority. Use 0 pages to hide the manual for a specific user without changing the rest of the role/department.</div><div className="guide-access-add"><select className="form-select" value={userId} onChange={e=>setUserId(e.target.value)}><option value="">-- Select user --</option>{availableUsers.map(u=><option key={u.id} value={u.id}>{u.name} · {u.email}</option>)}</select><button className="btn btn-secondary" disabled={!userId} onClick={addUser}><Plus size={14}/> Add</button></div><div className="guide-access-rows">{Object.entries(form.user_pages).length===0?<div className="setting-help">No user overrides.</div>:Object.entries(form.user_pages).map(([id,pages])=>{const u=users.find(x=>String(x.id)===id);return <div className="guide-access-row" key={id}><div><strong>{u?.name||`User #${id}`}</strong><div className="setting-help">{u?.email||'Specific user override'}</div></div><input className="form-input" type="number" min={0} max={18} value={pages} onChange={e=>setForm(x=>({...x,user_pages:{...x.user_pages,[id]:clamp(Number(e.target.value))}}))}/><button className="queue-remove-btn" title="Remove override" onClick={()=>setForm(x=>{const next={...x.user_pages};delete next[id];return {...x,user_pages:next}})}><Trash2 size={14}/></button></div>})}</div></div>
  <div style={{display:'flex',justifyContent:'flex-end'}}><button className="btn btn-primary" onClick={save}><Save size={15}/> Save User Guide Rights</button></div>
 </div>
}
