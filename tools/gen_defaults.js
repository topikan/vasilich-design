// Run after changing texts or the default portfolio in site/index.html:  node tools/gen_defaults.js site
// Builds site/lib/defaults.json from site/index.html: portfolio works, RU/EN texts grouped by page section.
const fs=require('fs'),path=require('path');
const root=process.argv[2];const html=fs.readFileSync(path.join(root,'index.html'),'utf8');
const grab=(start)=>{const i=html.indexOf(start);let d=0,j=i+start.length-1;for(;j<html.length;j++){const ch=html[j];if(ch==='{'||ch==='[')d++;else if(ch==='}'||ch===']'){d--;if(d===0)break}else if(ch==="'"){j++;while(html[j]!=="'"){if(html[j]==='\\')j++;j++}}}return html.slice(i+start.length-1,j+1)};
const L=(ru,en)=>({ru,en}),W='assets/works/';
const EN=eval('('+grab('const EN={')+')');
const RUJS=eval('('+grab('const RU={')+')');
const WORKS=eval(grab('WORKS=['));
const STEPS=eval(grab('STEPS=['));
// texts from DOM, in order, with the section they belong to
const sections=[];const reSec=/<!--\s*\d+\.\s*([^-]+?)\s*-->/g;let m;while((m=reSec.exec(html)))sections.push([m.index,m[1].trim()]);
const secAt=i=>{let s='Шапка';for(const [p,n] of sections)if(p<i)s=n;return s};
const NAMES={'WORKFLOW (SLIDER)':'Этапы работы','HEADER':'Шапка','HERO':'Первый экран','SERVICES':'Услуги','PACKAGES':'Пакеты услуг','INTERACTIVE DIORAMA':'Диорама','PORTFOLIO':'Портфолио','ABOUT':'О студии','WORKFLOW':'Этапы работы','CONTACT':'Контакты и форма','FOOTER':'Подвал'};
const body=html.slice(0,html.indexOf('<script>'));
const keys=[],seen=new Set();
const re=/<([a-z0-9]+)\b[^>]*\bdata-i18n="([^"]+)"[^>]*>([\s\S]*?)<\/\1>/g;
while((m=re.exec(body))){const k=m[2];if(seen.has(k))continue;seen.add(k);let sec=secAt(m.index);sec=NAMES[sec.toUpperCase()]||sec;keys.push({k,sec,ru:m[3].trim(),en:EN[k]??''})}
const reph=/data-i18n-ph="([^"]+)"[^>]*placeholder="([^"]*)"/g;while((m=reph.exec(body))){if(seen.has(m[1]))continue;seen.add(m[1]);keys.push({k:m[1],sec:'Контакты и форма',ru:m[2],en:EN[m[1]]??'',ph:true})}
for(const [k,v] of Object.entries(RUJS)){if(seen.has(k))continue;seen.add(k);keys.push({k,sec:/^light/.test(k)?'skip':/^d|promo/.test(k)?'Диорама':/^f/.test(k)?'Контакты и форма':/^music/.test(k)?'Диорама':'Прочее',ru:v,en:EN[k]??''})}
// keep WORKS media as-is; add an id for the editor
const works=WORKS.map((w,i)=>({id:'w'+(i+1),t:w.t,c:w.c,img:w.img,loop:w.loop||'',pos:w.pos||'',task:w.task||L('',''),res:w.res||L('',''),fmt:w.fmt||L('',''),media:w.media||[],hidden:false}));
const texts=keys.filter(k=>k.sec!=='skip').map(k=>k.k==='out'?{...k,sec:'Этапы работы'}:k);
const steps=STEPS.map(s=>({t:s[0],d:s[1],o:s[2]}));
fs.writeFileSync(path.join(root,'lib/defaults.json'),JSON.stringify({works,steps,texts},null,1));
console.log('works',works.length,'steps',steps.length,'texts',texts.length,[...new Set(texts.map(k=>k.sec))].join(' | '));
