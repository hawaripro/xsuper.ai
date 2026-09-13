(() => {
  'use strict';

  const $ = (selector, scope = document) => scope.querySelector(selector);
  const $$ = (selector, scope = document) => [...scope.querySelectorAll(selector)];
  const icon = (name) => `<svg class="icon" aria-hidden="true"><use href="#i-${name}"/></svg>`;
  const escapeHTML = (value) => String(value).replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const views = ['landing', 'workspace', 'console'];
  const consoleSections = ['overview', 'keys', 'usage', 'guide'];
  const modelNames = {
    gpt: { name: 'GPT-4o', maker: 'OpenAI', mode: 'chat', monogram: 'O' },
    claude: { name: 'Claude Sonnet', maker: 'Anthropic', mode: 'chat', monogram: 'A' },
    gemini: { name: 'Gemini Pro', maker: 'Google', mode: 'chat', monogram: 'G' },
    'gpt-image': { name: 'GPT Image', maker: 'OpenAI', mode: 'image', monogram: 'O' },
    imagen: { name: 'Imagen', maker: 'Google', mode: 'image', monogram: 'G' },
    flux: { name: 'FLUX.1', maker: 'Black Forest Labs', mode: 'image', monogram: 'B' },
    sora: { name: 'Sora', maker: 'OpenAI', mode: 'video', monogram: 'O' },
    veo: { name: 'Veo', maker: 'Google', mode: 'video', monogram: 'G' },
    runway: { name: 'Gen-4', maker: 'Runway', mode: 'video', monogram: 'R' },
    'openai-tts': { name: 'GPT-4o mini TTS', maker: 'OpenAI', mode: 'audio', monogram: 'O' },
    eleven: { name: 'Eleven v3', maker: 'ElevenLabs', mode: 'audio', monogram: 'E' },
    lyria: { name: 'Lyria', maker: 'Google', mode: 'audio', monogram: 'G' },
  };
  const samples = {
    writing: {
      prompt: 'Bantu tulis pembuka untuk brand kopi lokal. Hangat, sederhana, tidak berlebihan.',
      suggestion: 'Rapikan tulisan untuk brand kopi lokal: Kopi kami dari petani lokal. Kami ingin orang menikmati kopi sambil beristirahat sejenak.',
      title: 'Pembuka brand kopi',
      content: '<h3>Selalu ada waktu<br>untuk mulai pelan.</h3><p>Secangkir kopi, percakapan kecil, dan jeda yang Anda butuhkan. Kami meracik kopi lokal untuk menemani hal-hal sederhana yang layak dinikmati.</p><p class="draft-note">Draf pembuka · brand kopi fiktif</p>',
    },
    coding: {
      prompt: 'Buat komponen tombol React dengan state loading yang tetap bisa diakses.',
      suggestion: 'Buat komponen tombol React dengan state loading, label yang jelas, dan tombol disabled ketika proses berjalan.',
      title: 'Komponen tombol React',
      content: '<h3>Satu komponen.<br>State yang jelas.</h3><pre><code>function SubmitButton({ loading }) {\n  return (\n    &lt;button disabled={loading}\n      aria-busy={loading}&gt;\n      {loading ? "Menyimpan…" : "Simpan"}\n    &lt;/button&gt;\n  );\n}</code></pre><p class="draft-note">Cuplikan React contoh · bukan kode yang dieksekusi</p>',
    },
    planning: {
      prompt: 'Susun rencana satu minggu untuk menyiapkan website portfolio pertama saya.',
      suggestion: 'Susun rencana kerja satu minggu untuk website portfolio: kumpulkan karya, tulis cerita, bangun halaman, lalu periksa aksesibilitas.',
      title: 'Rencana portfolio',
      content: '<h3>Seminggu.<br>Langkah yang terarah.</h3><ul><li><span>Sen–Sel</span>Tentukan tujuan dan pilih tiga karya.</li><li><span>Rabu</span>Tulis cerita di balik setiap karya.</li><li><span>Kam–Jum</span>Bangun halaman, periksa di ponsel.</li></ul><p class="draft-note">Rencana ilustratif · sesuaikan dengan waktu Anda</p>',
    },
    general: {
      title: 'Memulai dari sebuah ide',
      content: '<h3>Mulai dari hasil<br>yang Anda butuhkan.</h3><p>Berikut kerangka contoh untuk mengembangkan sebuah ide. Ini respons tersimpan, bukan analisis pesan Anda oleh AI.</p><ul><li><span>Tujuan</span>Tulis hasil yang ingin dicapai dalam satu kalimat.</li><li><span>Konteks</span>Tambahkan audiens, batasan, dan bahan yang tersedia.</li><li><span>Langkah</span>Pilih satu bagian kecil yang bisa dikerjakan lebih dulu.</li></ul>',
    },
  };
  const workspaceModes = {
    chat: {
      name: 'Chat', label: 'Chat AI · demo', title: 'Halo, kreator.', accent: 'Mau buat apa hari ini?',
      description: 'Dari satu pertanyaan ke karya berikutnya. Pilih alatnya. Bawa idemu.',
      placeholder: 'Tanya apa saja, atau jatuhkan file di sini…', submit: 'Kirim contoh',
      suggestions: [
        { id: 'coding', icon: 'code', title: 'Bangun sesuatu', detail: 'Dari ide ke komponen React', prompt: samples.coding.suggestion },
        { id: 'writing', icon: 'edit', title: 'Temukan kata yang tepat', detail: 'Bikin draf lebih berkarakter', prompt: samples.writing.suggestion },
        { id: 'planning', icon: 'list', title: 'Urai rencanamu', detail: 'Langkah kecil, tujuan jelas', prompt: samples.planning.suggestion },
      ],
    },
    image: {
      name: 'Image', label: 'Brief gambar', title: 'Mulai dari bayangan.', accent: 'Beri idemu bentuk.',
      description: 'Susun arah visual, bawa referensi, lalu rapikan brief-mu. Tidak ada gambar yang dihasilkan di demo ini.',
      placeholder: 'Ceritakan subjek, suasana, warna, dan tujuan visualmu…', submit: 'Susun brief',
      suggestions: [
        { id: 'image-product', icon: 'image', title: 'Sorot produkmu', detail: 'Komposisi, cahaya, karakter', prompt: 'Brief foto produk kopi lokal: satu kemasan di meja kayu, cahaya pagi hangat, latar sederhana, format persegi untuk katalog.' },
        { id: 'image-poster', icon: 'edit', title: 'Bayangkan sebuah poster', detail: 'Satu ide visual yang kuat', prompt: 'Brief poster acara kreatif: bentuk geometris biru kobalt, ruang kosong untuk judul, format potret, tanpa logo atau teks buatan.' },
        { id: 'image-scene', icon: 'spark', title: 'Bangun suasana', detail: 'Dunia kecil untuk ceritamu', prompt: 'Brief ilustrasi ruang baca yang tenang: cahaya sore, tanaman dekat jendela, warna hangat, komposisi lebar tanpa figur manusia.' },
      ],
    },
    video: {
      name: 'Video', label: 'Brief video', title: 'Satu ide.', accent: 'Cerita yang bergerak.',
      description: 'Rancang adegan, gerak kamera, dan ritme cerita. Demo menyimpan brief, bukan menghasilkan video.',
      placeholder: 'Ceritakan adegan, durasi, gerak, dan akhir yang kamu bayangkan…', submit: 'Susun brief',
      suggestions: [
        { id: 'video-product', icon: 'video', title: 'Kenalkan produk', detail: 'Pembuka, detail, penutup', prompt: 'Brief video produk 15 detik: kopi diseduh perlahan, potongan detail tekstur, diakhiri kemasan di meja. Gerak kamera halus, format vertikal.' },
        { id: 'video-story', icon: 'edit', title: 'Susun cerita pendek', detail: 'Tiga adegan yang terhubung', prompt: 'Brief video 15 detik tentang memulai hari kreatif: membuka buku, membuat sketsa, lalu menata meja. Warna hangat, tanpa dialog.' },
        { id: 'video-motion', icon: 'spark', title: 'Gerakkan identitas', detail: 'Ritme untuk pembuka brand', prompt: 'Brief motion design 10 detik: bidang biru kobalt tersusun menjadi bentuk abstrak, gerak terukur, latar putih, penutup tenang tanpa teks.' },
      ],
    },
    audio: {
      name: 'Audio', label: 'Brief audio', title: 'Temukan nadanya.', accent: 'Buat ide terdengar.',
      description: 'Tulis naskah, arah suara, dan tempo. Brief dapat diedit dan diekspor; tidak ada audio yang dibuat.',
      placeholder: 'Tulis naskah atau ceritakan karakter suara dan durasinya…', submit: 'Susun brief',
      suggestions: [
        { id: 'audio-narration', icon: 'audio', title: 'Suara untuk ceritamu', detail: 'Narasi yang hangat dan jelas', prompt: 'Brief narasi bahasa Indonesia 20 detik, suara hangat, tempo sedang. Naskah: Ada ide yang hanya butuh ruang untuk tumbuh. Mulai kecil, buat dengan hati.' },
        { id: 'audio-podcast', icon: 'chat', title: 'Buka percakapan', detail: 'Intro singkat untuk podcast', prompt: 'Brief pembuka podcast kreatif 15 detik: gaya percakapan santai, jeda pendek setelah salam, tanpa meniru suara tokoh atau orang tertentu.' },
        { id: 'audio-music', icon: 'spark', title: 'Tentukan suasana musik', detail: 'Instrumen, tempo, dinamika', prompt: 'Brief musik latar instrumental 30 detik: piano lembut, tekstur akustik, tempo lambat, tanpa vokal, masuk dan keluar secara halus.' },
      ],
    },
  };
  const defaultModels = { chat: 'gpt', image: 'gpt-image', video: 'veo', audio: 'openai-tts' };
  const attachmentTypes = {
    png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp',
    pdf: 'application/pdf', txt: 'text/plain', md: 'text/markdown', csv: 'text/csv', json: 'application/json',
  };
  const allowedFileTypes = new Set(Object.values(attachmentTypes));
  const maxAttachments = 5;
  const maxFileSize = 20 * 1024 * 1024;
  const codeSamples = {
    curl: [
      '# Contoh saja; tidak dijalankan di prototype',
      'curl https://api.ultrai.id/v1/chat/completions \\',
      '  -H "Authorization: Bearer $ULTRAI_API_KEY" \\',
      '  -H "Content-Type: application/json" \\',
      "  -d '{",
      '    "model": "auto",',
      '    "messages": [{',
      '      "role": "user",',
      '      "content": "Halo, UltrAI!"',
      '    }]',
      "  }'",
    ].join('\n'),
    python: [
      '# Contoh saja; gunakan key valid di server Anda',
      'import json, os, urllib.request',
      '',
      'payload = {',
      '    "model": "auto",',
      '    "messages": [{"role": "user",',
      '                  "content": "Halo, UltrAI!"}]',
      '}',
      'request = urllib.request.Request(',
      '    "https://api.ultrai.id/v1/chat/completions",',
      '    data=json.dumps(payload).encode(),',
      '    headers={',
      '        "Authorization": "Bearer " +',
      '            os.environ["ULTRAI_API_KEY"],',
      '        "Content-Type": "application/json"',
      '    }, method="POST"',
      ')',
      'with urllib.request.urlopen(request) as response:',
      '    print(json.load(response))',
    ].join('\n'),
  };
  const requests = [
    { id: 'demo_req_004', model: 'auto', status: 200, tokens: 184, time: '10:42:08', message: 'Halo! Ini contoh respons untuk eksplorasi desain UltrAI.' },
    { id: 'demo_req_003', model: 'auto', status: 200, tokens: 326, time: '10:40:31', message: 'Contoh tersimpan: tulis tujuan, kumpulkan konteks, lalu buat draf pertama.' },
    { id: 'demo_req_002', model: 'auto', status: 401, tokens: 0, time: '10:38:16', message: 'Contoh error autentikasi: dummy key tidak valid. Periksa kredensial di sisi server.' },
    { id: 'demo_req_001', model: 'auto', status: 200, tokens: 92, time: '10:35:04', message: 'Halo! Request ini hanyalah rekaman sintetis untuk prototype.' },
  ];
  let currentView = 'landing';
  let currentConsoleSection = 'overview';
  let currentTask = 'writing';
  let selectedConversation = null;
  let nextConversation = 1;
  let nextMessage = 1;
  let nextKey = 2;
  let toastTimer;
  let requestDetailTrigger = null;
  let workspaceMode = 'chat';
  let pickerFilter = 'all';
  let nextAttachment = 1;
  let activeResponse = null;
  let newDraft = createDraft();
  const pendingReaders = new Set();
  const downloadURLs = new Map();
  const conversations = ['writing', 'planning', 'coding'].map((task) => ({
    id: `seed-${task}`,
    title: samples[task].title,
    model: task === 'coding' ? 'claude' : 'gpt',
    mode: 'chat',
    draft: createDraft('chat', { ...defaultModels, chat: task === 'coding' ? 'claude' : 'gpt' }),
    messages: [
      { role: 'user', text: samples[task].prompt },
      { role: 'assistant', task, mode: 'chat', status: 'complete', model: task === 'coding' ? 'claude' : 'gpt', id: `seed-message-${task}` },
    ],
  }));
  const keys = [{ id: 1, name: 'Website portfolio · contoh', value: 'DUMMY_NOT_VALID_STUDIO_0001', revealed: false, revoked: false }];

  function announce(message) {
    const toast = $('#toast');
    clearTimeout(toastTimer);
    toast.textContent = message;
    toast.hidden = false;
    toastTimer = setTimeout(() => { toast.hidden = true; }, 4200);
  }

  function scrollToElement(element) {
    element.scrollIntoView({ behavior: reducedMotion.matches ? 'instant' : 'smooth', block: 'nearest' });
  }

  function closeProductNavigation() {
    $('#product-navigation').classList.remove('is-open');
    $('#menu-toggle').setAttribute('aria-expanded', 'false');
    $('#menu-toggle').setAttribute('aria-label', 'Buka navigasi');
  }

  function updateURL(view, section) {
    const url = new URL(window.location.href);
    url.searchParams.set('view', view);
    if (view === 'console' && section !== 'overview') url.searchParams.set('section', section);
    else url.searchParams.delete('section');
    url.hash = '';
    if (url.href !== window.location.href) window.history.pushState({}, '', url);
  }

  function setConsoleSection(section, { update = true, scroll = true } = {}) {
    currentConsoleSection = consoleSections.includes(section) ? section : 'overview';
    consoleSections.forEach((name) => { $(`#console-${name}`).hidden = name !== currentConsoleSection; });
    $$('.console-navigation [data-console-section]').forEach((button) => {
      if (button.dataset.consoleSection === currentConsoleSection) button.setAttribute('aria-current', 'page');
      else button.removeAttribute('aria-current');
    });
    $('#request-detail').hidden = true;
    if (update) updateURL('console', currentConsoleSection);
    if (update) {
      const heading = $('h1', $(`#console-${currentConsoleSection}`));
      heading.tabIndex = -1;
      heading.focus({ preventScroll: true });
    }
    if (scroll) window.scrollTo({ top: 0, behavior: 'instant' });
  }

  function setView(view, { update = true, scroll = true, section = currentConsoleSection } = {}) {
    const nextView = views.includes(view) ? view : 'landing';
    if (currentView === 'workspace' && nextView !== 'workspace') {
      saveDraft();
      finishResponse('stop');
      closeModelPicker();
      closeHistory();
      setDropState(false);
    }
    currentView = nextView;
    views.forEach((name) => { $(`#view-${name}`).hidden = name !== currentView; });
    document.body.dataset.view = currentView;
    document.title = `UltrAI — ${currentView === 'landing' ? 'Studio' : currentView === 'workspace' ? 'Workspace · Studio' : 'Developer console · Studio'}`;
    $$('[data-view]').filter((element) => element !== document.body).forEach((element) => {
      if (element.dataset.view === currentView) element.setAttribute('aria-current', 'page');
      else element.removeAttribute('aria-current');
    });
    if (currentView === 'console') setConsoleSection(section, { update: false, scroll: false });
    if (update) updateURL(currentView, currentConsoleSection);
    closeProductNavigation();
    if (scroll) window.scrollTo({ top: 0, behavior: 'instant' });
    document.dispatchEvent(new CustomEvent('studio:view', { detail: { view: currentView } }));
  }

  function loadRoute() {
    const params = new URLSearchParams(window.location.search);
    const embedded = params.get('embed') === '1';
    document.body.dataset.embed = String(embedded);
    $('#preview-bar').hidden = embedded;
    setView(params.get('view') || 'landing', { update: false, scroll: false, section: params.get('section') || 'overview' });
    if (window.location.hash === '#models' && currentView === 'landing') $('#models').scrollIntoView();
  }

  function selectTask(task, animate = false) {
    currentTask = samples[task] ? task : 'writing';
    $$('[data-task]').forEach((button) => {
      const active = button.dataset.task === currentTask;
      button.setAttribute('aria-selected', String(active));
      button.tabIndex = active ? 0 : -1;
    });
    $('#workbench-example').setAttribute('aria-labelledby', `task-${currentTask}`);
    $('#sample-prompt').textContent = samples[currentTask].prompt;
    $('#sample-content').innerHTML = samples[currentTask].content;
    $('#workbench-input').value = samples[currentTask].prompt;
    if (animate && !reducedMotion.matches) {
      const sample = $('#sample-content');
      sample.classList.remove('sample-change');
      requestAnimationFrame(() => sample.classList.add('sample-change'));
    }
  }

  function setCode(scope, language) {
    const selectedLanguage = language === 'python' ? 'python' : 'curl';
    const tokens = /#[^\n]*|"(?:[^"\\]|\\.)*"|\b(?:curl|import|with|as|print)\b/g;
    let highlighted = '';
    let cursor = 0;
    for (const match of codeSamples[selectedLanguage].matchAll(tokens)) {
      highlighted += escapeHTML(codeSamples[selectedLanguage].slice(cursor, match.index));
      const tone = match[0][0] === '#' ? 'comment' : match[0][0] === '"' ? 'string' : 'keyword';
      highlighted += `<span class="code-${tone}">${escapeHTML(match[0])}</span>`;
      cursor = match.index + match[0].length;
    }
    $(`#${scope}-code`).innerHTML = highlighted + escapeHTML(codeSamples[selectedLanguage].slice(cursor));
    $$(`[data-code-scope="${scope}"]`).forEach((button) => {
      const active = button.dataset.language === selectedLanguage;
      button.setAttribute('aria-selected', String(active));
      button.tabIndex = active ? 0 : -1;
    });
    $(`#${scope}-code-panel`).setAttribute('aria-labelledby', `${scope}-${selectedLanguage}`);
  }

  async function copyText(text, button, message = 'Contoh disalin. Tidak ada request yang dikirim.') {
    try {
      if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
      await navigator.clipboard.writeText(text);
      const label = $('span', button);
      const oldLabel = label?.textContent;
      if (label) label.textContent = 'Tersalin';
      announce(message);
      if (label) setTimeout(() => { if (label.isConnected) label.textContent = oldLabel; }, 2000);
    } catch {
      const field = document.createElement('textarea');
      field.value = text;
      field.setAttribute('readonly', '');
      field.setAttribute('aria-label', 'Teks contoh untuk disalin');
      field.style.position = 'fixed';
      field.style.left = '-9999px';
      document.body.append(field);
      field.select();
      let copied = false;
      try { copied = document.execCommand('copy'); } catch { /* Browser may deny clipboard access. */ }
      field.remove();
      button.focus({ preventScroll: true });
      announce(copied ? message : 'Clipboard diblokir browser. Pilih teks contoh, lalu salin secara manual.');
    }
  }

  function pickSample(prompt) {
    if (/react|kode|code|koding|javascript|python|komponen|debug|fungsi|button|tombol|api/i.test(prompt)) return 'coding';
    if (/rencana|jadwal|minggu|plan|portfolio|proyek|project|langkah|kerja/i.test(prompt)) return 'planning';
    if (/tulis|rapikan|kopi|brand|caption|cerita|paragraf|draf|draft|pembuka/i.test(prompt)) return 'writing';
    return 'general';
  }

  function createDraft(mode = 'chat', models = defaultModels) {
    return { text: '', files: [], mode, models: { ...models } };
  }

  function currentDraft() {
    return conversations.find(item => item.id === selectedConversation)?.draft || newDraft;
  }

  function saveDraft() {
    currentDraft().text = $('#workspace-input').value;
  }

  function showWorkspaceError(message) {
    $('#workspace-error').textContent = message;
    $('#workspace-error').hidden = !message;
  }

  function renderHistory() {
    const query = $('#history-search').value.trim().toLocaleLowerCase('id');
    const items = conversations.filter(item => item.title.toLocaleLowerCase('id').includes(query));
    $('#conversation-history').innerHTML = items.length ? items.map(item => `<div class="history-entry"><button type="button" class="history-button" data-conversation="${item.id}" title="${escapeHTML(item.title)}"${selectedConversation === item.id ? ' aria-current="true"' : ''}>${icon(item.mode === 'chat' ? 'chat' : item.mode)}<span>${escapeHTML(item.title)}</span></button><button type="button" class="history-delete" data-delete-conversation="${item.id}" aria-label="Hapus percakapan ${escapeHTML(item.title)}" title="Hapus percakapan lokal">${icon('trash')}</button></div>`).join('') : '<p class="history-empty">Tidak ada percakapan yang cocok.</p>';
  }

  function attachmentMarkup(files, removable = false) {
    return files.map(file => `<div class="attachment-chip">${file.preview ? `<img class="attachment-preview" src="${file.preview}" alt="Pratinjau ${escapeHTML(file.name)}">` : `<span class="attachment-file-icon">${icon('file')}</span>`}<span class="attachment-copy"><strong class="attachment-name">${escapeHTML(file.name)}</strong><small>${file.loading ? 'Membaca pratinjau…' : `${Math.max(1, Math.round(file.size / 1024))} KB · lokal`}</small></span>${removable ? `<button type="button" data-remove-attachment="${file.id}" aria-label="Hapus lampiran ${escapeHTML(file.name)}">${icon('close')}</button>` : ''}</div>`).join('');
  }

  function renderAttachments() {
    const list = $('#attachment-list');
    const files = currentDraft().files;
    list.hidden = files.length === 0;
    list.innerHTML = attachmentMarkup(files, true);
  }

  function renderConversation() {
    const conversation = conversations.find(item => item.id === selectedConversation);
    const hasMessages = Boolean(conversation?.messages.length);
    $('#workspace-empty').hidden = hasMessages;
    $('#workspace-prompt-ideas').hidden = hasMessages;
    $('#view-workspace').classList.toggle('has-conversation', hasMessages);
    $('#conversation-title').textContent = conversation?.title || 'Percakapan baru';
    const thread = $('#conversation-thread');
    const focused = document.activeElement;
    const editorState = thread.contains(focused) && focused.matches('.brief-editor')
      ? { id: focused.id, start: focused.selectionStart, end: focused.selectionEnd, direction: focused.selectionDirection, scrollTop: focused.scrollTop }
      : null;
    thread.replaceChildren();
    conversation?.messages.forEach(message => {
      const article = document.createElement('article');
      article.className = `chat-message chat-${message.role}${message.status === 'streaming' ? ' is-streaming' : ''}`;
      if (message.role === 'user') {
        article.innerHTML = `<p>${escapeHTML(message.text)}</p>${message.files?.length ? `<div class="message-attachments">${attachmentMarkup(message.files)}</div>` : ''}<div class="chat-meta">Kamu · pesan lokal</div>`;
      } else {
        const model = modelNames[message.model];
        const byline = `<div class="answer-byline">${icon('mark')}<span>UltrAI</span><span>${message.mode === 'chat' ? 'Contoh respons' : 'Brief lokal'}</span></div>`;
        if (message.mode !== 'chat') {
          article.innerHTML = `${byline}<div class="media-brief"><label for="${message.id}">Brief ${workspaceModes[message.mode].name} · dapat diedit</label><textarea class="brief-editor" id="${message.id}" data-brief-message="${message.id}" rows="10">${escapeHTML(message.brief)}</textarea><div class="response-actions"><button type="button" class="response-copy" data-copy-brief="${message.id}">${icon('copy')}<span>Salin brief</span></button><button type="button" class="response-copy" data-export-brief="${message.id}">${icon('file')}<span>Unduh .txt</span></button></div></div><p class="chat-meta">Brief disusun dari arahanmu, bukan hasil ${escapeHTML(model.name)}. Tidak ada media yang dihasilkan.</p>`;
        } else {
          const complete = message.status === 'complete';
          article.innerHTML = `${byline}<div class="sample-content${complete ? '' : ' streaming-copy'}" id="${message.id}">${complete ? samples[message.task].content : escapeHTML(message.visibleText || '')}</div>${message.status === 'streaming' ? '<div class="generation-meter" aria-hidden="true"><span></span><span></span><span></span></div>' : ''}<p class="chat-meta">${message.status === 'stopped' ? 'Pratinjau dihentikan. ' : ''}${escapeHTML(model.name)} · ${escapeHTML(model.maker)}. Respons tersimpan, bukan AI live.</p><div class="response-actions"><button type="button" class="response-copy" data-copy="${message.id}" data-copy-kind="response"${message.status === 'streaming' ? ' disabled' : ''}>${icon('copy')}<span>Salin contoh</span></button></div>`;
        }
      }
      thread.append(article);
    });
    renderHistory();
    if (editorState) {
      const editor = document.getElementById(editorState.id);
      if (editor?.matches('.brief-editor')) {
        editor.focus({ preventScroll: true });
        editor.setSelectionRange(editorState.start, editorState.end, editorState.direction);
        editor.scrollTop = editorState.scrollTop;
      }
    }
  }

  function updateComposer() {
    const draft = currentDraft();
    draft.text = $('#workspace-input').value;
    const busy = Boolean(activeResponse);
    $('#workspace-submit').disabled = busy || draft.files.some(file => file.loading) || (!draft.text.trim() && draft.files.length === 0);
    $('#workspace-submit').hidden = busy;
    $('#stop-generation').hidden = !busy;
    $('#attach-file').disabled = busy || draft.files.length >= maxAttachments;
    $('#workspace-input').readOnly = busy;
    $('#view-workspace').classList.toggle('is-responding', busy);
  }

  function updateMode() {
    const draft = currentDraft();
    workspaceMode = draft.mode;
    const config = workspaceModes[workspaceMode];
    $('#view-workspace').dataset.mode = workspaceMode;
    $('#workspace-mode-title').innerHTML = `${escapeHTML(config.title)}<br><span>${escapeHTML(config.accent)}</span>`;
    $('#workspace-mode-description').textContent = config.description;
    $('#workspace-mode-label').textContent = config.label;
    $('#workspace-input').placeholder = config.placeholder;
    $('#workspace-submit span').textContent = config.submit;
    $('#media-note').hidden = workspaceMode === 'chat';
    $$('#workspace-mode-tabs [data-mode]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.mode === workspaceMode)));
    $('#workspace-prompt-ideas').innerHTML = config.suggestions.map(item => `<button type="button" data-suggestion="${item.id}">${icon(item.icon)}<span><strong>${escapeHTML(item.title)}</strong><small>${escapeHTML(item.detail)}</small></span>${icon('arrow')}</button>`).join('');
    const selected = draft.models[workspaceMode];
    $('#workspace-model').value = selected;
    $('#model-trigger-name').textContent = modelNames[selected].name;
    $('#model-attribution').textContent = `${modelNames[selected].name} · ${modelNames[selected].maker} · pilihan demo`;
    updateComposer();
  }

  function setMode(mode) {
    if (!workspaceModes[mode]) return;
    saveDraft();
    finishResponse('stop');
    currentDraft().mode = mode;
    updateMode();
    document.dispatchEvent(new CustomEvent('studio:mode', { detail: { mode } }));
  }

  function setModel(id) {
    if (!modelNames[id]) return;
    saveDraft();
    finishResponse('stop');
    const model = modelNames[id];
    currentDraft().mode = model.mode;
    currentDraft().models[model.mode] = id;
    updateMode();
    document.dispatchEvent(new CustomEvent('studio:mode', { detail: { mode: model.mode } }));
  }

  function renderModels() {
    const query = $('#model-search').value.trim().toLowerCase();
    const entries = Object.entries(modelNames).filter(([, model]) => (pickerFilter === 'all' || model.mode === pickerFilter) && `${model.name} ${model.maker}`.toLowerCase().includes(query));
    const groups = new Map();
    entries.forEach(entry => {
      const maker = entry[1].maker;
      if (!groups.has(maker)) groups.set(maker, []);
      groups.get(maker).push(entry);
    });
    $('#model-list').innerHTML = [...groups].map(([maker, models]) => `<section class="model-group"><h3 class="model-group-name">${escapeHTML(maker)}</h3><div class="model-options">${models.map(([id, model]) => `<button type="button" class="model-option" data-model-id="${id}" aria-pressed="${$('#workspace-model').value === id}"><span class="model-monogram">${model.monogram}</span><span class="model-option-copy"><strong>${escapeHTML(model.name)}</strong><small>${workspaceModes[model.mode].name} · katalog contoh</small></span>${icon('arrow')}</button>`).join('')}</div></section>`).join('');
    $('#model-empty').hidden = entries.length !== 0;
    $('#picker-count').textContent = `${entries.length} model contoh`;
    $$('#model-picker [data-model-filter]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.modelFilter === pickerFilter)));
  }

  function openModelPicker() {
    pickerFilter = 'all';
    $('#model-search').value = '';
    renderModels();
    $('#model-picker').showModal();
    $('#model-trigger').setAttribute('aria-expanded', 'true');
    $('#model-search').focus();
  }

  function closeModelPicker() {
    if (!$('#model-picker').open) return;
    $('#model-picker').close();
    $('#model-trigger').setAttribute('aria-expanded', 'false');
    $('#model-trigger').focus({ preventScroll: true });
  }

  function closeHistory() {
    $('.workspace-rail').classList.remove('is-open');
    $('#history-toggle').setAttribute('aria-expanded', 'false');
  }

  function loadConversation(id) {
    saveDraft();
    finishResponse('stop');
    selectedConversation = id;
    showWorkspaceError('');
    $('#generation-status').textContent = '';
    const draft = currentDraft();
    $('#workspace-input').value = draft.text;
    updateMode();
    renderAttachments();
    renderConversation();
    closeHistory();
    $('#workspace-input').focus({ preventScroll: true });
  }

  function newConversation() {
    saveDraft();
    finishResponse('stop');
    selectedConversation = null;
    newDraft = createDraft();
    $('#workspace-input').value = '';
    showWorkspaceError('');
    $('#generation-status').textContent = '';
    updateMode();
    renderAttachments();
    renderConversation();
    closeHistory();
    $('#workspace-input').focus({ preventScroll: true });
  }

  function plainSample(task) {
    const content = document.createElement('div');
    content.innerHTML = samples[task].content.replace(/<br\s*\/?\s*>/g, '\n').replace(/<\/(h3|p|li|pre)>/g, '</$1>\n\n');
    return content.textContent.trim();
  }

  function finishResponse(phase = 'complete') {
    if (!activeResponse) return;
    const response = activeResponse;
    clearTimeout(response.timer);
    activeResponse = null;
    response.message.status = phase === 'stop' ? 'stopped' : 'complete';
    if (phase === 'complete') response.message.visibleText = response.text;
    if (response.conversationId === selectedConversation) renderConversation();
    $('#generation-status').textContent = phase === 'stop' ? 'Pratinjau dihentikan. Teks yang sudah tampil tetap tersimpan.' : 'Contoh selesai. Tidak ada prompt atau file dikirim ke AI.';
    updateComposer();
    document.dispatchEvent(new CustomEvent('studio:response', { detail: { phase } }));
  }

  function streamExample(message, conversationId) {
    const text = plainSample(message.task);
    message.visibleText = text.slice(0, 18);
    activeResponse = { message, conversationId, text, index: 18, timer: null };
    $('#generation-status').textContent = 'Memutar respons contoh… Ini bukan AI live.';
    renderConversation();
    updateComposer();
    document.dispatchEvent(new CustomEvent('studio:response', { detail: { phase: 'start' } }));
    if (reducedMotion.matches || document.hidden) { finishResponse(); return; }
    const response = activeResponse;
    const advance = () => {
      if (activeResponse !== response) return;
      response.index = Math.min(response.index + 11, text.length);
      message.visibleText = text.slice(0, response.index);
      const output = document.getElementById(message.id);
      if (output) output.textContent = message.visibleText;
      if (response.index >= text.length) finishResponse();
      else response.timer = setTimeout(advance, 55);
    };
    response.timer = setTimeout(advance, 450);
  }

  function makeBrief(prompt, mode, files) {
    return `BRIEF ${workspaceModes[mode].name.toUpperCase()} — ULTRAI STUDIO\n\nArahan\n${prompt}\n\nReferensi\n${files.length ? files.map(file => `- ${file.name}`).join('\n') : 'Belum ada lampiran.'}\n\nDetail untuk dilengkapi\n${mode === 'image' ? 'Subjek:\nKomposisi:\nCahaya dan warna:\nRasio gambar:' : mode === 'video' ? 'Durasi:\nAdegan pembuka:\nGerak kamera:\nAdegan penutup:\nRasio video:' : 'Naskah:\nKarakter suara:\nTempo:\nDurasi:\nPelafalan:'}\n\nBrief lokal, bukan hasil model. Edit sebelum digunakan.`;
  }

  function submitMessage(event) {
    event.preventDefault();
    if (activeResponse) return;
    saveDraft();
    const draft = currentDraft();
    if (draft.files.some(file => file.loading)) { showWorkspaceError('Tunggu sampai pratinjau lampiran selesai dibaca.'); return; }
    if (!draft.text.trim() && !draft.files.length) { showWorkspaceError('Tulis pesan atau lampirkan bahan terlebih dahulu.'); $('#workspace-input').focus(); return; }
    showWorkspaceError('');
    const prompt = draft.text.trim() || 'Lihat lampiran contoh saya.';
    const mode = draft.mode;
    const model = draft.models[mode];
    let conversation = conversations.find(item => item.id === selectedConversation);
    if (!conversation) {
      conversation = { id: `conversation-${nextConversation++}`, title: prompt.length > 40 ? `${prompt.slice(0, 39)}…` : prompt, model, mode, draft, messages: [] };
      conversations.unshift(conversation);
      selectedConversation = conversation.id;
      newDraft = createDraft();
    }
    const files = draft.files;
    conversation.model = model;
    conversation.mode = mode;
    conversation.messages.push({ role: 'user', text: prompt, files });
    const message = { role: 'assistant', id: `response-${nextMessage++}`, model, mode, task: pickSample(prompt), status: mode === 'chat' ? 'streaming' : 'complete' };
    if (mode !== 'chat') message.brief = makeBrief(prompt, mode, files);
    conversation.messages.push(message);
    draft.text = '';
    draft.files = [];
    $('#workspace-input').value = '';
    renderAttachments();
    if (mode === 'chat') streamExample(message, conversation.id);
    else { renderConversation(); updateComposer(); $('#generation-status').textContent = 'Brief lokal siap diedit, disalin, atau diunduh. Tidak ada media yang dihasilkan.'; }
    $('.chat-assistant:last-child', $('#conversation-thread'))?.scrollIntoView({ behavior: 'instant', block: 'nearest' });
  }

  function setDropState(active) {
    $('#drop-overlay').hidden = !active;
    $('.workspace-main').classList.toggle('is-dragging', active);
  }

  async function processFiles(fileList) {
    if (activeResponse) { showWorkspaceError('Hentikan respons contoh sebelum menambahkan lampiran.'); return; }
    const draft = currentDraft();
    const errors = [];
    for (const file of Array.from(fileList)) {
      if (draft.files.length >= maxAttachments) { errors.push('Maksimal 5 lampiran.'); break; }
      const extension = file.name.split('.').pop().toLowerCase();
      const type = file.type || attachmentTypes[extension];
      if (!allowedFileTypes.has(type)) { errors.push(`${file.name}: format tidak didukung.`); continue; }
      if (file.size > maxFileSize) { errors.push(`${file.name}: ukuran melebihi 20 MB.`); continue; }
      const attachment = { id: `file-${nextAttachment++}`, name: file.name, size: file.size, type, loading: type.startsWith('image/') };
      draft.files.push(attachment);
      if (attachment.loading) {
        const reader = new FileReader();
        pendingReaders.add(reader);
        reader.onload = () => { attachment.preview = String(reader.result); attachment.loading = false; pendingReaders.delete(reader); if (draft === currentDraft()) { renderAttachments(); updateComposer(); } };
        reader.onerror = reader.onabort = () => { pendingReaders.delete(reader); const index = draft.files.indexOf(attachment); if (index !== -1) draft.files.splice(index, 1); if (draft === currentDraft()) { renderAttachments(); updateComposer(); showWorkspaceError('Pratinjau gambar gagal dibaca. Pilih ulang file.'); } };
        reader.readAsDataURL(file);
      }
    }
    if (draft === currentDraft()) { renderAttachments(); updateComposer(); showWorkspaceError(errors.join(' ')); }
  }

  function findMessage(id) {
    return conversations.flatMap(item => item.messages).find(message => message.id === id);
  }

  function exportBrief(id) {
    const message = findMessage(id);
    if (!message?.brief) return;
    const url = URL.createObjectURL(new Blob([message.brief], { type: 'text/plain;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `ultrai-${message.mode}-brief.txt`;
    link.click();
    const timer = setTimeout(() => { URL.revokeObjectURL(url); downloadURLs.delete(url); }, 1000);
    downloadURLs.set(url, timer);
    announce('Brief lokal diunduh. Tidak ada media yang dihasilkan.');
  }

  function requestRows(rows) {
    if (!rows.length) return '<tr><td colspan="5" class="empty-requests">Tidak ada request contoh yang sesuai. Ubah pencarian atau status.</td></tr>';
    return rows.map((request) => `<tr><td><button type="button" class="request-link" data-request="${request.id}">${request.id}</button></td><td>${request.model}</td><td><span class="status-code ${request.status === 200 ? 'status-success' : 'status-error'}">${request.status} ${request.status === 200 ? 'OK' : 'Unauthorized'}</span></td><td class="number-cell">${request.tokens}</td><td class="number-cell">${request.time}</td></tr>`).join('');
  }

  function filterRequests() {
    const query = $('#request-search').value.trim().toLowerCase();
    const status = $('#request-status').value;
    const filtered = requests.filter((request) => (status === 'all' || String(request.status) === status) && `${request.id} ${request.model}`.toLowerCase().includes(query));
    $('#all-request-rows').innerHTML = requestRows(filtered);
    $('#request-count').textContent = `${filtered.length} dari ${requests.length} request contoh. Waktu dan token adalah data sintetis, bukan metrik layanan.`;
  }

  function showRequest(id) {
    const request = requests.find((item) => item.id === id);
    if (!request) return;
    const fields = [
      ['ID contoh', `<code>${request.id}</code>`],
      ['Endpoint', '<code>POST https://api.ultrai.id/v1/chat/completions</code>'],
      ['Model pada payload', '<code>auto</code> · tidak ada model yang dipanggil'],
      ['Status sintetis', `${request.status} ${request.status === 200 ? 'OK' : 'Unauthorized'}`],
      ['Token contoh', String(request.tokens)],
      ['Isi contoh', escapeHTML(request.message)],
    ];
    $('#request-detail-content').innerHTML = fields.map(([label, value]) => `<dt>${label}</dt><dd>${value}</dd>`).join('');
    $('#request-detail').hidden = false;
    requestDetailTrigger = document.activeElement;
    $('#close-request-detail').focus({ preventScroll: true });
    requestAnimationFrame(() => scrollToElement($('#request-detail')));
  }

  function renderKeys() {
    $('#key-list').innerHTML = keys.map((key) => `<article class="key-row${key.revoked ? ' revoked' : ''}"><div><h2>${escapeHTML(key.name)}</h2><code>${key.revoked ? 'Key dummy dicabut secara lokal' : key.revealed ? key.value : 'DUMMY_••••••••••••••••_' + String(key.id).padStart(4, '0')}</code><p class="small-note">${key.revoked ? 'Dicabut dalam demo' : 'Dummy · tidak valid untuk API'} · hanya untuk preview</p></div><div class="key-actions"><button type="button" data-key-action="reveal" data-key-id="${key.id}" aria-pressed="${key.revealed}"${key.revoked ? ' disabled' : ''}>${key.revealed ? 'Sembunyikan' : 'Tampilkan'}</button><button type="button" data-key-action="copy" data-key-id="${key.id}"${key.revoked ? ' disabled' : ''}>Salin dummy</button><button type="button" class="revoke-key" data-key-action="revoke" data-key-id="${key.id}"${key.revoked ? ' disabled' : ''}>${key.revoked ? 'Dicabut' : 'Cabut contoh'}</button></div></article>`).join('');
  }

  document.addEventListener('click', (event) => {
    const viewLink = event.target.closest('[data-view]');
    if (viewLink && viewLink !== document.body && views.includes(viewLink.dataset.view)) {
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      setView(viewLink.dataset.view);
      $('#main').focus({ preventScroll: true });
      return;
    }
    const scrollLink = event.target.closest('[data-scroll]');
    if (scrollLink) {
      event.preventDefault();
      setView('landing', { scroll: false });
      const target = $(`#${scrollLink.dataset.scroll}`);
      target.scrollIntoView({ behavior: reducedMotion.matches ? 'instant' : 'smooth', block: 'start' });
      return;
    }
    const sectionButton = event.target.closest('[data-console-section]');
    if (sectionButton) {
      if (currentView !== 'console') setView('console', { section: sectionButton.dataset.consoleSection });
      else setConsoleSection(sectionButton.dataset.consoleSection);
      return;
    }
    const taskButton = event.target.closest('[data-task]');
    if (taskButton) { selectTask(taskButton.dataset.task, true); return; }
    const codeButton = event.target.closest('[data-code-scope]');
    if (codeButton) { setCode(codeButton.dataset.codeScope, codeButton.dataset.language); return; }
    const copyButton = event.target.closest('[data-copy]');
    if (copyButton) {
      const text = $(`[id="${copyButton.dataset.copy}"]`).innerText;
      copyText(text, copyButton, copyButton.dataset.copyKind === 'response' ? 'Jawaban contoh disalin.' : undefined);
      return;
    }
    const historyButton = event.target.closest('[data-conversation]');
    if (historyButton) { loadConversation(historyButton.dataset.conversation); return; }
    const deleteButton = event.target.closest('[data-delete-conversation]');
    if (deleteButton) {
      const index = conversations.findIndex(item => item.id === deleteButton.dataset.deleteConversation);
      if (index !== -1) {
        if (conversations[index].id === selectedConversation) newConversation();
        conversations.splice(index, 1);
        renderHistory();
        $('#history-search').focus({ preventScroll: true });
        announce('Percakapan contoh dihapus dari preview lokal.');
      }
      return;
    }
    const modeButton = event.target.closest('[data-mode]');
    if (modeButton?.tagName === 'BUTTON') { setMode(modeButton.dataset.mode); return; }
    const modelFilter = event.target.closest('[data-model-filter]');
    if (modelFilter) { pickerFilter = modelFilter.dataset.modelFilter; renderModels(); return; }
    const modelOption = event.target.closest('[data-model-id]');
    if (modelOption) { setModel(modelOption.dataset.modelId); closeModelPicker(); return; }
    const removeAttachment = event.target.closest('[data-remove-attachment]');
    if (removeAttachment) {
      currentDraft().files = currentDraft().files.filter(file => file.id !== removeAttachment.dataset.removeAttachment);
      renderAttachments();
      updateComposer();
      $('#attach-file').focus({ preventScroll: true });
      return;
    }
    const copyBrief = event.target.closest('[data-copy-brief]');
    if (copyBrief) { copyText(findMessage(copyBrief.dataset.copyBrief)?.brief || '', copyBrief, 'Brief lokal disalin.'); return; }
    const downloadBrief = event.target.closest('[data-export-brief]');
    if (downloadBrief) { exportBrief(downloadBrief.dataset.exportBrief); return; }
    const suggestion = event.target.closest('[data-suggestion]');
    if (suggestion) {
      const idea = workspaceModes[workspaceMode].suggestions.find(item => item.id === suggestion.dataset.suggestion);
      if (!idea) return;
      $('#workspace-input').value = idea.prompt;
      updateComposer();
      $('#workspace-input').focus({ preventScroll: true });
      scrollToElement($('#workspace-form'));
      return;
    }
    const requestButton = event.target.closest('[data-request]');
    if (requestButton) { showRequest(requestButton.dataset.request); return; }
    const keyButton = event.target.closest('[data-key-action]');
    if (keyButton) {
      const key = keys.find((item) => item.id === Number(keyButton.dataset.keyId));
      if (!key || key.revoked) return;
      const action = keyButton.dataset.keyAction;
      if (action === 'copy') { copyText(key.value, keyButton, 'Key dummy disalin. Nilai ini tidak valid untuk API.'); return; }
      if (action === 'reveal') key.revealed = !key.revealed;
      if (action === 'revoke') { key.revoked = true; key.revealed = false; announce('Key contoh dicabut secara lokal. Tidak ada akun yang diubah.'); }
      renderKeys();
      if (action === 'reveal') $(`[data-key-id="${key.id}"][data-key-action="reveal"]`).focus({ preventScroll: true });
      if (action === 'revoke') $('#key-name').focus({ preventScroll: true });
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') { closeProductNavigation(); closeHistory(); closeModelPicker(); }
    const tab = event.target.closest('[role="tab"]');
    if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    const siblings = $$('[role="tab"]', tab.closest('[role="tablist"]'));
    const index = siblings.indexOf(tab);
    const targetIndex = event.key === 'Home' ? 0 : event.key === 'End' ? siblings.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + siblings.length) % siblings.length;
    event.preventDefault();
    siblings[targetIndex].focus();
    siblings[targetIndex].click();
  });

  $('#menu-toggle').addEventListener('click', () => {
    const open = $('#product-navigation').classList.toggle('is-open');
    $('#menu-toggle').setAttribute('aria-expanded', String(open));
    $('#menu-toggle').setAttribute('aria-label', open ? 'Tutup navigasi' : 'Buka navigasi');
  });
  $('#workbench-form').addEventListener('submit', (event) => {
    event.preventDefault();
    const prompt = $('#workbench-input').value.trim();
    if (!prompt) { announce('Tulis ide singkat untuk melihat contoh, atau pilih jenis pekerjaan di atas.'); $('#workbench-input').focus(); return; }
    $('#sample-prompt').textContent = prompt;
    $('#sample-content').innerHTML = `${samples[currentTask].content}<p class="draft-note">Prompt Anda ditampilkan di atas. Jawaban ini contoh tersimpan, bukan hasil AI.</p>`;
    announce('Contoh ditampilkan. Prompt tidak dikirim ke model.');
  });
  $('#workspace-input').addEventListener('input', () => { showWorkspaceError(''); updateComposer(); });
  $('#workspace-input').addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && !$('#workspace-submit').disabled) {
      event.preventDefault();
      $('#workspace-form').requestSubmit();
    }
  });
  $('#workspace-form').addEventListener('submit', submitMessage);
  $('#workspace-model').addEventListener('change', (event) => setModel(event.target.value));
  $('#new-conversation').addEventListener('click', newConversation);
  $('#history-search').addEventListener('input', renderHistory);
  $('#model-trigger').addEventListener('click', openModelPicker);
  $('#model-picker-close').addEventListener('click', closeModelPicker);
  $('#model-picker').addEventListener('cancel', event => { event.preventDefault(); closeModelPicker(); });
  $('#model-search').addEventListener('input', renderModels);
  $('#workspace-theme').addEventListener('click', () => {
    const dark = document.body.dataset.workspaceTheme !== 'dark';
    document.body.dataset.workspaceTheme = dark ? 'dark' : 'light';
    $('#workspace-theme').setAttribute('aria-pressed', String(dark));
    $('#workspace-theme').setAttribute('aria-label', dark ? 'Gunakan tema terang' : 'Gunakan tema gelap');
    $('#workspace-theme use').setAttribute('href', dark ? '#i-sun' : '#i-moon');
  });
  $('#sidebar-collapse').addEventListener('click', () => {
    const collapsed = $('#view-workspace').classList.toggle('is-rail-collapsed');
    $('#sidebar-collapse').setAttribute('aria-expanded', String(!collapsed));
    $('#sidebar-collapse').setAttribute('aria-label', collapsed ? 'Perluas sidebar' : 'Ringkas sidebar');
  });
  $('#stop-generation').addEventListener('click', () => { finishResponse('stop'); $('#workspace-input').focus({ preventScroll: true }); });
  $('#attach-file').addEventListener('click', () => $('#workspace-file').click());
  $('#workspace-file').addEventListener('change', event => { processFiles(event.target.files); event.target.value = ''; });
  $('#workspace-input').addEventListener('paste', event => {
    const files = [...(event.clipboardData?.items || [])].filter(item => item.kind === 'file').map(item => item.getAsFile()).filter(Boolean);
    if (files.length) { event.preventDefault(); processFiles(files); }
  });
  const dropZone = $('.workspace-main');
  dropZone.addEventListener('dragover', event => {
    if (!event.dataTransfer.types.includes('Files')) return;
    event.preventDefault();
    setDropState(true);
  });
  dropZone.addEventListener('dragleave', event => {
    if (!event.relatedTarget || !dropZone.contains(event.relatedTarget)) setDropState(false);
  });
  dropZone.addEventListener('drop', event => {
    if (!event.dataTransfer.files.length) return;
    event.preventDefault();
    setDropState(false);
    processFiles(event.dataTransfer.files);
  });
  $('#conversation-thread').addEventListener('input', event => {
    if (!event.target.matches('[data-brief-message]')) return;
    const message = findMessage(event.target.dataset.briefMessage);
    if (message) message.brief = event.target.value;
  });
  document.addEventListener('visibilitychange', () => { if (document.hidden) finishResponse(); });
  reducedMotion.addEventListener('change', () => { if (reducedMotion.matches) finishResponse(); });
  window.addEventListener('pagehide', () => {
    finishResponse('stop');
    pendingReaders.forEach(reader => reader.abort());
    downloadURLs.forEach((timer, url) => { clearTimeout(timer); URL.revokeObjectURL(url); });
    downloadURLs.clear();
  });
  $('#history-toggle').addEventListener('click', () => {
    const open = $('.workspace-rail').classList.toggle('is-open');
    $('#history-toggle').setAttribute('aria-expanded', String(open));
  });
  $('#workspace-guide-toggle').addEventListener('click', () => {
    const guide = $('#workspace-guide');
    guide.hidden = !guide.hidden;
    $('#workspace-guide-toggle').setAttribute('aria-expanded', String(!guide.hidden));
    closeHistory();
    if (!guide.hidden) scrollToElement(guide);
  });
  $('#workspace-guide-close').addEventListener('click', () => {
    $('#workspace-guide').hidden = true;
    $('#workspace-guide-toggle').setAttribute('aria-expanded', 'false');
  });
  $('#show-response').addEventListener('click', () => {
    $('#sample-response').hidden = false;
    $('#show-response').setAttribute('aria-expanded', 'true');
    scrollToElement($('#sample-response'));
  });
  $('#hide-response').addEventListener('click', () => {
    $('#sample-response').hidden = true;
    $('#show-response').setAttribute('aria-expanded', 'false');
    $('#show-response').focus({ preventScroll: true });
  });
  $('#close-request-detail').addEventListener('click', () => {
    $('#request-detail').hidden = true;
    if (requestDetailTrigger?.isConnected) requestDetailTrigger.focus({ preventScroll: true });
  });
  $('#request-search').addEventListener('input', filterRequests);
  $('#request-status').addEventListener('change', filterRequests);
  $('#key-form').addEventListener('submit', (event) => {
    event.preventDefault();
    const input = $('#key-name');
    const name = input.value.trim();
    if (!name) { input.setCustomValidity('Beri nama key contoh, misalnya Website portfolio.'); input.reportValidity(); return; }
    input.setCustomValidity('');
    const id = nextKey++;
    keys.unshift({ id, name, value: `DUMMY_NOT_VALID_STUDIO_${String(id).padStart(4, '0')}`, revealed: true, revoked: false });
    renderKeys();
    announce('Key dummy dibuat untuk preview. Tidak dapat digunakan pada API.');
  });
  $('#key-name').addEventListener('input', (event) => event.target.setCustomValidity(''));
  window.addEventListener('popstate', loadRoute);

  $('#workspace-model').innerHTML = Object.entries(modelNames).map(([id, model]) => `<option value="${id}">${escapeHTML(model.name)} · ${escapeHTML(model.maker)}</option>`).join('');
  document.body.dataset.workspaceTheme = 'light';
  updateMode();
  renderAttachments();
  selectTask('writing');
  setCode('landing', 'curl');
  setCode('console', 'curl');
  renderHistory();
  renderKeys();
  $('#recent-request-rows').innerHTML = requestRows(requests.slice(0, 3));
  filterRequests();
  loadRoute();
})();
