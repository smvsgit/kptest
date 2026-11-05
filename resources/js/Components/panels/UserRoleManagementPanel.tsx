import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { CheckCircle2, Globe2, KeyRound, Mail, Plus, RefreshCcw, ShieldCheck, Trash2, UserCog, UserPlus, Users, WifiOff } from 'lucide-react';
import type { Department, NetworkPolicySummary, OrganizationUnit, PageAccessKey, PortalRole, User, UserGroup, UserRole } from '../../types';
import { useT } from '../../i18n';

interface Props {
  currentUser:User;
  users:User[];
  departments:Department[];
  organizationUnits:OrganizationUnit[];
  portalRoles:PortalRole[];
  userGroups:UserGroup[];
  networkPolicy:NetworkPolicySummary;
  onSuccess:(m:string)=>void;
  onError:(m:string)=>void;
}

// These maps hold catalogue keys, not English, so every card below reads in
// the user's own language. roles.* already carries the four base role names.
const pageLabels:Record<PageAccessKey,string>={browse:'users.page_browse',upload:'users.page_upload',access:'users.page_access_requests',reports:'users.page_reports',integrations:'users.page_integrations',guide:'users.page_guide',settings:'users.page_settings'};
const permissionLabels:Record<string,string>={upload:'users.perm_upload',delete:'users.perm_delete',manage_policy:'users.perm_manage_policy',manage_lifecycle:'users.perm_manage_lifecycle',review_access:'users.perm_review_access',manage_categories:'users.perm_manage_categories'};
const BASE_ROLES:UserRole[]=['super-admin','department-admin','department-operator','viewer'];
const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')||'';
async function api(url:string,method:string,body:Record<string,unknown>={}){
  const r=await fetch(url,{method,headers:{'X-CSRF-TOKEN':csrf(),'Content-Type':'application/json','Accept':'application/json'},body:method==='GET'?undefined:JSON.stringify(body)});
  const d=await r.json().catch(()=>({}));
  if(!r.ok){const first=Object.values((d.errors||{}) as Record<string,string|string[]>)[0];const detail=Array.isArray(first)?first[0]:first;throw new Error(String(d.message||detail||`HTTP ${r.status}`));}
  return d;
}
function dtLocal(value?:string|null){if(!value)return'';const d=new Date(value);const z=(n:number)=>String(n).padStart(2,'0');return`${d.getFullYear()}-${z(d.getMonth()+1)}-${z(d.getDate())}T${z(d.getHours())}:${z(d.getMinutes())}`}

export default function UserRoleManagementPanel(p:Props){
  const t=useT();
  const isSuper=p.currentUser.role==='super-admin';
  const manageableRoles=useMemo(()=>p.portalRoles.filter(r=>r.is_active&&(isSuper||!['super-admin','department-admin'].includes(r.base_role))),[p.portalRoles,isSuper]);
  const activeDepartments=p.departments.filter(d=>d.is_active);
  const [name,setName]=useState(''),[email,setEmail]=useState(''),[phone,setPhone]=useState(''),[departmentId,setDepartmentId]=useState(isSuper?'':String(p.currentUser.department_id||'')),[unitId,setUnitId]=useState(''),[roleId,setRoleId]=useState('');
  const [roleName,setRoleName]=useState(''),[roleBase,setRoleBase]=useState<UserRole>('viewer');
  const [rolePages,setRolePages]=useState<Record<PageAccessKey,boolean>>({browse:true,upload:false,access:true,reports:true,integrations:false,guide:true,settings:false});
  const [rolePermissions,setRolePermissions]=useState<Record<string,boolean>>({upload:false,delete:false,manage_policy:false,manage_lifecycle:false,review_access:false,manage_categories:false});
  const [groupName,setGroupName]=useState(''),[groupDescription,setGroupDescription]=useState(''),[groupDept,setGroupDept]=useState(isSuper?'':String(p.currentUser.department_id||'')),[groupRole,setGroupRole]=useState(''),[groupMember,setGroupMember]=useState<Record<number,string>>({});
  const [externalDraft,setExternalDraft]=useState<Record<number,{allowed:boolean;starts_at:string;expires_at:string;reason:string}>>({});

  const deptUnits=p.organizationUnits.filter(u=>u.is_active&&String(u.department_id)===departmentId);
  const refresh=()=>router.reload({only:['users','portalRoles','userGroups','networkPolicySummary','currentUser']});

  const createUser=async()=>{
    if(!name.trim()||!email.trim()||!roleId)return p.onError(t('users.required_fields'));
    try{const d=await api('/users','POST',{name:name.trim(),email:email.trim(),phone:phone.trim()||null,department_id:departmentId?Number(departmentId):null,organization_unit_id:unitId?Number(unitId):null,portal_role_id:Number(roleId),send_reset_email:true});p.onSuccess(d.message);setName('');setEmail('');setPhone('');setUnitId('');refresh();}catch(e){p.onError(e instanceof Error?e.message:t('users.create_failed'));}
  };
  const assignRole=async(u:User,id:string)=>{try{const d=await api(`/users/${u.id}/portal-role`,'PATCH',{portal_role_id:Number(id)});p.onSuccess(d.message);refresh();}catch(e){p.onError(e instanceof Error?e.message:t('users.role_assign_failed'))}};
  const setStatus=async(u:User,status:'active'|'disabled'|'inactive')=>{if(!isSuper)return;const reason=status==='active'?t('users.reactivated_reason'):prompt(t('users.status_reason_prompt',{status}))?.trim();if(status!=='active'&&!reason)return;try{const d=await api(`/users/${u.id}/status`,'PATCH',{status,reason:reason||null});p.onSuccess(d.message);refresh();}catch(e){p.onError(e instanceof Error?e.message:t('users.status_failed'))}};
  const resetPassword=async(u:User)=>{if(!confirm(t('users.reset_confirm',{email:u.email})))return;try{const d=await api(`/users/${u.id}/password-reset`,'POST');p.onSuccess(d.message);}catch(e){p.onError(e instanceof Error?e.message:t('users.reset_failed'))}};
  const resetAll=async()=>{if(!isSuper||!confirm(t('users.reset_all_confirm')))return;try{const d=await api('/users/password-reset-all','POST',{confirm:true});p.onSuccess(d.message);}catch(e){p.onError(e instanceof Error?e.message:t('users.reset_all_failed'))}};

  const draftFor=(u:User)=>externalDraft[u.id]||{allowed:Boolean(u.external_access_allowed),starts_at:dtLocal(u.external_access_starts_at),expires_at:dtLocal(u.external_access_expires_at),reason:u.external_access_reason||''};
  const setDraft=(u:User,next:Partial<{allowed:boolean;starts_at:string;expires_at:string;reason:string}>)=>setExternalDraft(x=>({...x,[u.id]:{...draftFor(u),...next}}));
  const saveExternal=async(u:User)=>{const d=draftFor(u);try{const r=await api(`/users/${u.id}/external-access`,'PATCH',{allowed:d.allowed,starts_at:d.starts_at||null,expires_at:d.expires_at||null,reason:d.reason||null});p.onSuccess(r.message);setExternalDraft(x=>{const n={...x};delete n[u.id];return n});refresh();}catch(e){p.onError(e instanceof Error?e.message:t('users.external_failed'))}};

  const createRole=async()=>{if(!isSuper||!roleName.trim())return p.onError(t('users.role_name_required'));try{const d=await api('/portal-roles','POST',{name:roleName.trim(),base_role:roleBase,page_access:rolePages,permissions:rolePermissions});p.onSuccess(d.message);setRoleName('');refresh();}catch(e){p.onError(e instanceof Error?e.message:t('users.role_create_failed'))}};
  const saveRole=async(r:PortalRole,patch:Partial<PortalRole>)=>{if(!isSuper)return;const next={...r,...patch};try{const d=await api(`/portal-roles/${r.id}`,'PATCH',{name:next.name,base_role:next.base_role,is_active:next.is_builtin?true:next.is_active,page_access:next.page_access,permissions:next.permissions});p.onSuccess(d.message);refresh();}catch(e){p.onError(e instanceof Error?e.message:t('users.role_update_failed'))}};
  const deleteRole=async(r:PortalRole)=>{if(!isSuper||r.is_builtin||!confirm(t('users.role_delete_confirm',{name:r.name})))return;try{const d=await api(`/portal-roles/${r.id}`,'DELETE');p.onSuccess(d.message);refresh();}catch(e){p.onError(e instanceof Error?e.message:t('users.role_delete_failed'))}};

  const createGroup=async()=>{if(!groupName.trim())return p.onError(t('users.group_name_required'));try{const d=await api('/user-groups','POST',{name:groupName.trim(),description:groupDescription.trim()||null,department_id:groupDept?Number(groupDept):null,portal_role_id:groupRole?Number(groupRole):null});p.onSuccess(d.message);setGroupName('');setGroupDescription('');refresh();}catch(e){p.onError(e instanceof Error?e.message:t('users.group_create_failed'))}};
  const addGroupMember=async(g:UserGroup)=>{const id=groupMember[g.id];if(!id)return p.onError(t('users.choose_user'));try{const d=await api(`/user-groups/${g.id}/members`,'POST',{user_id:Number(id),apply_group_role:true});p.onSuccess(d.message);setGroupMember(x=>({...x,[g.id]:''}));refresh();}catch(e){p.onError(e instanceof Error?e.message:t('users.add_member_failed'))}};
  const removeGroupMember=async(g:UserGroup,u:User)=>{try{const d=await api(`/user-groups/${g.id}/members/${u.id}`,'DELETE');p.onSuccess(d.message);refresh();}catch(e){p.onError(e instanceof Error?e.message:t('users.remove_member_failed'))}};
  const applyGroupRole=async(g:UserGroup)=>{if(!confirm(t('users.apply_role_confirm',{role:g.portal_role?.name||t('users.group_role'),group:g.name})))return;try{const d=await api(`/user-groups/${g.id}/apply-role`,'POST');p.onSuccess(d.message);refresh();}catch(e){p.onError(e instanceof Error?e.message:t('users.apply_role_failed'))}};
  const saveGroup=async(g:UserGroup,patch:{name:string;description:string|null;portal_role_id:number|null;is_active:boolean})=>{if(!patch.name.trim())return p.onError(t('users.group_name_required'));try{const d=await api(`/user-groups/${g.id}`,'PATCH',{name:patch.name.trim(),description:patch.description,portal_role_id:patch.portal_role_id,is_active:patch.is_active});p.onSuccess(d.message);refresh();}catch(e){p.onError(e instanceof Error?e.message:t('users.group_update_failed'))}};
  const deleteGroup=async(g:UserGroup)=>{if(!confirm(t('users.group_delete_confirm',{name:g.name})))return;try{const d=await api(`/user-groups/${g.id}`,'DELETE');p.onSuccess(d.message);refresh();}catch(e){p.onError(e instanceof Error?e.message:t('users.group_delete_failed'))}};

  const canManageExternal=isSuper||p.networkPolicy.department_admin_can_manage_external_access;
  return <div style={{display:'grid',gap:18}}>
    <div className="admin-guide"><b>{t('users.guide_title')}</b> {t('users.guide')}</div>

    <div className="settings-card"><h2 className="settings-card-title"><UserPlus size={18}/> {t('users.create_user')}</h2><div className="setting-help">{t('users.create_user_help')}</div><div className="settings-grid" style={{marginTop:12}}>
      <div className="form-group"><label className="form-label">{t('users.full_name')}</label><input className="form-input" value={name} onChange={e=>setName(e.target.value)} placeholder={t('users.user_name_placeholder')}/></div>
      <div className="form-group"><label className="form-label">{t('users.email')}</label><input className="form-input" type="email" value={email} onChange={e=>setEmail(e.target.value)} placeholder="user@example.org"/></div>
      <div className="form-group"><label className="form-label">{t('users.phone')}</label><input className="form-input" value={phone} onChange={e=>setPhone(e.target.value)} placeholder={t('users.optional')}/></div>
      <div className="form-group"><label className="form-label">{t('users.department')}</label><select className="form-select" disabled={!isSuper} value={departmentId} onChange={e=>{setDepartmentId(e.target.value);setUnitId('')}}><option value="">{t('users.no_department')}</option>{activeDepartments.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></div>
      <div className="form-group"><label className="form-label">{t('users.unit')}</label><select className="form-select" value={unitId} onChange={e=>setUnitId(e.target.value)}><option value="">{t('users.none')}</option>{deptUnits.map(u=><option key={u.id} value={u.id}>{u.type==='team'?t('users.team'):t('users.sub_department')} · {u.name}</option>)}</select></div>
      <div className="form-group"><label className="form-label">{t('users.role')}</label><select className="form-select" value={roleId} onChange={e=>setRoleId(e.target.value)}><option value="">{t('users.choose_role')}</option>{manageableRoles.map(r=><option key={r.id} value={r.id}>{r.name}{r.is_builtin?'':t('users.custom_suffix')}</option>)}</select></div>
    </div><button className="btn btn-primary" style={{marginTop:12}} onClick={createUser}><Plus size={15}/> {t('users.create_and_email')}</button></div>

    <div className="settings-card"><div className="setting-row"><div><h2 className="settings-card-title" style={{margin:0}}><UserCog size={18}/> {t('users.user_management')}</h2><div className="setting-help">{t('users.user_management_help')}</div></div><div style={{display:'flex',gap:8,flexWrap:'wrap'}}><a className="btn btn-secondary" href="/users/export/csv">{t('users.export_csv')}</a>{isSuper&&<button className="btn btn-secondary" onClick={resetAll}><Mail size={14}/> {t('users.reset_all')}</button>}</div></div>
      <div className="user-admin-grid" style={{marginTop:12}}>{p.users.map(u=>{const d=draftFor(u);const roleOptions=manageableRoles.filter(r=>isSuper||!['super-admin','department-admin'].includes(r.base_role));return <article className="user-admin-card" key={u.id}><div className="user-admin-head"><div><b>{u.name}</b><div className="setting-help">{u.email} · {u.department?.name||t('users.no_department')}</div></div><span className={`status-pill ${u.status||'active'}`}>{t('users.status_'+(u.status||'active'))}</span></div><div className="user-admin-meta"><span>{u.portal_role?.name||t('roles.'+u.role)}</span><span>{(u.groups||[]).map(g=>g.name).join(', ')||t('users.no_group')}</span></div>
        <div className="form-group"><label className="form-label">{t('users.assigned_role')}</label><select className="form-select" disabled={u.id===p.currentUser.id} value={u.portal_role_id||''} onChange={e=>assignRole(u,e.target.value)}><option value="">{t('users.choose_role')}</option>{roleOptions.map(r=><option key={r.id} value={r.id}>{r.name}{r.is_builtin?'':t('users.custom_suffix')}</option>)}</select></div>
        <div style={{display:'flex',gap:7,flexWrap:'wrap',marginTop:8}}><button className="btn btn-secondary" onClick={()=>resetPassword(u)}><KeyRound size={13}/> {t('users.send_password_reset')}</button>{isSuper&&u.id!==p.currentUser.id&&<select className="form-select" style={{width:135}} value={u.status||'active'} onChange={e=>setStatus(u,e.target.value as 'active'|'disabled'|'inactive')}><option value="active">{t('users.status_active')}</option><option value="disabled">{t('users.status_disabled')}</option><option value="inactive">{t('users.status_inactive')}</option></select>}</div>
        <div className="external-access-box"><div className="setting-row"><div><div className="setting-title"><Globe2 size={14}/> {t('users.external_access')}</div><div className="setting-help">{u.external_access_allowed?t('users.exception_configured'):t('users.internal_vpn_only')}</div></div>{canManageExternal?<label className="toggle-switch"><input type="checkbox" checked={d.allowed} onChange={e=>setDraft(u,{allowed:e.target.checked})}/><span className="toggle-track"/></label>:<WifiOff size={18}/>}</div>{canManageExternal&&<><div className="settings-grid compact"><div className="form-group"><label className="form-label">{t('users.start')}</label><input className="form-input" type="datetime-local" value={d.starts_at} onChange={e=>setDraft(u,{starts_at:e.target.value})}/></div><div className="form-group"><label className="form-label">{t('users.expiry_optional')}</label><input className="form-input" type="datetime-local" value={d.expires_at} onChange={e=>setDraft(u,{expires_at:e.target.value})}/></div></div><div className="form-group"><label className="form-label">{t('users.reason')} {d.allowed?t('users.reason_required'):''}</label><input className="form-input" value={d.reason} onChange={e=>setDraft(u,{reason:e.target.value})} placeholder={t('users.reason_placeholder')}/></div><button className="btn btn-secondary" onClick={()=>saveExternal(u)}><CheckCircle2 size={13}/> {t('users.save_external')}</button></>}</div>
      </article>})}</div>
    </div>

    {isSuper&&<div className="settings-card"><h2 className="settings-card-title"><ShieldCheck size={18}/> {t('users.role_management')}</h2><div className="setting-help">{t('users.role_management_help')}</div>
      <div className="role-admin-grid" style={{marginTop:12}}>{p.portalRoles.map(r=><RoleCard key={r.id} role={r} onSave={saveRole} onDelete={deleteRole}/>)}</div>
      <div className="role-create-box"><h3>{t('users.create_new_role')}</h3><div className="settings-grid"><input className="form-input" placeholder={t('users.role_name')} value={roleName} onChange={e=>setRoleName(e.target.value)}/><select className="form-select" value={roleBase} onChange={e=>setRoleBase(e.target.value as UserRole)}>{BASE_ROLES.map(k=><option value={k} key={k}>{t('users.security_base',{role:t('roles.'+k)})}</option>)}</select></div><div className="role-check-grid"><div><b>{t('users.page_access')}</b>{(Object.keys(pageLabels) as PageAccessKey[]).map(k=><label className="check-row" key={k}><input type="checkbox" checked={rolePages[k]} disabled={k==='browse'||(roleBase==='super-admin'&&k==='settings')} onChange={e=>setRolePages(x=>({...x,[k]:e.target.checked}))}/>{t(pageLabels[k])}</label>)}</div><div><b>{t('users.permission_limits')}</b>{Object.keys(permissionLabels).map(k=><label className="check-row" key={k}><input type="checkbox" checked={Boolean(rolePermissions[k])} onChange={e=>setRolePermissions(x=>({...x,[k]:e.target.checked}))}/>{t(permissionLabels[k])}</label>)}</div></div><button className="btn btn-primary" onClick={createRole}><Plus size={14}/> {t('users.create_custom_role')}</button></div>
    </div>}

    <div className="settings-card"><h2 className="settings-card-title"><Users size={18}/> {t('users.user_groups')}</h2><div className="setting-help">{t('users.user_groups_help')}</div><div className="settings-grid" style={{marginTop:12}}><input className="form-input" placeholder={t('users.group_name')} value={groupName} onChange={e=>setGroupName(e.target.value)}/><input className="form-input" placeholder={t('users.description')} value={groupDescription} onChange={e=>setGroupDescription(e.target.value)}/><select className="form-select" disabled={!isSuper} value={groupDept} onChange={e=>setGroupDept(e.target.value)}><option value="">{t('users.global_group')}</option>{activeDepartments.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select><select className="form-select" value={groupRole} onChange={e=>setGroupRole(e.target.value)}><option value="">{t('users.no_role_assignment')}</option>{manageableRoles.map(r=><option key={r.id} value={r.id}>{r.name}</option>)}</select></div><button className="btn btn-primary" style={{marginTop:10}} onClick={createGroup}>{t('users.create_group')}</button>
      <div className="group-admin-grid">{p.userGroups.map(g=><GroupCard key={g.id} group={g} users={p.users} roles={manageableRoles} memberDraft={groupMember[g.id]||''} onMemberDraft={v=>setGroupMember(x=>({...x,[g.id]:v}))} onAddMember={()=>addGroupMember(g)} onRemoveMember={m=>removeGroupMember(g,m)} onApplyRole={()=>applyGroupRole(g)} onSave={patch=>saveGroup(g,patch)} onDelete={()=>deleteGroup(g)}/>)}</div>
    </div>
  </div>
}

function RoleCard({role,onSave,onDelete}:{role:PortalRole;onSave:(r:PortalRole,p:Partial<PortalRole>)=>void;onDelete:(r:PortalRole)=>void}){
  const t=useT();
  const [name,setName]=useState(role.name),[pages,setPages]=useState({...role.page_access}),[permissions,setPermissions]=useState({...role.permissions}),[active,setActive]=useState(role.is_active);
  return <div className="role-admin-card"><div className="setting-row"><div><b>{role.name}</b><div className="setting-help">{role.is_builtin?t('users.builtin'):t('users.custom')} · {t('users.base_label',{role:t('roles.'+role.base_role)})}</div></div>{!role.is_builtin&&<button className="icon-btn danger" onClick={()=>onDelete(role)}><Trash2 size={15}/></button>}</div>{!role.is_builtin&&<input className="form-input" value={name} onChange={e=>setName(e.target.value)}/>}<div className="role-check-grid"><div><span className="mini-heading">{t('users.pages')}</span>{(Object.keys(pageLabels) as PageAccessKey[]).map(k=><label className="check-row" key={k}><input type="checkbox" checked={Boolean(pages[k])} disabled={k==='browse'||(role.base_role==='super-admin'&&k==='settings')} onChange={e=>setPages(x=>({...x,[k]:e.target.checked}))}/>{t(pageLabels[k])}</label>)}</div><div><span className="mini-heading">{t('users.permissions')}</span>{Object.keys(permissionLabels).map(k=><label className="check-row" key={k}><input type="checkbox" checked={Boolean(permissions[k])} onChange={e=>setPermissions(x=>({...x,[k]:e.target.checked}))}/>{t(permissionLabels[k])}</label>)}</div></div>{!role.is_builtin&&<label className="check-row"><input type="checkbox" checked={active} onChange={e=>setActive(e.target.checked)}/>{t('users.role_active')}</label>}<button className="btn btn-secondary" onClick={()=>onSave(role,{name,page_access:pages,permissions,is_active:role.is_builtin?true:active})}>{t('users.save_role')}</button></div>
}

/**
 * A group row that can be renamed and re-roled in place (UAT C07) and whose
 * member picker filters as you type (UAT C08), so large user lists stay usable.
 */
function GroupCard({group,users,roles,memberDraft,onMemberDraft,onAddMember,onRemoveMember,onApplyRole,onSave,onDelete}:{
  group:UserGroup; users:User[]; roles:PortalRole[]; memberDraft:string;
  onMemberDraft:(v:string)=>void; onAddMember:()=>void; onRemoveMember:(u:User)=>void;
  onApplyRole:()=>void; onDelete:()=>void;
  onSave:(patch:{name:string;description:string|null;portal_role_id:number|null;is_active:boolean})=>void;
}){
  const t=useT();
  const [editing,setEditing]=useState(false);
  const [name,setName]=useState(group.name);
  const [description,setDescription]=useState(group.description||'');
  const [roleId,setRoleId]=useState(group.portal_role_id?String(group.portal_role_id):'');
  const [active,setActive]=useState(group.is_active??true);
  const [memberQuery,setMemberQuery]=useState('');

  const members=group.members||[];
  const candidates=useMemo(()=>{
    const q=memberQuery.trim().toLowerCase();
    return users.filter(u=>
      (!group.department_id||u.department_id===group.department_id)
      && !members.some(m=>m.id===u.id)
      && (!q||u.name.toLowerCase().includes(q)||(u.email||'').toLowerCase().includes(q))
    );
  },[users,members,group.department_id,memberQuery]);

  const cancel=()=>{setName(group.name);setDescription(group.description||'');setRoleId(group.portal_role_id?String(group.portal_role_id):'');setActive(group.is_active??true);setEditing(false)};

  return <div className="group-admin-card">
    <div className="setting-row">
      <div><b>{group.name}</b><div className="setting-help">{group.department?.name||t('users.global')} · {t('users.group_meta',{role:group.portal_role?.name||t('users.none'),count:members.length})}{group.is_active===false?t('users.group_inactive'):''}</div></div>
      <div style={{display:'flex',gap:6,alignItems:'center'}}>
        <button className="icon-btn" title={t('settings.edit_item',{name:group.name})} onClick={()=>setEditing(x=>!x)}><UserCog size={15}/></button>
        <button className="icon-btn danger" title={t('settings.delete_item',{name:group.name})} onClick={onDelete}><Trash2 size={15}/></button>
      </div>
    </div>

    {editing&&<div className="group-edit-box">
      <div className="settings-grid">
        <input className="form-input" placeholder={t('users.group_name')} value={name} onChange={e=>setName(e.target.value)}/>
        <input className="form-input" placeholder={t('users.description')} value={description} onChange={e=>setDescription(e.target.value)}/>
        <select className="form-select" value={roleId} onChange={e=>setRoleId(e.target.value)}>
          <option value="">{t('users.no_role_assignment')}</option>
          {roles.map(r=><option key={r.id} value={r.id}>{r.name}</option>)}
        </select>
      </div>
      <label className="check-row"><input type="checkbox" checked={active} onChange={e=>setActive(e.target.checked)}/>{t('users.group_active')}</label>
      <div style={{display:'flex',gap:8,marginTop:8}}>
        <button className="btn btn-primary" onClick={()=>{onSave({name,description:description.trim()||null,portal_role_id:roleId?Number(roleId):null,is_active:active});setEditing(false)}}>{t('users.save_group')}</button>
        <button className="btn btn-secondary" onClick={cancel}>{t('common.cancel')}</button>
      </div>
    </div>}

    <div className="group-members">{members.map(m=><span className="group-member-chip" key={m.id}>{m.name}<button onClick={()=>onRemoveMember(m)}>×</button></span>)}</div>

    <div className="group-member-picker">
      <input className="form-input" placeholder={t('users.search_user')} value={memberQuery} onChange={e=>setMemberQuery(e.target.value)} aria-label={t('users.search_user_aria')}/>
      <div style={{display:'flex',gap:7,marginTop:6}}>
        <select className="form-select" value={memberDraft} onChange={e=>onMemberDraft(e.target.value)}>
          <option value="">{candidates.length?t('users.add_user_count',{count:candidates.length}):t('users.no_matching_user')}</option>
          {candidates.map(u=><option key={u.id} value={u.id}>{u.name}{u.email?` — ${u.email}`:''}</option>)}
        </select>
        <button className="btn btn-secondary" disabled={!memberDraft} onClick={onAddMember}>{t('users.add')}</button>
      </div>
    </div>

    {group.portal_role_id&&<button className="btn btn-secondary" style={{marginTop:8}} onClick={onApplyRole}><RefreshCcw size={13}/> {t('users.apply_group_role')}</button>}
  </div>;
}
