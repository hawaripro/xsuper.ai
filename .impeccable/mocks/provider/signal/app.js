const app = document.querySelector('#app');
const params = new URLSearchParams(location.search);
const embedded = params.get('embed') === '1';
const views = ['landing', 'workspace', 'console'];
const state = {
  view: views.includes(params.get('view')) ? params.get('view') : 'landing',
  capability: 'write',
  model: 'Claude · Anthropic',
  messages: [],
  draft: '',
  consoleSection: 'overview',
  code: 'curl',
  filter: 'all',
  menu: false,
  busy: false,
};
const tasks = {
  write: {
    title: 'Tulis lebih terarah.', label: 'Menulis & merangkum', model: 'Claude', maker: 'Anthropic', type: 'Bahasa',
    prompt: 'Bantu susun pembuka proposal untuk sebuah studio desain independen.',
    heading: 'Sebuah ide yang layak diwujudkan.',
    answer: 'Kami membantu bisnis menyampaikan hal yang paling penting. Melalui strategi yang jelas dan desain yang berkarakter, kami mengubah ide menjadi pengalaman yang mudah dipahami — dan ingin digunakan.',
    detail: 'Pembuka proposal · 1 paragraf',
  },
  code: {
    title: 'Dari masalah ke solusi.', label: 'Coding & pengembangan', model: 'GPT', maker: 'OpenAI', type: 'Kode',
    prompt: 'Buat fungsi JavaScript untuk mengelompokkan catatan berdasarkan proyek.',
    heading: 'Satu fungsi. Catatan lebih teratur.',
    answer: 'function groupByProject(notes) {\n  return Object.groupBy(\n    notes, note => note.project\n  );\n}\n\nconst projects = groupByProject(notes);',
    detail: 'JavaScript · contoh kode',
  },
  plan: {
    title: 'Pikirkan langkah berikutnya.', label: 'Riset & perencanaan', model: 'Gemini', maker: 'Google', type: 'Analisis',
    prompt: 'Susun kerangka riset sebelum meluncurkan produk digital pertama.',
    heading: 'Mulai dari pertanyaan yang tepat.',
    answer: 'Tentukan siapa yang mengalami masalah, bagaimana mereka menyelesaikannya saat ini, dan bagian mana yang paling menyulitkan. Wawancarai calon pengguna sebelum membuat solusi. Rangkum pola yang berulang menjadi satu hipotesis yang bisa diuji.',
    detail: 'Kerangka riset · tahap awal',
  },
};
const demoRequests = [
  {time: '14:32:08',model:'Claude',maker:'Anthropic',task:'Ringkasan dokumen',status:'Selesai',code:'200',tokens:'1.284'},
  {time: '14:28:41',model:'GPT',maker:'OpenAI',task:'Review fungsi',status:'Selesai',code:'200',tokens:'826'},
  {time: '14:21:16',model:'Gemini',maker:'Google',task:'Rencana riset',status:'Dibatasi',code:'429',tokens:'—'},
  {time: '14:16:03',model:'Claude',maker:'Anthropic',task:'Draft proposal',status:'Selesai',code:'200',tokens:'2.016'},
];
const paths = {
  arrow:'M4 12h16m-6-6 6 6-6 6', up:'M6 18 18 6M6 6h12v12',
  plus:'M12 5v14M5 12h14', menu:'M4 6h16M4 12h16M4 18h16', close:'m6 6 12 12M18 6 6 18',
  chat:'M5 4h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 3V6a2 2 0 0 1 1-2Z',
  code:'m8 7-5 5 5 5m8-10 5 5-5 5m-3-12-2 14',
  grid:'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
  key:'M14 7a4 4 0 1 1-2 7l-7 7H2v-3l7-7a4 4 0 0 1 5-4Z',
  chart:'M4 4v16h16M8 15v-4m5 4V7m5 8v-6',
  book:'M4 4h7v16H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm9 0h7a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-7Z',
  copy:'M8 8h12v12H8zM4 16H2V2h14v2',
  chevron:'m8 10 4 4 4-4', send:'M12 20V4m-6 6 6-6 6 6',
};
function icon(name) {return `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${paths[name] || paths.arrow}"/></svg>`;}
function escape(value) {return String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));}
function brand() {return `<a class="brand" href="?view=landing${embedded ? '&embed=1' : ''}" data-view="landing" aria-label="UltrAI, beranda"><svg viewBox="0 0 28 28" aria-hidden="true"><path d="M5 4v12a9 9 0 0 0 18 0V4h-5v12a4 4 0 0 1-8 0V4Z" fill="currentColor" stroke="none"/><path d="m13 2 6 7h-5l-4-7Z" fill="currentColor" stroke="none"/></svg>UltrAI</a>`;}
function previewBar() {return embedded ? '' : `<div class="preview-bar"><a href="/">${icon('arrow')} Semua konsep</a><span>Signal</span><nav aria-label="Halaman prototype">${views.map(view=>`<button data-view="${view}" class="${state.view===view?'selected':''}">${view==='landing'?'Landing':view==='workspace'?'Workspace':'Console'}</button>`).join('')}</nav></div>`;}
function siteHeader() {return `<header class="site-header">${brand()}<button class="menu-button" data-action="menu" aria-label="${state.menu?'Tutup':'Buka'} navigasi" aria-expanded="${state.menu}">${icon(state.menu?'close':'menu')}</button><nav class="site-nav ${state.menu?'open':''}" aria-label="Navigasi produk"><a href="#capabilities">Kapabilitas</a><button data-view="workspace">Workspace</button><button data-view="console">Developer</button><button class="nav-action" data-view="workspace">Mulai bekerja ${icon('up')}</button></nav></header>`;}
function landing() {
  const task=tasks[state.capability];
  return `${siteHeader()}<main id="main" class="landing"><section class="hero"><div class="hero-line"><h1>Satu platform.<br><span>Banyak kemungkinan.</span></h1><div class="hero-aside"><p>Tempat ide dipertajam,<br>pekerjaan diselesaikan,<br>dan produk dibangun.</p><button class="amber-button" data-view="workspace">Buka workspace ${icon('arrow')}</button><button class="plain-button" data-view="console">Bangun dengan API ${icon('up')}</button></div></div><div class="hero-bottom"><p>AI yang kamu gunakan.<br>Platform yang kamu kendalikan.</p><span>Chat, kode, dan eksplorasi.<br>Dalam satu pengalaman UltrAI.</span></div></section>
  <section id="capabilities" class="capability-section" aria-labelledby="capability-heading"><div class="section-heading"><h2 id="capability-heading">Pilih pekerjaan.<br>Mulai bergerak.</h2><p>Bukan tentang mengoleksi model.<br>Tentang menemukan yang tepat untuk pekerjaanmu.</p></div><div class="capability-board"><div class="capability-list"><div class="board-header"><span>Pekerjaan</span><span>Model / pembuat</span><span></span></div>${Object.entries(tasks).map(([key,item])=>`<button class="capability-row ${key===state.capability?'active':''}" data-task="${key}" aria-pressed="${key===state.capability}"><span><strong>${item.label}</strong><small>${item.type}</small></span><span>${item.model}<small>${item.maker}</small></span>${icon('up')}</button>`).join('')}<p class="board-footnote">Nama model dan pembuatnya ditampilkan apa adanya.</p></div><div class="capability-output" aria-live="polite"><div class="output-meta"><span>${task.model} <span class="muted">/ ${task.maker}</span></span><span>Contoh respons</span></div><p class="prompt-quote">“${task.prompt}”</p><h3>${task.heading}</h3>${state.capability==='code'?`<pre>${escape(task.answer)}</pre>`:`<p class="output-answer">${task.answer}</p>`}<div class="output-footer"><span>${task.detail}</span><button data-action="use-task" aria-label="Coba tugas ini di workspace">${icon('arrow')}</button></div></div></div></section>
  <section class="paths-section"><h2>Dua cara masuk.<br>Satu tempat bertumbuh.</h2><div class="journey"><div><h3>Untuk pekerjaanmu.</h3><p>Tulis, telusuri ide, dan selesaikan masalah lewat percakapan. Mulai dari satu pertanyaan.</p></div><button class="plain-button" data-view="workspace">Masuk workspace ${icon('up')}</button></div><div class="journey"><div><h3>Untuk produkmu.</h3><p>Gunakan API UltrAI dari aplikasi yang sedang kamu bangun. Integrasi tetap berada dalam kendalimu.</p></div><button class="plain-button" data-view="console">Buka developer console ${icon('up')}</button></div></section><section class="close-section"><h2>Pekerjaan baik<br>dimulai dari sini.</h2><button class="amber-button" data-view="workspace">Coba workspace ${icon('arrow')}</button></section></main><footer class="site-footer">${brand()}<span>Workspace untuk berpikir. API untuk membangun.</span><button data-view="console">Dokumentasi ${icon('up')}</button></footer>`;
}
function rail(view) {
  const consoleItems=[['overview','grid','Ringkasan'],['keys','key','API keys'],['usage','chart','Pemakaian'],['docs','book','Panduan']];
  return `<aside class="app-rail ${state.menu?'open':''}">${brand()}<div class="rail-switch"><button data-view="workspace" class="${view==='workspace'?'active':''}">${icon('chat')} Workspace</button><button data-view="console" class="${view==='console'?'active':''}">${icon('code')} Console</button></div>${view==='workspace'?`<button class="new-chat" data-action="new-chat">${icon('plus')} Percakapan baru</button><div class="rail-label">Contoh pekerjaan</div><nav aria-label="Contoh percakapan">${Object.entries(tasks).map(([key,item])=>`<button class="rail-item" data-history="${key}">${item.label}</button>`).join('')}</nav><div class="rail-help"><p>Ruang untuk ide berikutnya.</p><span>Pilih contoh atau mulai<br>dengan pertanyaanmu sendiri.</span></div>`:`<div class="rail-label">Developer</div><nav aria-label="Navigasi console">${consoleItems.map(([id,glyph,label])=>`<button class="rail-item ${state.consoleSection===id?'active':''}" data-section="${id}">${icon(glyph)}${label}</button>`).join('')}</nav><div class="rail-help"><p>Bangun dengan UltrAI.</p><span>Eksplorasi alur integrasi<br>dengan data ilustratif.</span></div>`}<div class="rail-account"><span class="avatar">A</span><div>Alex<span>Akun contoh</span></div><button data-view="landing" aria-label="Kembali ke landing">${icon('up')}</button></div></aside>`;
}
function appHeader(view) {return `<header class="app-header"><button class="menu-button" data-action="menu" aria-label="${state.menu?'Tutup':'Buka'} navigasi" aria-expanded="${state.menu}">${icon(state.menu?'close':'menu')}</button><span>UltrAI <span class="header-divider">/</span> ${view==='workspace'?'Workspace':'Developer console'}</span><button data-view="${view==='workspace'?'console':'workspace'}">${view==='workspace'?'Developer console':'Buka workspace'} ${icon('up')}</button></header>`;}
function modelSelect() {return `<label class="model-select"><span>Model</span><select id="model-select" aria-label="Model pilihan">${['Claude · Anthropic','GPT · OpenAI','Gemini · Google'].map(model=>`<option${model===state.model?' selected':''}>${model}</option>`).join('')}</select></label>`;}
function workspace() {
  return `<div class="app-shell">${rail('workspace')}<div class="app-content">${appHeader('workspace')}<main id="main" class="workspace-main ${state.messages.length?'has-messages':''}">${state.messages.length?`<div class="conversation" aria-live="polite">${state.messages.map(message=>`<article class="message ${message.role}"><div class="message-author">${message.role==='user'?'Kamu':`UltrAI <span>Contoh respons · ${escape(message.model)}</span>`}</div><div class="message-body">${escape(message.content)}</div></article>`).join('')}</div>`:`<div class="workspace-welcome"><div class="workspace-emblem" aria-hidden="true"><svg viewBox="0 0 72 72"><path d="M8 36h56M36 8v56M16 16l40 40M56 16 16 56"/></svg></div><h1>Apa yang ingin<br>kamu kerjakan?</h1><p>Mulai dari ide mentah.<br>Kita buat langkah berikutnya lebih jelas.</p></div>`}<form id="composer" class="composer"><label class="sr-only" for="prompt">Tulis pesan</label><textarea id="prompt" placeholder="Tanyakan, tulis, atau mulai sesuatu…" rows="3" required maxlength="3000"${state.busy?' disabled':''}></textarea><div class="composer-bottom">${modelSelect()}<button type="submit" class="send-button" aria-label="Kirim prompt contoh"${state.busy?' disabled':''}>${icon('send')}</button></div></form>${!state.messages.length?`<div class="suggestion-row">${Object.entries(tasks).map(([key,item])=>`<button data-suggestion="${key}">${item.label} ${icon('up')}</button>`).join('')}</div>`:''}<p class="workspace-disclaimer">Prototype interaktif. Jawaban contoh ditulis sebelumnya, bukan respons AI langsung.</p></main></div></div>`;
}
function codeExample() {return state.code==='curl'?`curl https://api.ultrai.id/v1/chat/completions \\\n  -H "Authorization: Bearer YOUR_API_KEY" \\\n  -H "Content-Type: application/json" \\\n  -d '{\n    "model": "MODEL_ID_FROM_CATALOG",\n    "messages": [\n      {"role": "user", "content": "Halo, UltrAI"}\n    ]\n  }'`:`from openai import OpenAI\n\nclient = OpenAI(\n    base_url="https://api.ultrai.id/v1",\n    api_key="YOUR_API_KEY"\n)\n\nresponse = client.chat.completions.create(\n    model="MODEL_ID_FROM_CATALOG",\n    messages=[{"role": "user", "content": "Halo, UltrAI"}]\n)\nprint(response.choices[0].message.content)`;}
function codePanel() {return `<section class="code-panel"><div class="code-toolbar"><div class="code-tabs" role="group" aria-label="Bahasa contoh integrasi"><button data-code="curl" aria-pressed="${state.code==='curl'}">cURL</button><button data-code="python" aria-pressed="${state.code==='python'}">Python</button></div><button class="copy-button" data-action="copy-code">${icon('copy')} Salin</button></div><pre id="code-sample"><code>${escape(codeExample())}</code></pre><p>Ganti placeholder dengan API key dan ID model yang tersedia di akunmu. Kode tidak dijalankan oleh prototype.</p></section>`;}
function requestTable() {const rows=demoRequests.filter(row=>state.filter==='all'||(state.filter==='success'?row.code==='200':row.code!=='200'));return `<section class="requests"><div class="section-heading compact"><div><h2>Aktivitas request</h2><p>Data ilustratif · bukan pemakaian akun asli</p></div><label class="filter-label"><span class="sr-only">Filter status request</span><select id="request-filter"><option value="all"${state.filter==='all'?' selected':''}>Semua status</option><option value="success"${state.filter==='success'?' selected':''}>Selesai</option><option value="limited"${state.filter==='limited'?' selected':''}>Dibatasi</option></select></label></div><div class="table-scroll"><table><thead><tr><th>Waktu contoh</th><th>Model</th><th>Pekerjaan</th><th>Token contoh</th><th>Status</th></tr></thead><tbody>${rows.map(row=>`<tr><td class="numeric">${row.time}</td><td>${row.model}<small>${row.maker}</small></td><td>${row.task}</td><td class="numeric">${row.tokens}</td><td><span class="status-text ${row.code==='200'?'success':'limited'}">${row.code} ${row.status}</span></td></tr>`).join('')}</tbody></table></div></section>`;}
function consoleBody() {
  if(state.consoleSection==='keys')return `<div class="console-heading"><div><h1>API keys</h1><p>Kenali alur kredensial sebelum integrasi.</p></div><span class="demo-label">Contoh antarmuka</span></div><section class="key-panel"><div><h2>Development key</h2><p>Placeholder untuk dokumentasi. Bukan kredensial aktif.</p></div><code>YOUR_API_KEY</code><button class="outline-button" data-action="copy-key">${icon('copy')} Salin placeholder</button></section><div class="notice"><h3>Kunci asli tidak dibuat di sini.</h3><p>Prototype tidak terhubung ke akun. Pembuatan, rotasi, dan pembatasan API key mengikuti kemampuan backend pada implementasi final.</p></div>`;
  if(state.consoleSection==='usage')return `<div class="console-heading"><div><h1>Pemakaian</h1><p>Lihat model dan hasil setiap permintaan.</p></div><span class="demo-label">Data ilustratif</span></div>${requestTable()}<p class="console-footnote">Nilai token, waktu, dan status di atas adalah contoh untuk mengevaluasi desain, bukan data bisnis.</p>`;
  if(state.consoleSection==='docs')return `<div class="console-heading"><div><h1>Dari kode ke percakapan.</h1><p>Mulai integrasi melalui endpoint UltrAI.</p></div></div><div class="docs-steps"><article><h2>Pilih model</h2><p>Ambil ID model yang tersedia melalui <code>GET /v1/models</code>. Nama pembuat model tetap ditampilkan dengan akurat.</p></article><article><h2>Siapkan API key</h2><p>Gunakan kredensial akunmu sebagai Bearer token. Simpan di server, bukan dalam kode browser.</p></article><article><h2>Kirim permintaan</h2><p>Kirim pesan ke <code>POST /v1/chat/completions</code> dengan ID model pilihanmu.</p></article></div>${codePanel()}`;
  return `<div class="console-heading"><div><h1>Bangun langkah berikutnya.</h1><p>Dari permintaan pertama sampai menjadi bagian dari produkmu.</p></div><span class="demo-label">Ruang developer</span></div><section class="first-request"><div><h2>Mulai dengan<br>satu request.</h2><p>Struktur yang familiar.<br>Identitas yang tetap UltrAI.</p><button class="plain-button" data-section="docs">Baca panduan ${icon('up')}</button><div class="endpoint"><span>Base URL</span><code>https://api.ultrai.id/v1</code></div></div>${codePanel()}</section>${requestTable()}`;
}
function consolePage() {return `<div class="app-shell">${rail('console')}<div class="app-content">${appHeader('console')}<main id="main" class="console-main">${consoleBody()}</main></div></div>`;}
function render({ focusContent = false } = {}) {
  const active = document.activeElement;
  let focusSelector = null;
  if (app.contains(active)) {
    if (active.id) focusSelector = `#${CSS.escape(active.id)}`;
    else {
      const key = ['task', 'code', 'action', 'section', 'view', 'history'].find(name => active.dataset[name]);
      if (key) focusSelector = `[data-${key}="${CSS.escape(active.dataset[key])}"]`;
    }
  }
  app.innerHTML = previewBar() + (state.view === 'landing' ? landing() : state.view === 'workspace' ? workspace() : consolePage());
  const prompt = document.querySelector('#prompt');
  if (prompt) prompt.value = state.draft;
  document.body.classList.toggle('embedded', embedded);
  document.title = `UltrAI — Signal ${state.view === 'landing' ? '' : state.view}`;
  if (focusContent) {
    const main = document.querySelector('#main');
    main.tabIndex = -1;
    main.focus({ preventScroll: true });
  } else if (focusSelector) {
    const replacement = [...app.querySelectorAll(focusSelector)].find(element => element.getBoundingClientRect().height > 0);
    replacement?.focus({ preventScroll: true });
  }
}
function setView(view) {if(!views.includes(view))return;state.view=view;state.menu=false;const url=new URL(location.href);url.searchParams.set('view',view);history.pushState(null,'',url);render({focusContent:true});window.scrollTo(0,0);}
function notify(text) {document.querySelector('#notification').textContent=text;clearTimeout(notify.timer);notify.timer=setTimeout(()=>document.querySelector('#notification').textContent='',4200);}
async function copy(text) {try{await navigator.clipboard.writeText(text);notify('Contoh disalin ke clipboard.');}catch{notify('Clipboard tidak tersedia. Pilih dan salin teks contoh secara manual.');}}
app.addEventListener('click',event=>{
  const target=event.target.closest('button,a');if(!target)return;
  if(target.dataset.view){event.preventDefault();setView(target.dataset.view);return;}
  if(target.dataset.task){state.capability=target.dataset.task;const position=window.scrollY;render();window.scrollTo(0,position);return;}
  if(target.dataset.suggestion){state.capability=target.dataset.suggestion;state.draft=tasks[state.capability].prompt;document.querySelector('#prompt').value=state.draft;document.querySelector('#prompt').focus();return;}
  if(target.dataset.history){state.capability=target.dataset.history;state.messages=[{role:'user',content:tasks[state.capability].prompt},{role:'assistant',content:tasks[state.capability].answer,model:state.model}];state.menu=false;render();return;}
  if(target.dataset.section){state.consoleSection=target.dataset.section;state.menu=false;render({focusContent:true});return;}
  if(target.dataset.code){state.code=target.dataset.code;render();return;}
  switch(target.dataset.action){
    case 'menu':state.menu=!state.menu;render();break;
    case 'new-chat':state.messages=[];state.draft='';state.menu=false;render();document.querySelector('#prompt').focus();break;
    case 'use-task':state.draft=tasks[state.capability].prompt;setView('workspace');document.querySelector('#prompt').focus();break;
    case 'copy-code':copy(codeExample());break;
    case 'copy-key':copy('YOUR_API_KEY');break;
  }
});
app.addEventListener('input',event=>{if(event.target.id==='prompt')state.draft=event.target.value;});
app.addEventListener('change',event=>{if(event.target.id==='model-select')state.model=event.target.value;if(event.target.id==='request-filter'){state.filter=event.target.value;render();}});
app.addEventListener('submit',event=>{if(event.target.id!=='composer')return;event.preventDefault();const prompt=document.querySelector('#prompt').value.trim();if(!prompt||state.busy)return;state.messages.push({role:'user',content:prompt});state.messages.push({role:'assistant',content:tasks[state.capability].answer,model:state.model});state.draft='';render();document.querySelector('#prompt').focus();});
window.addEventListener('popstate',()=>{const view=new URLSearchParams(location.search).get('view');state.view=views.includes(view)?view:'landing';state.menu=false;render({focusContent:true});});
render();
