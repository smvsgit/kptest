import { useEffect, useMemo, useState } from 'react';
import { BookOpen, ChevronLeft, ChevronRight, Download, ExternalLink, Printer, Search } from 'lucide-react';
import type { UserGuideAccess, UserGuideData } from '../../types';

interface Props { active:boolean; access:UserGuideAccess; onError:(message:string)=>void; }

export default function UserGuidePanel({active,access,onError}:Props){
 const [data,setData]=useState<UserGuideData|null>(null);
 const [loading,setLoading]=useState(false);
 const [page,setPage]=useState(1);
 const [query,setQuery]=useState('');
 useEffect(()=>{if(!active||!access.can_view||data)return;setLoading(true);fetch('/user-guide',{headers:{Accept:'application/json'}}).then(async r=>{const body=await r.json().catch(()=>({}));if(!r.ok)throw new Error(body.message||`HTTP ${r.status}`);setData(body as UserGuideData)}).catch(e=>onError(e instanceof Error?e.message:'User Guide could not be loaded.')).finally(()=>setLoading(false))},[active,access.can_view,data,onError]);
 const pages=data?.pages||[];
 const filtered=useMemo(()=>{const q=query.trim().toLocaleLowerCase();if(!q)return pages;return pages.filter(p=>(p.title+' '+p.html.replace(/<[^>]+>/g,' ')).toLocaleLowerCase().includes(q))},[pages,query]);
 const current=pages.find(p=>p.number===page)||pages[0];
 useEffect(()=>{if(pages.length&&!pages.some(p=>p.number===page))setPage(pages[0].number)},[pages,page]);
 if(!active)return null;
 return <section className="panel user-guide-panel active">
  <div className="section-header user-guide-header"><div><h1><BookOpen size={24}/> User Guide Manual</h1><p>Role / Department / User rights મુજબ authorized manual pages.</p></div><div className="user-guide-actions">{data?.html_url&&<button className="btn btn-secondary" onClick={()=>window.open(data.html_url,'_blank','noopener,noreferrer')}><Printer size={15}/> Print / HTML</button>}{data?.document_url&&<a className="btn btn-secondary" href={data.document_url}><Download size={15}/> Word Manual</a>}</div></div>
  <div className="user-guide-summary"><span><strong>{data?.allowed_pages??access.allowed_pages}</strong> of {data?.total_pages??access.total_pages} pages available</span>{(data?.allowed_pages??access.allowed_pages)<access.total_pages&&<span>Additional pages are restricted by Super Admin policy.</span>}</div>
  {loading&&<div className="settings-card"><div className="admin-guide">Loading authorized User Guide pages…</div></div>}
  {!loading&&data&&<div className="user-guide-shell">
   <aside className="user-guide-toc"><div className="user-guide-search"><Search size={15}/><input value={query} onChange={e=>setQuery(e.target.value)} placeholder="Search manual…"/></div><div className="user-guide-page-list">{filtered.length===0?<div className="admin-guide">No guide page matches this search.</div>:filtered.map(p=><button key={p.number} className={p.number===current?.number?'active':''} onClick={()=>setPage(p.number)}><span>{p.number}</span><div>{p.title}</div></button>)}</div></aside>
   <article className="user-guide-content">{current&&<><div className="user-guide-page-toolbar"><span>Logical page {current.number} / {data.total_pages}</span><div><button className="btn btn-secondary" disabled={current.number<=pages[0].number} onClick={()=>setPage(Math.max(pages[0].number,current.number-1))}><ChevronLeft size={14}/> Previous</button><button className="btn btn-secondary" disabled={current.number>=pages[pages.length-1].number} onClick={()=>setPage(Math.min(pages[pages.length-1].number,current.number+1))}>Next <ChevronRight size={14}/></button></div></div><div className="user-guide-html" dangerouslySetInnerHTML={{__html:current.html}}/></>}</article>
  </div>}
  <div className="user-guide-footnote"><ExternalLink size={13}/> Print/HTML view is filtered to your current page entitlement. Full Word manual download is intentionally available only to accounts authorized for all pages.</div>
 </section>
}
