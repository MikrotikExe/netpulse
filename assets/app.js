'use strict';
// DudeWeb SPA
const SVGNS='http://www.w3.org/2000/svg';
const $=id=>document.getElementById(id);
const api={
  get:(a,p={})=>fetch('api.php?action='+a+'&'+new URLSearchParams(p)).then(r=>r.json()),
  post:(a,b)=>fetch('api.php?action='+a,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)}).then(r=>r.json()),
};
const esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const statusSk=s=>TR(({up:'funkčné',pending:'nereaguje',down:'nefunkčné',unknown:'neznáme'}[s]||'neznáme'));
function fmtBps(v){if(v==null)return '–';v=+v;if(v>=1e9)return (v/1e9).toFixed(2)+' Gbps';if(v>=1e6)return (v/1e6).toFixed(2)+' Mbps';if(v>=1e3)return (v/1e3).toFixed(1)+' kbps';return Math.round(v)+' bps';}
// farba linky podľa vyťaženia interface – plynulé spektrum (viditeľné aj pri nízkych %)
// 0%→modrá, ~15%→tyrkys, ~30%→zelená, ~50%→žltá, ~70%→oranžová, ~90%+→červená
function hsl2rgb(h,s,l){h=((h%360)+360)%360/360;const q=l<0.5?l*(1+s):l+s-l*s,p=2*l-q;
  const hk=t=>{t=(t+1)%1;if(t<1/6)return p+(q-p)*6*t;if(t<0.5)return q;if(t<2/3)return p+(q-p)*(2/3-t)*6;return p;};
  return `rgb(${Math.round(hk(h+1/3)*255)},${Math.round(hk(h)*255)},${Math.round(hk(h-1/3)*255)})`;}
function linkColor(util){
  if(util==null)return null;
  const u=Math.max(0,Math.min(1,util));
  const stops=[[0,210],[0.15,180],[0.3,140],[0.5,80],[0.7,40],[0.9,0]];
  let hue=stops[stops.length-1][1];
  for(let i=0;i<stops.length-1;i++){ if(u<=stops[i+1][0]){const a=stops[i],b=stops[i+1];const t=(u-a[0])/((b[0]-a[0])||1);hue=a[1]+(b[1]-a[1])*t;break;} }
  return hsl2rgb(hue,0.8,0.55);
}
const UI={"sun": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><circle cx=\"12\" cy=\"12\" r=\"4\"/><path d=\"M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M6.3 17.7l-1.4 1.4M19.1 4.9l-1.4 1.4\"/></svg>", "moon": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z\"/></svg>", "mon": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"2\" y=\"3\" width=\"20\" height=\"14\" rx=\"2\"/><path d=\"M8 21h8M12 17v4\"/></svg>", "palette": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M12 2a10 10 0 1 0 0 20 1.5 1.5 0 0 0 1.06-2.56 1.5 1.5 0 0 1 1.06-2.56H16a5 5 0 0 0 5-5c0-5-4-9-9-9z\"/><circle cx=\"7.5\" cy=\"10.5\" r=\"1\"/><circle cx=\"12\" cy=\"7.5\" r=\"1\"/><circle cx=\"16.5\" cy=\"10.5\" r=\"1\"/></svg>", "key": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><circle cx=\"7.5\" cy=\"15.5\" r=\"4\"/><path d=\"M10.5 12.5 20 3M17 6l2 2M13.5 9.5l2 2\"/></svg>", "users": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2\"/><circle cx=\"9\" cy=\"7\" r=\"4\"/><path d=\"M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75\"/></svg>", "send": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M22 2 11 13\"/><path d=\"M22 2 15 22l-4-9-9-4z\"/></svg>", "db": "<svg class=\"ic\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><ellipse cx=\"12\" cy=\"5\" rx=\"9\" ry=\"3\"/><path d=\"M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5\"/><path d=\"M3 12c0 1.7 4 3 9 3s9-1.3 9-3\"/></svg>"};

function canEdit(){return S.role==='admin'||S.role==='administrator';}
let S={mapId:null,data:{nodes:[],links:[],map:null},view:{x:0,y:0,k:1},mode:null,linkFrom:null,sel:null,section:'maps'};

// ---------- summary + alerts ----------
function paintSummary(s){
  $('summary').innerHTML=
    `<div class="chip"><span class="dot"></span><span class="clabel">${TR('Zariadenia')} </span><b>${s.total||0}</b></div>`+
    `<div class="chip up"><span class="dot up"></span><span class="clabel">${TR('funkčné')} </span><b>${s.up||0}</b></div>`+
    `<div class="chip pending"><span class="dot pending"></span><span class="clabel">${TR('nereaguje')} </span><b>${s.pending||0}</b></div>`+
    `<div class="chip down"><span class="dot down"></span><span class="clabel">${TR('nefunkčné')} </span><b>${s.down||0}</b></div>`+
    `<div class="chip unknown"><span class="dot unknown"></span><span class="clabel">${TR('neznáme')} </span><b>${s.unknown||0}</b></div>`;
}
async function loadSummary(){ paintSummary(await api.get('summary')); }
async function loadAlerts(){
  const f=await api.get('faults');
  const ul=$('alert-list');
  if(!f.length){ul.innerHTML='<li>'+TR('Žiadne aktívne poruchy')+' 🎉</li>';return;}
  ul.innerHTML=f.map(d=>`<li><b>${esc(d.name)}</b><br>${esc(d.ip||'')} · ${esc(d.last_check||'')}</li>`).join('');
}

// ---------- sekcie ----------
document.querySelectorAll('#sections button').forEach(b=>b.onclick=()=>switchSection(b.dataset.sec));
function switchSection(sec){
  S.section=sec;
  document.querySelectorAll('#sections button').forEach(b=>b.classList.toggle('active',b.dataset.sec===sec));
  document.querySelectorAll('.view').forEach(v=>v.classList.remove('active'));
  $('view-'+sec).classList.add('active');
  const showMapSidebar=sec==='maps';
  $('sidebar').style.display=showMapSidebar?'flex':'none';
  if(sec==='devices')loadDevices();
  if(sec==='services')loadServices();
  if(sec==='faults')loadFaults();
  if(sec==='events')loadEvents();
  if(sec==='graphs')loadGraphs();
  if(sec==='settings')loadSettings();
}

// ---------- MAPY ----------
async function loadMaps(){
  const maps=await api.get('maps');
  const ul=$('map-list');ul.innerHTML='';
  maps.forEach((m)=>{
    const li=document.createElement('li');li.dataset.id=m.id;
    li.innerHTML=`<span class="grip" title="${TR('Ťahaj pre presun')}">⠿</span>`+
      `<span class="mname">${esc(m.name||TR('(bez názvu)'))}</span>`+
      (m.down>0?`<span class="badge">${m.down}</span>`:`<span class="cnt">${m.nodes}</span>`);
    if(m.id===S.mapId)li.classList.add('active');
    installMapDrag(li,m.id);
    ul.appendChild(li);
  });
  if(S.mapId===null&&maps.length)selectMap(maps[0].id);
}

// ---- drag & drop radenie máp (chyť a potiahni) ----
function installMapDrag(li,id){
  let startY=0,dragging=false,moved=false;
  const ul=document.getElementById('map-list');
  const onDown=e=>{
    if(e.button!==undefined&&e.button!==0)return;
    startY=e.clientY;dragging=true;moved=false;
    document.addEventListener('mousemove',onMove);
    document.addEventListener('mouseup',onUp);
  };
  const onMove=e=>{
    if(!dragging)return;
    if(!canEdit())return;   // user nepresúva mapy
    if(!moved&&Math.abs(e.clientY-startY)<5)return;   // rozlíš klik od ťahu
    moved=true;li.classList.add('dragging');e.preventDefault();
    const items=[...ul.querySelectorAll('li:not(.dragging)')];
    const after=items.find(it=>{const r=it.getBoundingClientRect();return e.clientY<r.top+r.height/2;});
    if(after)ul.insertBefore(li,after);else ul.appendChild(li);
  };
  const onUp=async()=>{
    dragging=false;li.classList.remove('dragging');
    document.removeEventListener('mousemove',onMove);
    document.removeEventListener('mouseup',onUp);
    if(moved){
      const order=[...ul.querySelectorAll('li')].map(it=>+it.dataset.id);
      await api.post('reorder_maps',{order});
    }else{
      selectMap(id);   // bez ťahu = obyčajný klik = výber mapy
    }
  };
  li.addEventListener('mousedown',onDown);
}

async function selectMap(id){
  S.mapId=id;S.sel=null;S.linkFrom=null;
  document.getElementById('sidebar')&&document.getElementById('sidebar').classList.remove('open');
  document.querySelectorAll('#map-list li').forEach(li=>li.classList.toggle('active',+li.dataset.id===id));
  S.data=await api.get('map',{id});
  fitView();render();
}

// zalomí text na riadky s max počtom znakov (po slovách)
function wrapText(str,maxChars){
  const words=String(str||'').split(/\s+/).filter(Boolean);
  const lines=[];let cur='';
  words.forEach(w=>{
    const test=(cur?cur+' ':'')+w;
    if(test.length<=maxChars){cur=test;}
    else{ if(cur)lines.push(cur); cur=(w.length>maxChars)?w.slice(0,maxChars-1)+'…':w; }
  });
  if(cur)lines.push(cur);
  return lines.length?lines:[''];
}
function render(){
  const svg=$('canvas');svg.innerHTML='';
  // gradienty + defs
  const defs=document.createElementNS(SVGNS,'defs');
  defs.innerHTML=`
    <linearGradient id="gUp" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#4be07d"/><stop offset="1" stop-color="#1f9d4d"/></linearGradient>
    <linearGradient id="gDown" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff6b84"/><stop offset="1" stop-color="#c62e4a"/></linearGradient>
    <linearGradient id="gPending" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffdb7a"/><stop offset="1" stop-color="#d99a1e"/></linearGradient>
    <linearGradient id="gUnk" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8a94aa"/><stop offset="1" stop-color="#565f75"/></linearGradient>`;
  svg.appendChild(defs);
  const g=document.createElementNS(SVGNS,'g');
  g.setAttribute('transform',`translate(${S.view.x},${S.view.y}) scale(${S.view.k})`);
  svg.appendChild(g);
  const byId={};S.data.nodes.forEach(n=>byId[n.id]=n);

  // linky
  S.data.links.forEach(l=>{
    const a=byId[l.from_node],b=byId[l.to_node];if(!a||!b)return;
    const line=document.createElementNS(SVGNS,'line');
    line.setAttribute('x1',a.x);line.setAttribute('y1',a.y);
    line.setAttribute('x2',b.x);line.setAttribute('y2',b.y);
    line.setAttribute('stroke-width',Math.max(1.5,(l.thickness||l.width||2)));
    // farba podľa vyťaženia interface (tok / rýchlosť portu)
    const _util=(l.speed_bps>0&&(l.rx_bps!=null||l.tx_bps!=null))?Math.max(l.rx_bps||0,l.tx_bps||0)/l.speed_bps:null;
    const _col=linkColor(_util); if(_col){line.style.stroke=_col;line.style.strokeOpacity='0.95';}
    // štýl čiary podľa typu spoja (0 plná, 1 čiarkovaná, 2 bodkovaná, 3 čiara-bodka)
    const dash={1:'10 6',2:'1 7',3:'14 5 2 5'}[l.style];
    if(dash){line.setAttribute('stroke-dasharray',dash);line.setAttribute('stroke-linecap','round');}
    line.setAttribute('class','edge');line.dataset.link=l.id;
    line.onclick=()=>onLinkClick(l);
    if(l.ltype){const ti=document.createElementNS(SVGNS,'title');ti.textContent=l.ltype;line.appendChild(ti);}
    g.appendChild(line);
    // neviditeľná širšia čiara pre ľahšie kliknutie
    const hit=document.createElementNS(SVGNS,'line');
    hit.setAttribute('x1',a.x);hit.setAttribute('y1',a.y);hit.setAttribute('x2',b.x);hit.setAttribute('y2',b.y);
    hit.setAttribute('stroke','transparent');hit.setAttribute('stroke-width','16');
    hit.setAttribute('class','edge-hit');hit.dataset.link=l.id;
    hit.onclick=()=>onLinkClick(l);
    g.appendChild(hit);
    // reálny tok Rx/Tx (SNMP) – modrý štítok na strede čiary
    if(l.rx_bps!=null||l.tx_bps!=null){
      const mx=(a.x+b.x)/2, my=(a.y+b.y)/2;
      const t1='Rx: '+fmtBps(l.rx_bps), t2='Tx: '+fmtBps(l.tx_bps)+((_util!=null)?' ('+Math.round(_util*100)+'%)':'');
      const w=Math.max(t1.length,t2.length)*5.6+12;
      const lg=document.createElementNS(SVGNS,'g');
      lg.setAttribute('transform',`translate(${mx},${my})`);
      const r=document.createElementNS(SVGNS,'rect');
      r.setAttribute('x',-w/2);r.setAttribute('y',-14);r.setAttribute('width',w);r.setAttribute('height',28);
      r.setAttribute('rx',5);r.setAttribute('class','traf-bg');
      lg.appendChild(r);
      lg.appendChild(txt(t1,0,-2,'traf-t',null,'middle'));
      lg.appendChild(txt(t2,0,10,'traf-t',null,'middle'));
      g.appendChild(lg);
    }
  });

  // uzly
  S.data.nodes.forEach(n=>{
    const grp=document.createElementNS(SVGNS,'g');
    grp.setAttribute('class','node '+(n.status||'unknown')+(S.sel===n.id?' sel':''));
    grp.setAttribute('transform',`translate(${n.x},${n.y})`);
    grp.dataset.node=n.id;
    if(n.kind==='submap'){
      // názov zalomíme na viac riadkov a koliečko zväčšíme, aby sa zmestil (ako Dude)
      const name=n.sub_name||n.label||'';
      const lines=wrapText(name,14);
      const countLine=`${n.sub_total||0} / 0 / ${n.sub_down||0}`;
      const all=lines.concat([countLine]);
      const lineH=13;
      const longest=Math.max.apply(null,all.map(l=>l.length));
      const r=Math.max(30, longest*3.4+12, all.length*lineH/2+10);
      const c=document.createElementNS(SVGNS,'circle');
      c.setAttribute('r',r);c.setAttribute('class','box');
      grp.appendChild(c);
      const y0=-(all.length-1)*lineH/2+4;
      all.forEach((ln,i)=>{
        const t=txt(ln,0,y0+i*lineH,'sub','#0b1220','middle');
        if(i===all.length-1)t.setAttribute('font-weight','700');
        grp.appendChild(t);
      });
    }else{
      // karta zariadenia s veľkou ikonou navrchu a názvom pod ňou (ako Dude, moderne)
      const name=n.dev_name||n.label||('#'+n.id);
      let lines=wrapText(name,22);
      if(lines.length>2) lines=[lines[0], lines[1].slice(0,20)+'…'];
      const lh=13, hasIcon=!!n.icon, iconSize=30;
      const longest=Math.max.apply(null,lines.map(l=>l.length));
      const w=Math.max(120, longest*6.6+22);
      const h=(hasIcon?iconSize+8:8)+lines.length*lh+8;
      const rect=document.createElementNS(SVGNS,'rect');
      rect.setAttribute('x',-w/2);rect.setAttribute('y',-h/2);
      rect.setAttribute('width',w);rect.setAttribute('height',h);
      rect.setAttribute('rx',10);rect.setAttribute('class','box');
      grp.appendChild(rect);
      if(hasIcon){
        const bs=iconSize+6;
        const bg=document.createElementNS(SVGNS,'rect');
        bg.setAttribute('x',-bs/2);bg.setAttribute('y',-h/2+5);
        bg.setAttribute('width',bs);bg.setAttribute('height',bs);bg.setAttribute('rx',8);
        bg.setAttribute('fill','#ffffff');bg.setAttribute('fill-opacity','0.85');
        grp.appendChild(bg);
        const img=document.createElementNS(SVGNS,'image');
        img.setAttributeNS('http://www.w3.org/1999/xlink','href','assets/icons/'+n.icon);
        img.setAttribute('href','assets/icons/'+n.icon);
        img.setAttribute('x',-iconSize/2);img.setAttribute('y',-h/2+8);
        img.setAttribute('width',iconSize);img.setAttribute('height',iconSize);
        grp.appendChild(img);
      }
      let ly=-h/2+(hasIcon?iconSize+8:8)+11;
      lines.forEach(ln=>{grp.appendChild(txt(ln,0,ly,'lbl','#0b1220','middle'));ly+=lh;});
    }
    grp.onmousedown=e=>onNodeDown(e,n);
    grp.onmouseenter=e=>showTip(e,n);
    grp.onmouseleave=()=>$('tooltip').style.display='none';
    grp.onclick=e=>onNodeClick(e,n);
    g.appendChild(grp);
  });
}
function txt(s,x,y,cls,fill,anchor){
  const t=document.createElementNS(SVGNS,'text');
  t.setAttribute('x',x);t.setAttribute('y',y);t.setAttribute('class',cls);
  if(fill)t.setAttribute('fill',fill);if(anchor)t.setAttribute('text-anchor',anchor);
  t.textContent=s;return t;
}
function showTip(e,n){
  const tip=$('tooltip');
  if(n.kind==='submap'){
    tip.innerHTML=`<b>${esc(n.sub_name||'')}</b><br>${TR('Mapa')} · ${n.sub_total||0} ${TR('zariadení')}`+
      (n.sub_down?` · <span class="st-down">${n.sub_down} ${TR('dole')}</span>`:'');
  }else{
    const r=n.rtt!=null?` · ${n.rtt} ms`:'';
    tip.innerHTML=`<b>${esc(n.dev_name||n.label||'')}</b><br>`+
      (n.ip?`IP: ${esc(n.ip)}<br>`:'')+
      `${TR('Stav')}: <span class="st-${n.status||'unknown'}">${statusSk(n.status)}${r}</span>`;
  }
  tip.style.display='block';tip.style.left=(e.offsetX+16)+'px';tip.style.top=(e.offsetY+16)+'px';
}

// ---------- ťahanie ----------
let drag=null;
function onNodeDown(e,n){
  if(S.mode)return;
  if(!canEdit())return;   // user = len čítanie (klik otvorí inšpektor)
  e.stopPropagation();
  drag={n,moved:false,sx:e.clientX,sy:e.clientY};
  document.addEventListener('mousemove',onDragMove);document.addEventListener('mouseup',onDragUp);
}
function onDragMove(e){
  if(!drag)return;drag.moved=true;
  drag.n.x+=(e.clientX-drag.sx)/S.view.k;drag.n.y+=(e.clientY-drag.sy)/S.view.k;
  drag.sx=e.clientX;drag.sy=e.clientY;render();
}
function onDragUp(){
  if(drag&&drag.moved)api.post('move',{node:drag.n.id,x:Math.round(drag.n.x),y:Math.round(drag.n.y)});
  drag=null;document.removeEventListener('mousemove',onDragMove);document.removeEventListener('mouseup',onDragUp);
}

// ---------- klik na uzol ----------
async function onNodeClick(e,n){
  e.stopPropagation();
  if(S.mode==='delete'){
    if(n.kind==='device'&&confirm(TR('Zmazať uzol')+' „'+(n.dev_name||n.label||n.id)+'"?')){
      await api.post('del_node',{node:n.id});await selectMap(S.mapId);}
    return;
  }
  if(S.mode==='link'){
    if(n.kind!=='device')return;
    if(S.linkFrom===null){S.linkFrom=n.id;S.sel=n.id;render();$('mode').textContent=TR('Spoj: vyber druhý uzol');}
    else if(S.linkFrom!==n.id){await api.post('add_link',{map:S.mapId,from:S.linkFrom,to:n.id});
      S.linkFrom=null;S.sel=null;await selectMap(S.mapId);setMode('link');$('mode').textContent=TR('Spoj: vyber prvý uzol');}
    return;
  }
  if(n.kind==='submap'){selectMap(n.submap_id);switchSection('maps');
    document.querySelector('#sections button[data-sec=maps]').classList.add('active');return;}
  S.sel=n.id;render();openInspector(n.device_id);
}
async function onLinkClick(l){
  if(S.mode==='delete'){ if(confirm(TR('Zmazať spoj?'))){await api.post('del_link',{link:l.id});await selectMap(S.mapId);} return; }
  if(canEdit()) openLinkDialog(l);
}
let curLink=null;
const dashName=s=>TR(({0:'plná',1:'čiarkovaná',2:'bodkovaná',3:'čiara-bodka'}[s]||'plná'));
let linkDevs=null;
async function openLinkDialog(l){
  curLink=l;
  const types=await api.get('link_types');
  $('link-type').innerHTML=types.map(t=>`<option value="${t.id}" ${t.name===l.ltype?'selected':''}>${esc(t.name)} — ${dashName(t.style)}, ${TR('hr.')}${t.thickness}</option>`).join('')||'<option>'+TR('(žiadne typy)')+'</option>';
  if(!linkDevs) linkDevs=await api.get('devices',{q:''});
  $('link-dev').innerHTML='<option value="">'+TR('— žiadne —')+'</option>'+linkDevs.map(d=>`<option value="${d.id}" ${l.snmp_device==d.id?'selected':''}>${esc(d.name)}${d.ip?' ('+d.ip+')':''}</option>`).join('');
  $('link-if').innerHTML = l.snmp_ifindex ? `<option value="${l.snmp_ifindex}" selected>ifIndex ${l.snmp_ifindex} (${TR('aktuálne')})</option>` : '<option value="">—</option>';
  $('link-msg').textContent='';
  $('dlg-link').classList.remove('hidden');
}

// ---------- inspector ----------
async function openInspector(devId){
  if(!devId)return;
  const ins=$('inspector');ins.classList.remove('hidden');
  const d=await api.get('device',{id:devId});
  const dev=d.device||{};
  $('insp-title').textContent=dev.name||TR('Detail');
  const svc=(d.services||[]).map(s=>
    `<div class="svc"><span>${esc(s.name)}</span><span class="pill ${s.status||'unknown'}">${statusSk(s.status)}</span></div>`).join('')||'<div class="empty">'+TR('Žiadne služby')+'</div>';
  $('insp-body').innerHTML=
    `<div class="kv"><span>${TR('Stav')}</span><span class="pill ${dev.status||'unknown'}">${statusSk(dev.status)}</span></div>`+
    `<div class="kv"><span>IP</span><span>${esc(dev.ip||'–')}</span></div>`+
    `<div class="kv"><span>${TR('Typ')}</span><span>${esc(dev.type_name||'–')}</span></div>`+
    `<div class="kv"><span>DNS</span><span>${esc(dev.dns||'–')}</span></div>`+
    `<div class="kv"><span>${TR('Odozva')}</span><span>${dev.rtt!=null?dev.rtt+' ms':'–'}</span></div>`+
    `<div class="kv"><span>${TR('Posl. kontrola')}</span><span>${esc(dev.last_check||'–')}</span></div>`+
    `<h4 style="margin:14px 0 6px;color:var(--muted);font-size:12px">${TR('SLUŽBY')}</h4>${svc}`+
    `<button class="btn" style="margin-top:14px;width:100%" onclick="openDeviceDialog(${devId})">${TR('Otvoriť okno zariadenia')}</button>`;
}
window.openDeviceDialog=openDeviceDialog;
$('insp-close').onclick=()=>$('inspector').classList.add('hidden');

// ---------- klik do plátna ----------
$('canvas').addEventListener('click',async e=>{
  if(!S.mapId)return;const p=toWorld(e);
  if(S.mode==='addnode'){
    const q=prompt(TR('Nájsť zariadenie (názov/IP):'),'');if(q===null)return;
    const list=await api.get('devices',{q});if(!list.length){alert(TR('Nič sa nenašlo.'));return;}
    const pick=prompt(TR('Vyber číslo:')+'\n'+list.slice(0,20).map((d,i)=>`${i+1}) ${d.name} ${d.ip?'('+d.ip+')':''}`).join('\n'),'1');
    const idx=parseInt(pick)-1;if(isNaN(idx)||!list[idx])return;
    await api.post('add_node',{map:S.mapId,device_id:list[idx].id,x:Math.round(p.x),y:Math.round(p.y)});await selectMap(S.mapId);
  }else if(S.mode==='newdevice'){
    const name=prompt(TR('Názov nového zariadenia:'),'');if(!name)return;
    const ip=prompt(TR('IP adresa (nepovinné):'),'')||'';
    await api.post('new_device',{map:S.mapId,name,ip,x:Math.round(p.x),y:Math.round(p.y)});await selectMap(S.mapId);
  }
});

// ---------- pan & zoom ----------
let pan=null;const svgEl=$('canvas');
svgEl.addEventListener('mousedown',e=>{
  if(e.target.tagName==='line')return;
  if(e.target===svgEl||e.target.tagName==='g'){pan={x:e.clientX,y:e.clientY,vx:S.view.x,vy:S.view.y};svgEl.style.cursor='grabbing';}
});
document.addEventListener('mousemove',e=>{if(!pan)return;S.view.x=pan.vx+(e.clientX-pan.x);S.view.y=pan.vy+(e.clientY-pan.y);render();});
document.addEventListener('mouseup',()=>{pan=null;svgEl.style.cursor='grab';});
svgEl.addEventListener('wheel',e=>{
  e.preventDefault();const s=e.deltaY<0?1.1:1/1.1;const p={x:e.offsetX,y:e.offsetY};
  S.view.x=p.x-(p.x-S.view.x)*s;S.view.y=p.y-(p.y-S.view.y)*s;S.view.k*=s;render();
},{passive:false});
function toWorld(e){return{x:(e.offsetX-S.view.x)/S.view.k,y:(e.offsetY-S.view.y)/S.view.k};}
function fitView(){
  const ns=S.data.nodes;if(!ns.length){S.view={x:60,y:60,k:1};return;}
  const xs=ns.map(n=>n.x),ys=ns.map(n=>n.y);
  const minx=Math.min(...xs),maxx=Math.max(...xs),miny=Math.min(...ys),maxy=Math.max(...ys);
  const w=svgEl.clientWidth||900,h=svgEl.clientHeight||600;
  let k=Math.min(w/(maxx-minx+260),h/(maxy-miny+200),1.3); if(w<600)k=Math.max(k,0.55);
  S.view={k,x:w/2-(minx+maxx)/2*k,y:h/2-(miny+maxy)/2*k};
}

// ---------- režimy ----------
function setMode(m){
  S.mode=(S.mode===m)?null:m;S.linkFrom=null;S.sel=null;
  ['btn-link','btn-delete','btn-add-node','btn-new-device'].forEach(id=>$(id).classList.remove('active'));
  const map={link:'btn-link',delete:'btn-delete',addnode:'btn-add-node',newdevice:'btn-new-device'};
  if(S.mode&&map[S.mode])$(map[S.mode]).classList.add('active');
  $('mode').textContent=TR({link:'Spoj: vyber prvý uzol',delete:'Mazací režim – klikni uzol/spoj',
    addnode:'Klikni do mapy pre umiestnenie',newdevice:'Klikni do mapy pre nové zariadenie'}[S.mode]||'');
  render();
}
$('btn-link').onclick=()=>setMode('link');
$('btn-delete').onclick=()=>setMode('delete');
$('btn-add-node').onclick=()=>setMode('addnode');
$('btn-new-device').onclick=()=>setMode('newdevice');
$('btn-refresh').onclick=()=>{selectMap(S.mapId);loadSummary();loadAlerts();};
$('btn-add-map').onclick=async()=>{const name=prompt(TR('Názov novej mapy:'),'');if(!name)return;const r=await api.post('add_map',{name});await loadMaps();if(r.map)selectMap(r.map);};

// ---------- tabuľkové sekcie ----------
function pill(s){return `<span class="pill ${s||'unknown'}">${statusSk(s)}</span>`;}
// ---- generické triediteľné tabuľky (klik na hlavičku triedi) ----
const sortState={};
function ipNum(ip){if(!ip)return -1;const p=String(ip).split('.').map(n=>parseInt(n)||0);return p[0]*16777216+p[1]*65536+p[2]*256+p[3];}
function cellCmp(a,b,k,cols){
  const col=cols.find(c=>c.key===k), t=col&&col.type;
  if(t==='ip')return ipNum(a[k])-ipNum(b[k]);
  if(t==='status'){const o={up:0,pending:1,down:2,unknown:3};return (o[a[k]]??9)-(o[b[k]]??9);}
  const va=(a[k]==null?'':String(a[k])).toLowerCase(), vb=(b[k]==null?'':String(b[k])).toLowerCase();
  return va<vb?-1:va>vb?1:0;
}
function buildTable(tblId,rows,cols,emptyMsg){
  const st=sortState[tblId]||(sortState[tblId]={col:cols[0].key,dir:1});
  const sorted=[...rows].sort((a,b)=>cellCmp(a,b,st.col,cols)*st.dir);
  const head=cols.map(c=>`<th class="sortable" data-col="${c.key}">${c.label}${st.col===c.key?`<span class="arr">${st.dir>0?'▲':'▼'}</span>`:''}</th>`).join('');
  const body=sorted.length?sorted.map(r=>'<tr>'+cols.map(c=>`<td>${c.render?c.render(r):esc(r[c.key]??'')}</td>`).join('')+'</tr>').join('')
    :`<tr><td colspan="${cols.length}" class="empty">${emptyMsg||TR('Žiadne dáta')}</td></tr>`;
  const el=$(tblId); el.innerHTML=`<thead><tr>${head}</tr></thead><tbody>${body}</tbody>`;
  el.querySelectorAll('th.sortable').forEach(th=>th.onclick=()=>{const c=th.dataset.col;if(st.col===c)st.dir*=-1;else{st.col=c;st.dir=1;}buildTable(tblId,rows,cols,emptyMsg);});
}
async function loadDevices(){
  const d=await api.get('devices',{q:''});
  buildTable('tbl-devices',d,[
    {key:'name',label:TR('Názov')},{key:'ip',label:'IP',type:'ip'},
    {key:'type_name',label:TR('Typ')},{key:'status',label:TR('Stav'),type:'status',render:x=>pill(x.status)}]);
}
async function loadServices(){
  const d=await api.get('services');
  buildTable('tbl-services',d,[
    {key:'name',label:TR('Služba')},{key:'dev_name',label:TR('Zariadenie')},
    {key:'ip',label:'IP',type:'ip'},{key:'status',label:TR('Stav'),type:'status',render:x=>pill(x.status)}]);
}
async function loadFaults(){
  const d=await api.get('faults');
  buildTable('tbl-faults',d,[
    {key:'name',label:TR('Zariadenie')},{key:'ip',label:'IP',type:'ip'},
    {key:'last_check',label:TR('Od')}],TR('Žiadne poruchy')+' 🎉');
}
async function loadEvents(){
  const d=await api.get('events');
  buildTable('tbl-events',d,[
    {key:'ts',label:TR('Čas')},{key:'device_name',label:TR('Zariadenie')},
    {key:'ip',label:'IP',type:'ip'},{key:'status',label:TR('Stav'),type:'status',render:x=>pill(x.status)}],
    TR('Zatiaľ žiadne udalosti (spustí sa monitoring)'));
}

// ---- GRAFY ----
let graphState={src:null,range:'hour'};
async function loadGraphs(){
  const srcs=await api.get('graph_sources');
  $('graph-src').innerHTML=srcs.length?srcs.map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join(''):'<option value="">'+TR('(žiadne SNMP zdroje – priraď linke rozhranie)')+'</option>';
  if((!graphState.src||!srcs.find(x=>x.id==graphState.src))&&srcs.length)graphState.src=srcs[0].id;
  if(graphState.src)$('graph-src').value=graphState.src;
  $('graph-src').onchange=()=>{graphState.src=$('graph-src').value;drawGraph();};
  document.querySelectorAll('#graph-ranges button').forEach(b=>b.onclick=()=>{graphState.range=b.dataset.r;document.querySelectorAll('#graph-ranges button').forEach(x=>x.classList.toggle('active',x.dataset.r===graphState.range));drawGraph();});
  drawGraph();
}
async function drawGraph(){
  const cv=$('graph-canvas'), em=$('graph-empty');
  if(!graphState.src){cv.style.display='none';em.textContent=TR('Žiadny SNMP zdroj. Priraď linke zariadenie a rozhranie cez Upraviť spoj.');return;}
  const d=await api.get('graph_data',{id:graphState.src,range:graphState.range});
  const pts=d.points||[];
  if(pts.length<2){cv.style.display='none';em.textContent=TR('Zatiaľ málo dát – graf sa napĺňa ako beží SNMP poller.');return;}
  cv.style.display='block';em.textContent='';
  drawTrafficChart(cv,pts,graphState.range);
}
function drawTrafficChart(cv,pts,range){
  const rect=cv.getBoundingClientRect(); const dpr=window.devicePixelRatio||1;
  cv.width=rect.width*dpr; cv.height=rect.height*dpr;
  const ctx=cv.getContext('2d'); ctx.setTransform(dpr,0,0,dpr,0,0);
  const W=rect.width,H=rect.height,padL=66,padB=28,padT=22,padR=14;
  ctx.clearRect(0,0,W,H);
  const cs=getComputedStyle(document.documentElement);
  const cText=(cs.getPropertyValue('--muted')||'#889').trim(), cGrid=(cs.getPropertyValue('--border')||'#334').trim();
  const t0=pts[0].t, t1=pts[pts.length-1].t;
  let max=0; pts.forEach(p=>{max=Math.max(max,p.rx||0,p.tx||0);}); if(max<1000)max=1000;
  const X=t=>padL+(t-t0)/((t1-t0)||1)*(W-padL-padR);
  const Y=v=>H-padB-(v/max)*(H-padB-padT);
  ctx.strokeStyle=cGrid;ctx.fillStyle=cText;ctx.font='10px sans-serif';ctx.lineWidth=1;
  for(let k=0;k<=4;k++){const y=H-padB-k/4*(H-padB-padT);ctx.beginPath();ctx.moveTo(padL,y);ctx.lineTo(W-padR,y);ctx.stroke();ctx.fillText(fmtBps(max*k/4),6,y+3);}
  const fmtT=t=>{const d=new Date(t*1000);const p=n=>String(n).padStart(2,'0');
    return (range==='hour'||range==='day')?p(d.getHours())+':'+p(d.getMinutes()):p(d.getDate())+'.'+p(d.getMonth()+1)+'.';};
  for(let k=0;k<=4;k++){const t=t0+k/4*(t1-t0);const x=X(t);ctx.fillText(fmtT(t),x-14,H-9);}
  const series=(key,color,fill)=>{
    ctx.beginPath();ctx.moveTo(X(pts[0].t),Y(pts[0][key]||0));
    for(let i=1;i<pts.length;i++)ctx.lineTo(X(pts[i].t),Y(pts[i][key]||0));
    const lastX=X(pts[pts.length-1].t);
    ctx.strokeStyle=color;ctx.lineWidth=1.7;ctx.stroke();
    ctx.lineTo(lastX,H-padB);ctx.lineTo(X(pts[0].t),H-padB);ctx.closePath();ctx.fillStyle=fill;ctx.fill();
  };
  series('rx','#3b6fd6','rgba(59,111,214,.18)');
  series('tx','#e0304f','rgba(224,48,79,.16)');
  ctx.fillStyle='#3b6fd6';ctx.fillRect(padL,6,11,11);ctx.fillStyle=cText;ctx.fillText(TR('Rx (príjem)'),padL+16,15);
  ctx.fillStyle='#e0304f';ctx.fillRect(padL+92,6,11,11);ctx.fillStyle=cText;ctx.fillText(TR('Tx (odosielanie)'),padL+108,15);
}
const roleSk=new Proxy({user:'Používateľ',admin:'Admin',administrator:'Administrátor'},{get:(o,k)=>TR(o[k]!=null?o[k]:String(k))});
const SNMPIC='<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12a7 7 0 0 1 14 0M8.5 12a3.5 3.5 0 0 1 7 0"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/><path d="M2 9a11 11 0 0 1 20 0"/></svg>';
async function loadSettings(){
  const me=await api.get('whoami'); S.role=me.role; S.user=me.user; S._me=me;
  S._srank={user:1,admin:2,administrator:3}[me.role]||1;
  let tabs=`<button data-sp="appearance">${UI.palette}<span>${TR('Vzhľad')}</span></button>`+
           `<button data-sp="password">${UI.key}<span>${TR('Heslo')}</span></button>`;
  if(S._srank>=2) tabs+=`<button data-sp="users">${UI.users}<span>${TR('Používatelia')}</span></button>`;
  if(S._srank>=3) tabs+=`<button data-sp="telegram">${UI.send}<span>Telegram</span></button>`;
  if(S._srank>=3) tabs+=`<button data-sp="snmp">${SNMPIC}<span>SNMP</span></button>`;
  if(S._srank>=3) tabs+=`<button data-sp="backup">${UI.db}<span>${TR('Zálohy a import')}</span></button>`;
  $('settings-body').innerHTML=`<div class="settings-subnav">${tabs}</div><div id="settings-panel"></div>`;
  document.querySelectorAll('.settings-subnav button').forEach(b=>b.onclick=()=>openSettingsPanel(b.dataset.sp));
  openSettingsPanel('appearance');
}
function openSettingsPanel(name){
  document.querySelectorAll('.settings-subnav button').forEach(b=>b.classList.toggle('active',b.dataset.sp===name));
  ({appearance:panelAppearance,password:panelPassword,users:panelUsers,telegram:panelTelegram,snmp:panelSnmp,backup:panelBackup}[name]||panelAppearance)($('settings-panel'));
}
function panelAppearance(p){
  p.innerHTML=`<div class="settings-card"><h3>${UI.palette} ${TR('Vzhľad')}</h3>
    <p style=\"color:var(--muted);font-size:13px;margin:0 0 14px\">${TR('@help_appearance')}</p>
    <div class="theme-seg">
      <button data-theme="auto">${UI.mon}<span>${TR('Automaticky')}</span></button>
      <button data-theme="light">${UI.sun}<span>${TR('Svetlá')}</span></button>
      <button data-theme="dark">${UI.moon}<span>${TR('Tmavá')}</span></button></div></div>\n    <div class="settings-card"><h3>\uD83C\uDF10 ${TR('Jazyk')}</h3><select class="lang-select" id="lang-set" style="max-width:240px"></select></div>`;
  p.querySelectorAll('.theme-seg button').forEach(b=>{b.classList.toggle('active',b.dataset.theme===getTheme());b.onclick=()=>setTheme(b.dataset.theme);});
  if(window.I18N)I18N.buildSelect(document.getElementById('lang-set'));
}
function panelPassword(p){
  const me=S._me||{user:'',role:'user'};
  p.innerHTML=`<div class="settings-card"><h3>${UI.key} ${TR('Zmena môjho hesla')}</h3>
    <div style="color:var(--muted);font-size:12px;margin-bottom:12px">${TR('Prihlásený:')} <b>${esc(me.user)}</b> · ${TR('rola:')} <b>${roleSk[me.role]||me.role}</b></div>
    <label>${TR('Staré heslo')}<input type="password" id="pw-old"></label>
    <label>${TR('Nové heslo')}<input type="password" id="pw-new"></label>
    <button class="btn" id="pw-save">${TR('Uložiť')}</button>
    <div id="pw-msg" style="margin-top:10px;font-size:13px"></div></div>`;
  $('pw-save').onclick=async()=>{
    const r=await api.post('change_password',{old:$('pw-old').value,new:$('pw-new').value});
    $('pw-msg').innerHTML=r.ok?'<span class="st-up">'+TR('Heslo zmenené.')+'</span>':'<span class="st-down">'+esc(r.error||TR('Chyba'))+'</span>';
  };
}
function panelUsers(p){
  const rank=S._srank;
  p.innerHTML=`<div class="settings-card"><h3>${UI.users} ${TR('Používatelia')}</h3>
    <div id="users-tbl"></div>
    <h4 style="margin:16px 0 8px;color:var(--muted);font-size:12px">${TR('PRIDAŤ POUŽÍVATEĽA')}</h4>
    <div class="user-add-grid">
      <input id="nu-name" placeholder="${TR('Meno')}">
      <input id="nu-pass" type="password" placeholder="${TR('Heslo')}">
      <select id="nu-role">${rank>=3?('<option value="user">'+TR('Používateľ')+'</option><option value="admin">Admin</option><option value="administrator">'+TR('Administrátor')+'</option>'):('<option value="user">'+TR('Používateľ')+'</option>')}</select>
      <button class="btn" id="nu-add">${TR('Pridať')}</button></div>
    <div id="nu-msg" style="margin-top:8px;font-size:13px"></div></div>`;
  loadUsers(rank);
  $('nu-add').onclick=async()=>{
    const r=await api.post('user_add',{username:$('nu-name').value,password:$('nu-pass').value,role:$('nu-role').value});
    $('nu-msg').innerHTML=r.ok?'<span class="st-up">'+TR('Pridaný.')+'</span>':'<span class="st-down">'+esc(r.error||TR('Chyba'))+'</span>';
    if(r.ok){$('nu-name').value='';$('nu-pass').value='';loadUsers(rank);}
  };
}
async function panelTelegram(p){
  const s=await api.get('get_settings');
  p.innerHTML=`<div class="settings-card"><h3>${UI.send} ${TR('Telegram notifikácie')}</h3>
    <p style=\"color:var(--muted);font-size:13px;margin:0 0 14px\">${TR('@help_telegram')}</p>
    <label class="chk"><input type="checkbox" id="tg-en" ${s.tg_enabled==='1'?'checked':''}> ${TR('Zapnúť Telegram notifikácie')}</label>
    <label>${TR('Bot token')}<input id="tg-token" value="${esc(s.tg_token)}" placeholder="123456789:ABCdef..."></label>
    <label>${TR('Chat ID')}<input id="tg-chat" value="${esc(s.tg_chat)}" placeholder="-1001234567890"></label>
    <div class="modal-actions"><button class="btn ghost" id="tg-test">${TR('Poslať test')}</button><button class="btn" id="tg-save">${TR('Uložiť')}</button></div>
    <div id="tg-msg" style="margin-top:10px;font-size:13px"></div></div>`;
  $('tg-save').onclick=async()=>{
    const r=await api.post('save_settings',{tg_enabled:$('tg-en').checked?1:0,tg_token:$('tg-token').value,tg_chat:$('tg-chat').value});
    $('tg-msg').innerHTML=r.ok?'<span class="st-up">'+TR('Uložené.')+'</span>':'<span class="st-down">'+esc(r.error||TR('Chyba'))+'</span>';};
  $('tg-test').onclick=async()=>{
    const tok=$('tg-token').value.trim(), chat=$('tg-chat').value.trim();
    if(!tok.includes(':')){ $('tg-msg').innerHTML='<span class="st-down">'+TR('@tg_incomplete')+'</span>'; return; }
    $('tg-msg').textContent=TR('Posielam test…');
    const r=await api.post('test_telegram',{tg_token:tok,tg_chat:chat});
    if(r.ok){ $('tg-msg').innerHTML='<span class="st-up">'+TR('@tg_ok')+'</span>'; return; }
    let hint=esc(r.error||TR('Chyba'));
    if(/404|Not Found/i.test(r.error||'')) hint=TR('@tg_err_token');
    else if(/chat not found|400/i.test(r.error||'')) hint=TR('@tg_err_chat');
    $('tg-msg').innerHTML='<span class="st-down">'+hint+'</span>';};
}
async function panelSnmp(p){
  const st=await api.get('get_settings');
  const profs=await api.get('snmp_profiles_list');
  const rows=profs.map(pr=>`<div class="svc-row" data-id="${pr.id}">
    <span class="grow">${esc(pr.name)} <span style="color:var(--muted)">${pr.version==1?'v2c':(pr.version==0?'v1':'—')} · ${esc(pr.community||'')} · :${pr.port}</span></span>
    <button class="mini sp-edit">${TR('upraviť')}</button><button class="mini sp-del">${TR('zmazať')}</button></div>`).join('')||'<div class="empty">'+TR('Žiadne profily')+'</div>';
  p.innerHTML=`<div class="settings-card"><h3>${SNMPIC} ${TR('Meranie toku')}</h3>
    <div class="form-grid">
      <label>${TR('Interval merania SNMP (s)')}<input id="iv-snmp" type="number" min="3" value="${esc(st.snmp_interval||'30')}"></label>
      <label>${TR('Obnova mapy v prehliadači (s)')}<input id="iv-map" type="number" min="2" value="${esc(st.map_refresh||'10')}"></label>
    </div>
    <div class="modal-actions"><button class="btn" id="iv-save">${TR('Uložiť intervaly')}</button></div>
    <div id="iv-msg" style="font-size:13px;margin-top:8px"></div></div>
    <div class="settings-card"><h3>${SNMPIC} ${TR('SNMP profily')}</h3>
    <p style=\"color:var(--muted);font-size:13px;margin:0 0 12px\">${TR('@help_snmp_profiles')}</p>
    <div id="sp-list">${rows}</div>
    <h4 style="margin:16px 0 8px;color:var(--muted);font-size:12px">${TR('PRIDAŤ / UPRAVIŤ PROFIL')}</h4>
    <input type="hidden" id="sp-id">
    <div class="user-add-grid">
      <input id="sp-name" placeholder="${TR('Názov (napr. v2-public)')}">
      <input id="sp-comm" placeholder="${TR('Community')}" value="public">
      <select id="sp-ver"><option value="0">SNMP v1</option><option value="1" selected>SNMP v2c</option><option value="2">SNMP v3</option></select>
      <input id="sp-port" placeholder="${TR('Port')}" value="161">
    </div>
    <div id="sp-v3" style="display:none">
      <h4 style="margin:14px 0 8px;color:var(--muted);font-size:12px">${TR('SNMP v3 – MENO A HESLO')}</h4>
      <div class="user-add-grid">
        <input id="sp-sec" placeholder="${TR('Meno (security name)')}">
        <select id="sp-authp"><option value="MD5">Auth MD5</option><option value="SHA">Auth SHA</option></select>
        <input id="sp-auth" type="password" placeholder="${TR('Auth heslo')}">
        <select id="sp-privp"><option value="DES">Priv DES</option><option value="AES">Priv AES</option></select>
        <input id="sp-priv" type="password" placeholder="${TR('Priv heslo (šifrovanie)')}">
      </div>
    </div>
    <div class="modal-actions"><button class="btn ghost" id="sp-clear">${TR('Nový')}</button><button class="btn" id="sp-save">${TR('Uložiť profil')}</button></div>
    <div id="sp-msg" style="margin-top:8px;font-size:13px"></div></div>`;
  const v3vis=()=>{$('sp-v3').style.display=$('sp-ver').value==='2'?'block':'none';};
  const fill=(pr)=>{$('sp-id').value=pr?pr.id:'';$('sp-name').value=pr?pr.name:'';$('sp-comm').value=pr?(pr.community||''):'public';$('sp-ver').value=pr?pr.version:'1';$('sp-port').value=pr?pr.port:'161';
    $('sp-sec').value=pr?(pr.sec_name||''):'';$('sp-auth').value=pr?(pr.auth_pass||''):'';$('sp-priv').value=pr?(pr.priv_pass||''):'';$('sp-authp').value=pr?(pr.auth_proto||'MD5'):'MD5';$('sp-privp').value=pr?(pr.priv_proto||'DES'):'DES';v3vis();};
  $('iv-save').onclick=async()=>{await api.post('save_settings',{snmp_interval:$('iv-snmp').value,map_refresh:$('iv-map').value});$('iv-msg').innerHTML='<span class="st-up">'+TR('@iv_saved')+'</span>';startLive();};
  $('sp-ver').onchange=v3vis; v3vis();
  $('sp-save').onclick=async()=>{
    if(!$('sp-name').value.trim()){$('sp-msg').innerHTML='<span class="st-down">'+TR('Zadaj názov.')+'</span>';return;}
    const r=await api.post('snmp_profile_save',{id:$('sp-id').value,name:$('sp-name').value,community:$('sp-comm').value,version:$('sp-ver').value,port:$('sp-port').value,
      sec_name:$('sp-sec').value,auth_pass:$('sp-auth').value,priv_pass:$('sp-priv').value,auth_proto:$('sp-authp').value,priv_proto:$('sp-privp').value});
    if(r.ok){panelSnmp(p);} else {$('sp-msg').innerHTML='<span class="st-down">'+esc(r.error||TR('Chyba'))+'</span>';}
  };
  $('sp-clear').onclick=()=>fill(null);
  p.querySelectorAll('.sp-edit').forEach(b=>b.onclick=()=>{const id=b.closest('.svc-row').dataset.id;fill(profs.find(x=>x.id==id));});
  p.querySelectorAll('.sp-del').forEach(b=>b.onclick=async()=>{const id=b.closest('.svc-row').dataset.id;if(confirm(TR('Zmazať profil?'))){await api.post('snmp_profile_delete',{id});panelSnmp(p);}});
}
function panelBackup(p){
  p.innerHTML=`<div class="settings-card"><h3>${UI.db} ${TR('Záloha, obnova a import')}</h3>
    <p style=\"color:var(--muted);font-size:13px;margin:0 0 12px\">${TR('@help_backup')}</p>
    <a class="btn" href="api.php?action=backup">⬇ ${TR('Stiahnuť zálohu (.db)')}</a>
    <div style="margin-top:16px"><label>${TR('Obnoviť zo zálohy (.db)')}<input type="file" id="rf" accept=".db"></label>
      <button class="btn ghost" id="rf-btn">${TR('Obnoviť databázu')}</button></div>
    <div style="margin-top:14px"><label>${TR('Import z Dude (dude.db)')}<input type="file" id="imf" accept=".db"></label>
      <button class="btn ghost" id="imf-btn">${TR('Importovať z Dude')}</button></div>
    <div id="br-msg" style="margin-top:12px;font-size:13px"></div></div>`;
  $('rf-btn').onclick=()=>doUpload('restore',$('rf'),TR('@restored'));
  $('imf-btn').onclick=()=>doUpload('import_dude',$('imf'),TR('@imported'));
}
async function loadUsers(rank){
  const us=await api.get('users_list');
  const rows=us.map(u=>{
    let actions='';
    if(rank>=3){
      actions=`<select class="mini u-role" data-id="${u.id}">
        ${['user','admin','administrator'].map(r=>`<option value="${r}" ${r===u.role?'selected':''}>${roleSk[r]}</option>`).join('')}</select>
        <button class="mini u-pw" data-id="${u.id}">${TR('heslo')}</button>`;
    }
    actions+=`<button class="mini u-del" data-id="${u.id}">${TR('zmazať')}</button>`;
    return `<div class="svc-row"><span class="grow">${esc(u.username)} <span style="color:var(--muted)">${roleSk[u.role]||u.role}</span></span>${actions}</div>`;
  }).join('')||'<div class="empty">'+TR('Žiadni používatelia')+'</div>';
  $('users-tbl').innerHTML=rows;
  document.querySelectorAll('.u-del').forEach(b=>b.onclick=async()=>{
    if(confirm(TR('Zmazať používateľa?'))){const r=await api.post('user_delete',{id:b.dataset.id});if(!r.ok)alert(r.error);loadUsers(rank);}});
  document.querySelectorAll('.u-role').forEach(sel=>sel.onchange=async()=>{
    const r=await api.post('user_set_role',{id:sel.dataset.id,role:sel.value});if(!r.ok){alert(r.error);loadUsers(rank);}});
  document.querySelectorAll('.u-pw').forEach(b=>b.onclick=async()=>{
    const np=prompt(TR('Nové heslo pre používateľa:'));if(!np)return;
    const r=await api.post('user_reset_password',{id:b.dataset.id,new:np});alert(r.ok?TR('Heslo zmenené.'):r.error);});
}
async function doUpload(action,input,okMsg){
  const f=input.files[0]; if(!f){$('br-msg').innerHTML='<span class="st-down">'+TR('Vyber súbor.')+'</span>';return;}
  if(action==='restore'&&!confirm(TR('Naozaj nahradiť aktuálnu databázu zálohou?')))return;
  if(action==='import_dude'&&!confirm(TR('Import prepíše mapy/zariadenia dátami z dude.db. Pokračovať?')))return;
  $('br-msg').textContent=TR('Nahrávam…');
  const fd=new FormData();fd.append('file',f);
  const r=await fetch('api.php?action='+action,{method:'POST',body:fd}).then(x=>x.json()).catch(()=>({error:TR('Chyba prenosu')}));
  $('br-msg').innerHTML=r.ok?'<span class="st-up">'+okMsg+'</span>':'<span class="st-down">'+esc(r.error||TR('Chyba'))+'</span>';
}

// ---------- štart ----------
loadSummary();loadMaps();loadAlerts();
api.get('whoami').then(w=>{S.role=w.role;S.user=w.user;document.body.dataset.role=w.role||'user';}).catch(()=>{});

// ---- TÉMA (svetlá/tmavá/auto) ----
const THEME_ICON={dark:UI.moon,light:UI.sun,auto:UI.mon};
function getTheme(){try{return localStorage.getItem('np-theme')||'auto';}catch(e){return 'auto';}}
function setTheme(t){
  document.documentElement.dataset.theme=t;
  try{localStorage.setItem('np-theme',t);}catch(e){}
  const b=$('theme-toggle'); if(b)b.innerHTML=THEME_ICON[t]||UI.mon;
  document.querySelectorAll('.theme-seg button').forEach(x=>x.classList.toggle('active',x.dataset.theme===t));
}
(function(){const b=$('theme-toggle'); if(b){b.innerHTML=THEME_ICON[getTheme()]||UI.mon;
  b.onclick=()=>{const cur=getTheme();setTheme({auto:'light',light:'dark',dark:'auto'}[cur]);};}})();

// ---- ŽIVÉ prefarbovanie (bez reloadu) ----
async function liveTick(){
  try{
    loadSummary(); loadAlerts();
    if(S.section==='maps'&&S.mapId&&S.data&&S.data.nodes){
      // obnov celú mapu (stavy + tok) so zachovaním pohľadu a výberu
      const view={...S.view}, sel=S.sel;
      const d=await api.get('map',{id:S.mapId});
      S.data=d; S.view=view; S.sel=sel;
      render();
      // odznaky výpadkov v ľavom zozname
      const maps=await api.get('maps');
      maps.forEach(m=>{const li=document.querySelector(`#map-list li[data-id="${m.id}"]`);if(!li)return;
        const el=li.querySelector('.badge,.cnt');if(!el)return;
        if(m.down>0){el.className='badge';el.textContent=m.down;}else{el.className='cnt';el.textContent=m.nodes;}});
    }
  }catch(e){}
}
let liveTimer=null;
async function startLive(){
  let iv=10; try{const c=await api.get('client_config'); iv=Math.max(2,+c.map_refresh||10);}catch(e){}
  if(liveTimer)clearInterval(liveTimer);
  liveTimer=setInterval(liveTick, iv*1000);
}
startLive();

// ================= OKNO ZARIADENIA =================
let curDev=null, curDevData=null;
async function openDeviceDialog(devId){
  if(!devId)return;curDev=devId;
  $('dlg-device').classList.remove('hidden');
  showTab('obecne');
  await loadDeviceDialog();
}
async function loadDeviceDialog(){
  const d=await api.get('device',{id:curDev});curDevData=d;
  const dev=d.device||{};
  $('dlg-title').textContent=dev.name||TR('Zariadenie');
  // Obecné (editovateľné)
  const types=(await api.get('device_types'));
  const opts=types.map(t=>`<option value="${t.id}" ${t.id==dev.type_id?'selected':''}>${esc(t.name)}</option>`).join('');
  $('tab-obecne').innerHTML=`
    <div class="form-grid">
      <label>${TR('Názov')}<input id="e-name" value="${esc(dev.name||'')}"></label>
      <label>${TR('Adresa (IP)')}<input id="e-ip" value="${esc(dev.ip||'')}"></label>
      <label>${TR('DNS názov')}<input id="e-dns" value="${esc(dev.dns||'')}"></label>
      <label>${TR('Typ')}<select id="e-type">${opts}</select></label>
      <label>${TR('Používateľ')}<input id="e-user" value="${esc(dev.username||'')}"></label>
      <label>${TR('Heslo')}<input id="e-pass" type="password" value="${esc(dev.password||'')}"></label>
      <label>${TR('Stav')}<input value="${statusSk(dev.status)}" disabled></label>
    </div>
    <label class="chk" style="margin:4px 2px 12px"><input type="checkbox" id="e-mon" ${String(dev.monitored)==='0'?'':'checked'}> ${TR('Monitorovať zariadenie')} <span style="color:var(--muted)">${TR('(vypnuté = sivé)')}</span></label>
    <div class="modal-actions"><button class="btn" id="e-save">${TR('Uložiť')}</button></div>
    <div id="e-msg" style="margin-top:8px;font-size:13px"></div>`;
  $('e-save').onclick=saveDevice;
  if(!canEdit()){
    document.querySelectorAll('#tab-obecne input,#tab-obecne select').forEach(x=>x.disabled=true);
    const es=$('e-save'); if(es)es.style.display='none';
  }
  // Služby
  renderServices(d.services);
  // Poruchy
  const ou=d.outages||[];
  $('tab-poruchy').innerHTML=`<table><thead><tr><th>${TR('Stav')}</th><th>${TR('Od')}</th><th>${TR('Do')}</th><th>${TR('Trvanie')}</th><th>${TR('Služba')}</th></tr></thead><tbody>`+
    (ou.length?ou.map(o=>`<tr><td>${o.ended?('<span class="pill up">'+TR('obnovené')+'</span>'):('<span class="pill down">'+TR('trvá')+'</span>')}</td>
      <td>${esc(o.started)}</td><td>${esc(o.ended||'–')}</td><td>${o.duration?fmtDur(o.duration):'–'}</td><td>${esc(o.service||'')}</td></tr>`).join('')
      :`<tr><td colspan="5" class="empty">${TR('Žiadne zaznamenané výpadky')}</td></tr>`)+`</tbody>`;
  // História (graf)
  drawRtt(d.history||[]);
  // Nástroje
  $('tab-nastroje').innerHTML=`
    <button class="btn" id="tool-ping">▶ ${TR('Ping')} ${esc(dev.ip||'')}</button>
    <button class="btn ghost" id="tool-refresh">↻ ${TR('Znovu sondovať')}</button>
    <pre class="tool-out" id="tool-out">${TR('Klikni Ping…')}</pre>`;
  $('tool-ping').onclick=async()=>{
    $('tool-out').textContent=TR('Pingujem…');
    const r=await api.get('ping_now',{ip:dev.ip||''});
    $('tool-out').textContent=(r.output||r.error||'')+'\n\n'+(r.ok?'✓ '+TR('Dostupné'):'✗ '+TR('Nedostupné'));
  };
  $('tool-refresh').onclick=()=>loadDeviceDialog();

  // ---- karta SNMP ----
  const profs=await api.get('snmp_profiles_list');
  $('tab-snmp').innerHTML=`
    <p style=\"color:var(--muted);font-size:13px;margin:0 0 12px\">${TR('@help_snmp_dev')}</p>
    <div class="form-grid">
      <label>${TR('SNMP profil')}<select id="e-snmp" ${canEdit()?'':'disabled'}>
        <option value="">${TR('— žiadny (bez SNMP) —')}</option>
        ${profs.map(p=>`<option value="${p.id}" ${dev.snmp_profile==p.id?'selected':''}>${esc(p.name)} (${p.version==2?'v3':(p.version==1?'v2c':(p.version==0?'v1':'—'))})</option>`).join('')}
      </select></label>
    </div>
    ${canEdit()?'<div class="modal-actions"><button class="btn" id="e-snmp-save">'+TR('Uložiť')+'</button></div>':''}
    <div style="margin-top:6px;font-size:12px;color:var(--muted)">${TR('Profily pridáš/upravíš v Nastavenia → SNMP.')}</div>
    <div id="e-snmp-msg" style="margin-top:8px;font-size:13px"></div>`;
  if(canEdit()){ const b=$('e-snmp-save'); if(b) b.onclick=async()=>{ await saveDevice(); const m=$('e-snmp-msg'); if(m) m.innerHTML='<span class="st-up">'+TR('Uložené.')+'</span>'; }; }
}
async function saveDevice(){
  const r=await api.post('update_device',{id:curDev,name:$('e-name').value,ip:$('e-ip').value,
    dns:$('e-dns').value,type_id:$('e-type').value,username:$('e-user').value,
    monitored:$('e-mon').checked?1:0, snmp_profile:$('e-snmp')?$('e-snmp').value:'',
    password:$('e-pass')?$('e-pass').value:''});
  const em=$('e-msg'); if(em) em.innerHTML=r.ok?'<span class="st-up">'+TR('Uložené.')+'</span>':'<span class="st-down">'+esc(r.error||TR('Chyba'))+'</span>';
  if(r.ok){ loadMaps(); if(S.mapId) await selectMap(S.mapId);
    if(!$('inspector').classList.contains('hidden')) openInspector(curDev); }
}
function renderServices(svcs){
  const rows=(svcs||[]).map(s=>`<div class="svc-row" data-sid="${s.id}">
    <span class="pill ${s.status||'unknown'}">${statusSk(s.status)}</span>
    <span class="grow">${esc(s.name)} <span style="color:var(--muted)">${s.ptype||''}${s.port?':'+s.port:''}</span></span>
    <button class="mini t-toggle" data-on="${(+s.enabled)?0:1}">${(+s.enabled)?TR('vypnúť'):TR('zapnúť')}</button>
    <button class="mini t-del">${TR('zmazať')}</button></div>`).join('')||'<div class="empty">'+TR('Žiadne služby')+'</div>';
  $('tab-sluzby').innerHTML=rows+`
    <div class="add-svc-bar"><select id="svc-probe"></select><button class="btn" id="svc-add">＋ ${TR('Pridať sondu')}</button></div>`;
  api.get('probes').then(ps=>{$('svc-probe').innerHTML=ps.map(p=>`<option value="${p.id}">${esc(p.name)} (${p.type}${p.port?':'+p.port:''})</option>`).join('');});
  $('svc-add').onclick=async()=>{await api.post('add_service',{device_id:curDev,probe_id:$('svc-probe').value});loadDeviceDialog();};
  $('tab-sluzby').querySelectorAll('.t-toggle').forEach(b=>b.onclick=async e=>{
    const row=e.target.closest('.svc-row');const on=e.target.dataset.on==='1';
    await api.post('toggle_service',{service:row.dataset.sid,enabled:on?1:0});loadDeviceDialog();});
  $('tab-sluzby').querySelectorAll('.t-del').forEach(b=>b.onclick=async e=>{
    const row=e.target.closest('.svc-row');
    if(confirm(TR('Zmazať službu?'))){await api.post('del_service',{service:row.dataset.sid});loadDeviceDialog();}});
  if(!canEdit()) $('tab-sluzby').querySelectorAll('.t-toggle,.t-del,.add-svc-bar').forEach(x=>x.style.display='none');
}
function fmtDur(s){s=+s;const h=Math.floor(s/3600),m=Math.floor(s%3600/60),ss=s%60;
  return (h?h+'h ':'')+(m?m+'m ':'')+ss+'s';}
// graf odozvy (RTT) na canvas
function drawRtt(hist){
  const cv=$('rtt-chart');const em=$('hist-empty');
  const pts=hist.filter(h=>h.rtt!=null).map(h=>+h.rtt);
  if(pts.length<2){cv.style.display='none';em.textContent=TR('Zatiaľ málo dát pre graf (spustí sa monitoringom).');return;}
  cv.style.display='block';em.textContent='';
  const ctx=cv.getContext('2d');const W=cv.width,H=cv.height,pad=34;
  ctx.clearRect(0,0,W,H);
  const max=Math.max(10,Math.max(...pts));const n=pts.length;
  const X=i=>pad+i/(n-1)*(W-pad-10), Y=v=>H-pad-v/max*(H-pad-14);
  // mriežka
  ctx.strokeStyle='#2a3247';ctx.fillStyle='#8b95ab';ctx.font='10px sans-serif';ctx.lineWidth=1;
  for(let k=0;k<=4;k++){const y=H-pad-k/4*(H-pad-14);ctx.beginPath();ctx.moveTo(pad,y);ctx.lineTo(W-10,y);ctx.stroke();
    ctx.fillText(Math.round(max*k/4)+' ms',4,y+3);}
  // area
  ctx.beginPath();ctx.moveTo(X(0),Y(pts[0]));
  for(let i=1;i<n;i++)ctx.lineTo(X(i),Y(pts[i]));
  ctx.lineTo(X(n-1),H-pad);ctx.lineTo(X(0),H-pad);ctx.closePath();
  const grd=ctx.createLinearGradient(0,0,0,H);grd.addColorStop(0,'rgba(139,124,240,.55)');grd.addColorStop(1,'rgba(139,124,240,.04)');
  ctx.fillStyle=grd;ctx.fill();
  // line
  ctx.beginPath();ctx.moveTo(X(0),Y(pts[0]));
  for(let i=1;i<n;i++)ctx.lineTo(X(i),Y(pts[i]));
  ctx.strokeStyle='#8b7cf0';ctx.lineWidth=1.8;ctx.stroke();
}
// taby
document.querySelectorAll('.tabs button').forEach(b=>b.onclick=()=>showTab(b.dataset.tab));
function showTab(t){
  document.querySelectorAll('.tabs button').forEach(b=>b.classList.toggle('active',b.dataset.tab===t));
  document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
  $('tab-'+t).classList.add('active');
}
$('dlg-close').onclick=()=>$('dlg-device').classList.add('hidden');
$('link-close').onclick=()=>$('dlg-link').classList.add('hidden');
$('dlg-link').addEventListener('click',e=>{if(e.target===$('dlg-link'))$('dlg-link').classList.add('hidden');});
$('link-if-load').onclick=async()=>{
  const dev=$('link-dev').value; if(!dev){$('link-msg').innerHTML='<span class="st-down">'+TR('Najprv vyber zariadenie.')+'</span>';return;}
  $('link-msg').textContent=TR('Načítavam rozhrania cez SNMP…');
  const r=await api.get('snmp_interfaces',{device_id:dev});
  if(r.error){$('link-msg').innerHTML='<span class="st-down">'+esc(r.error)+'</span>';return;}
  const cur=curLink.snmp_ifindex;
  $('link-if').innerHTML='<option value="">—</option>'+r.map(i=>`<option value="${i.idx}" ${cur==i.idx?'selected':''}>${esc(i.name)} (ifIndex ${i.idx})</option>`).join('');
  $('link-msg').innerHTML='<span class="st-up">'+TR('@loaded_ifaces',r.length)+'</span>';
};
$('link-save').onclick=async()=>{
  await api.post('update_link',{link:curLink.id,type_id:$('link-type').value,
    snmp_device:$('link-dev').value,snmp_ifindex:$('link-if').value});
  $('dlg-link').classList.add('hidden');selectMap(S.mapId);};
$('link-del').onclick=async()=>{if(confirm(TR('Zmazať spoj?'))){await api.post('del_link',{link:curLink.id});$('dlg-link').classList.add('hidden');selectMap(S.mapId);}};
$('dlg-device').addEventListener('click',e=>{if(e.target===$('dlg-device'))$('dlg-device').classList.add('hidden');});

// ================= PRIDAŤ ZARIADENIE (modál) =================
async function openAddDialog(){
  const types=await api.get('device_types');
  $('add-type').innerHTML=types.map(t=>`<option value="${t.id}">${esc(t.name)}</option>`).join('');
  $('add-ip').value='';$('add-name').value='';$('add-pass').value='';$('add-msg').innerHTML='';
  $('dlg-add').classList.remove('hidden');
}
$('add-close').onclick=()=>$('dlg-add').classList.add('hidden');
$('dlg-add').addEventListener('click',e=>{if(e.target===$('dlg-add'))$('dlg-add').classList.add('hidden');});
$('add-save').onclick=async()=>{
  const name=$('add-name').value.trim()||$('add-ip').value.trim();
  if(!name){$('add-msg').innerHTML='<span class="st-down">'+TR('Zadaj adresu alebo názov.')+'</span>';return;}
  const c={x:(($('canvas').clientWidth/2)-S.view.x)/S.view.k,y:(($('canvas').clientHeight/2)-S.view.y)/S.view.k};
  const r=await api.post('new_device',{map:S.mapId,name,ip:$('add-ip').value.trim(),
    type_id:$('add-type').value,x:Math.round(c.x),y:Math.round(c.y)});
  if(r.ok){$('dlg-add').classList.add('hidden');await selectMap(S.mapId);
    if(r.device_id)openDeviceDialog(r.device_id);}
  else $('add-msg').innerHTML='<span class="st-down">'+esc(r.error||TR('Chyba'))+'</span>';
};

// prepojenia: dvojklik na uzol = okno zariadenia; tlačidlo Zariadenie = modál
$('btn-new-device').onclick=()=>{ if(!S.mapId){alert(TR('Najprv vyber mapu.'));return;} openAddDialog(); };
$('canvas').addEventListener('dblclick',e=>{
  let el=e.target;while(el&&el.tagName!=='g')el=el.parentNode;
  if(el&&el.dataset&&el.dataset.node){
    const n=S.data.nodes.find(x=>x.id==el.dataset.node);
    if(n&&n.kind==='device'&&n.device_id)openDeviceDialog(n.device_id);
  }
});

// ================= MOBIL: zásuvka máp + dotykové ovládanie =================
(function(){
  const sb=document.getElementById('sidebar');
  const tg=document.getElementById('btn-maps-toggle');
  if(tg&&sb) tg.onclick=e=>{e.stopPropagation();sb.classList.toggle('open');};

  const cv=document.getElementById('canvas');
  const dist=(a,b)=>Math.hypot(a.clientX-b.clientX,a.clientY-b.clientY);
  let ts=null;

  cv.addEventListener('touchstart',e=>{
    if(sb) sb.classList.remove('open');
    if(e.touches.length===1){
      const t=e.touches[0];
      let g=document.elementFromPoint(t.clientX,t.clientY);
      while(g&&g.tagName!=='g') g=g.parentNode;
      if(g&&g.dataset&&g.dataset.node&&!S.mode){
        const n=S.data.nodes.find(x=>x.id==g.dataset.node);
        ts={type:'node',n,sx:t.clientX,sy:t.clientY,moved:false};
      }else{
        ts={type:'pan',x:t.clientX,y:t.clientY,vx:S.view.x,vy:S.view.y,moved:false};
      }
    }else if(e.touches.length===2){
      ts={type:'pinch',d0:dist(e.touches[0],e.touches[1]),k0:S.view.k,vx:S.view.x,vy:S.view.y,
          mx:(e.touches[0].clientX+e.touches[1].clientX)/2,my:(e.touches[0].clientY+e.touches[1].clientY)/2};
    }
  },{passive:false});

  cv.addEventListener('touchmove',e=>{
    if(!ts)return;
    if(ts.type==='pan'&&e.touches.length===1){
      const t=e.touches[0];e.preventDefault();
      S.view.x=ts.vx+(t.clientX-ts.x);S.view.y=ts.vy+(t.clientY-ts.y);
      if(Math.abs(t.clientX-ts.x)+Math.abs(t.clientY-ts.y)>4)ts.moved=true;
      render();
    }else if(ts.type==='node'&&e.touches.length===1){
      if(!canEdit())return;
      const t=e.touches[0];e.preventDefault();
      ts.n.x+=(t.clientX-ts.sx)/S.view.k;ts.n.y+=(t.clientY-ts.sy)/S.view.k;
      ts.sx=t.clientX;ts.sy=t.clientY;ts.moved=true;render();
    }else if(ts.type==='pinch'&&e.touches.length===2){
      e.preventDefault();
      const d=dist(e.touches[0],e.touches[1]);const nk=Math.max(.2,Math.min(4,ts.k0*(d/ts.d0)));
      const r=cv.getBoundingClientRect();const px=ts.mx-r.left,py=ts.my-r.top;
      S.view.x=px-(px-ts.vx)*(nk/ts.k0);S.view.y=py-(py-ts.vy)*(nk/ts.k0);S.view.k=nk;render();
    }
  },{passive:false});

  cv.addEventListener('touchend',e=>{
    if(!ts)return;
    if(ts.type==='node'){
      if(ts.moved){
        e.preventDefault();
        api.post('move',{node:ts.n.id,x:Math.round(ts.n.x),y:Math.round(ts.n.y)});
      }else{
        // ťuk na uzol
        const n=ts.n;e.preventDefault();
        if(S.mode==='delete'){ if(n.kind==='device'&&confirm(TR('Zmazať uzol?'))){api.post('del_node',{node:n.id}).then(()=>selectMap(S.mapId));} }
        else if(S.mode==='link'&&n.kind==='device'){
          if(S.linkFrom===null){S.linkFrom=n.id;S.sel=n.id;render();document.getElementById('mode').textContent=TR('Spoj: ťukni druhý uzol');}
          else if(S.linkFrom!==n.id){api.post('add_link',{map:S.mapId,from:S.linkFrom,to:n.id}).then(()=>{S.linkFrom=null;selectMap(S.mapId);});}
        }
        else if(n.kind==='submap'){selectMap(n.submap_id);}
        else if(n.device_id){S.sel=n.id;render();openInspector(n.device_id);}
      }
    }
    ts=null;
  },{passive:false});

  // ťuk mimo zásuvky ju zavrie
  document.getElementById('main').addEventListener('click',()=>{ if(sb) sb.classList.remove('open'); });
})();
