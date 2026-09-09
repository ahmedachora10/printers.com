import{a as m}from"./createLucideIcon-FkZ3yUBR.js";import{r as s}from"./app-CyOXKT25.js";/**
 * @license lucide-react v0.475.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const E=[["polygon",{points:"6 3 20 12 6 21 6 3",key:"1oa8hb"}]],b=m("Play",E);/**
 * @license lucide-react v0.475.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const T=[["polyline",{points:"4 17 10 11 4 5",key:"akl6gq"}],["line",{x1:"12",x2:"20",y1:"19",y2:"19",key:"q2wloq"}]],j=m("Terminal",T);function k(a){const o=document.cookie.split("; ").find(r=>r.startsWith(a+"="));return o?decodeURIComponent(o.split("=").slice(1).join("=")):""}function q(){const[a,o]=s.useState(""),[r,f]=s.useState(!1),[y,c]=s.useState(null),[g,i]=s.useState([]),l=s.useRef(null);return s.useEffect(()=>{var n;(n=l.current)==null||n.scrollTo({top:l.current.scrollHeight})},[a]),s.useEffect(()=>{if(!r)return;const n=u=>u.preventDefault();return window.addEventListener("beforeunload",n),()=>window.removeEventListener("beforeunload",n)},[r]),{output:a,running:r,result:y,errors:g,consoleRef:l,start:async(n,u)=>{var p;f(!0),c(null),i([]),o("");try{const e=await fetch(n,{method:"POST",headers:{"Content-Type":"application/json",Accept:"application/json, text/plain","X-Requested-With":"XMLHttpRequest","X-XSRF-TOKEN":k("XSRF-TOKEN")},body:JSON.stringify(u)}),h=((p=e.headers.get("content-type"))==null?void 0:p.includes("text/plain"))??!1;if(!e.ok||!e.body||!h){const t=await e.json().catch(()=>null);i(t!=null&&t.errors?Object.values(t.errors).flat().map(String):[(t==null?void 0:t.message)??"تعذّر بدء التنفيذ ("+e.status+")."]),c("failure");return}const w=e.body.getReader(),R=new TextDecoder;let d="";for(;;){const{done:t,value:S}=await w.read();if(t)break;d+=R.decode(S,{stream:!0}),o(d)}c(d.includes("== اكتمل النشر ==")?"success":"failure")}catch(e){i([e instanceof Error?e.message:"انقطع الاتصال بالخادم."]),c("failure")}finally{f(!1)}}}}export{b as P,j as T,q as u};
