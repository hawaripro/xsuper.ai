(() => {
  'use strict';

  const tasks = {
    writing: {
      label: 'Menulis',
      shortLabel: 'Contoh menulis',
      title: 'Narasi sebuah ide',
      prompt: 'Tulis pembuka cerita tentang ide kecil yang mengubah keseharian.',
      specimen: '<h2>Hal kecil.<br>Arti yang besar.</h2><p>Setiap pagi, Nara melewati bangku kosong di ujung gang. Suatu hari, ia membawa sebuah buku dan meninggalkannya di sana.</p><p>Di halaman pertama, hanya ada satu kalimat: <em>“Kalau sudah selesai, ceritakan pada seseorang.”</em></p>',
      answer: '<h2>Hal kecil. Arti yang besar.</h2><p>Setiap pagi, Nara melewati bangku kosong di ujung gang. Suatu hari, ia membawa sebuah buku dan meninggalkannya di sana.</p><p>Di halaman pertama, hanya ada satu kalimat: <em>“Kalau sudah selesai, ceritakan pada seseorang.”</em></p><p>Seminggu kemudian, ada tiga buku di bangku itu. Lalu sebuah catatan. Lalu seseorang yang duduk lebih lama dari biasanya. Tidak ada yang merencanakan sebuah perpustakaan. Mereka hanya memberi sebuah ide tempat untuk tumbuh.</p><p><strong>Arah berikutnya:</strong> lanjutkan dari sudut pandang orang yang menemukan buku pertama. Apa yang membuatnya berhenti?</p>'
    },
    coding: {
      label: 'Membuat kode',
      shortLabel: 'Contoh kode',
      title: 'Fungsi validasi email',
      prompt: 'Buat fungsi JavaScript untuk memeriksa bentuk dasar alamat email, lalu jelaskan batasannya.',
      specimen: '<h2>Dari logika<br>ke langkah nyata.</h2><pre><code>function hasBasicEmailShape(value) {\n  return typeof value === "string"\n    &amp;&amp; /^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/\n      .test(value);\n}</code></pre><p>Pemeriksaan bentuk, bukan bukti bahwa alamat benar-benar ada.</p>',
      answer: '<h2>Periksa bentuknya. Kenali batasnya.</h2><p>Fungsi berikut memeriksa pola dasar alamat email. Ia mengharuskan teks sebelum dan sesudah <code>@</code>, serta titik pada bagian domain.</p><pre><code>function hasBasicEmailShape(value) {\n  return typeof value === "string"\n    &amp;&amp; /^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/\n      .test(value);\n}\n\nhasBasicEmailShape("nara@example.com");\n// true\nhasBasicEmailShape("bukan-email");\n// false</code></pre><p><strong>Batasan:</strong> contoh sederhana ini bukan validator lengkap seluruh standar email. Ia tidak membuktikan alamat aktif atau bisa menerima pesan. Verifikasi melalui email tetap diperlukan pada alur pendaftaran nyata.</p>'
    },
    planning: {
      label: 'Merencanakan',
      shortLabel: 'Contoh rencana',
      title: 'Rencana pekan pertama',
      prompt: 'Bantu susun rencana satu pekan untuk mulai menulis buletin pribadi.',
      specimen: '<h2>Beri ide Anda<br>sebuah arah.</h2><ol><li><strong>Senin — pilih satu pertanyaan.</strong>Apa yang ingin Anda ceritakan?</li><li><strong>Rabu — bentuk draf pertama.</strong>Tulis tanpa mengejar sempurna.</li><li><strong>Jumat — bagikan dan dengarkan.</strong>Minta satu tanggapan yang jujur.</li></ol>',
      answer: '<h2>Satu pekan. Satu cerita yang selesai.</h2><p>Mulai kecil agar Anda bisa mengenali ritme yang nyaman. Berikut contoh rencana untuk edisi pertama buletin pribadi.</p><ol><li><strong>Senin — tentukan pembaca dan pertanyaan.</strong> Bayangkan satu orang yang akan membaca. Pilih topik yang benar-benar ingin Anda ceritakan.</li><li><strong>Selasa — kumpulkan bahan.</strong> Catat satu pengalaman, satu temuan, dan satu pertanyaan terbuka. Simpan sumber yang perlu diperiksa.</li><li><strong>Rabu — tulis draf.</strong> Mulai dari bagian yang paling jelas. Biarkan pembuka menunggu jika perlu.</li><li><strong>Kamis — sunting dengan jarak.</strong> Baca keras-keras. Hapus pengulangan, periksa fakta, dan buat judul yang spesifik.</li><li><strong>Jumat — bagikan versi pertama.</strong> Minta tanggapan dari pembaca pilihan: bagian mana yang paling berguna, dan mana yang belum jelas?</li></ol><p><strong>Catatan:</strong> ukuran kemajuan minggu ini adalah satu edisi yang selesai, bukan jumlah pembaca.</p>'
    }
  };

  const models = {
    claude: 'Claude — Anthropic',
    gpt: 'GPT — OpenAI',
    gemini: 'Gemini — Google'
  };

  const apiExamples = {
    python: '# Contoh integrasi — tidak dieksekusi\nfrom openai import OpenAI\n\nclient = OpenAI(\n    base_url="https://api.ultrai.id/v1",\n    api_key="YOUR_API_KEY",\n)\n\nresponse = client.chat.completions.create(\n    model="MODEL_ID",\n    messages=[{\n        "role": "user",\n        "content": "Mulai dari satu ide."\n    }],\n)\nprint(response.choices[0].message.content)',
    curl: '# Contoh integrasi — tidak dieksekusi\ncurl https://api.ultrai.id/v1/chat/completions \\\n  -H "Authorization: Bearer YOUR_API_KEY" \\\n  -H "Content-Type: application/json" \\\n  -d \'{\n    "model": "MODEL_ID",\n    "messages": [{\n      "role": "user",\n      "content": "Mulai dari satu ide."\n    }]\n  }\''
  };

  const validViews = ['landing', 'workspace', 'console'];
  const validPanels = ['overview', 'keys', 'usage', 'docs'];
  const promptInput = document.getElementById('workspace-prompt');
  const taskSelect = document.getElementById('workspace-task');
  const modelSelect = document.getElementById('workspace-model');
  const submitDemo = document.getElementById('submit-demo');
  const composerFeedback = document.getElementById('composer-feedback');
  const welcome = document.getElementById('workspace-welcome');
  const conversation = document.getElementById('conversation');
  const specimenContent = document.getElementById('specimen-content');
  const menuToggle = document.querySelector('.menu-toggle');
  const productNav = document.getElementById('product-nav');
  const historyToggle = document.getElementById('history-toggle');
  const workspaceRail = document.getElementById('workspace-rail');
  let activeView = 'landing';
  let activeTask = 'writing';
  let activePanel = 'overview';
  let activeLanguage = 'python';

  function setTabState(selector, value, attribute) {
    document.querySelectorAll(selector).forEach((tab) => {
      const selected = tab.getAttribute(attribute) === value;
      tab.setAttribute('aria-selected', String(selected));
      tab.tabIndex = selected ? 0 : -1;
    });
  }

  function closeProductMenu() {
    productNav.classList.remove('is-open');
    menuToggle.setAttribute('aria-expanded', 'false');
  }

  function closeHistory() {
    workspaceRail.classList.remove('is-open');
    historyToggle.setAttribute('aria-expanded', 'false');
  }

  function setConsolePanel(panel, updateUrl = false, focus = false) {
    activePanel = validPanels.includes(panel) ? panel : 'overview';
    document.querySelectorAll('.console-panel').forEach((element) => {
      element.hidden = element.id !== `console-${activePanel}`;
    });
    document.querySelectorAll('.console-nav [data-console-panel]').forEach((button) => {
      if (button.dataset.consolePanel === activePanel) button.setAttribute('aria-current', 'page');
      else button.removeAttribute('aria-current');
    });
    if (updateUrl) {
      const url = new URL(window.location.href);
      url.searchParams.set('panel', activePanel);
      history.pushState(null, '', url);
    }
    if (focus) {
      const heading = document.querySelector(`#console-${activePanel} h1`);
      heading.focus({ preventScroll: true });
      document.querySelector('.console-main').scrollIntoView({ block: 'start' });
    }
  }

  function renderView(view, { focus = false, scrollTarget = '' } = {}) {
    activeView = validViews.includes(view) ? view : 'landing';
    document.body.dataset.view = activeView;
    document.querySelectorAll('.view').forEach((section) => {
      section.hidden = section.id !== `view-${activeView}`;
    });
    document.querySelectorAll('[data-nav-view]').forEach((link) => {
      if (link.dataset.navView === activeView) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
    const names = { landing: 'Dari satu ide. Ke banyak kemungkinan.', workspace: 'Workspace', console: 'Developer console' };
    document.title = `UltrAI — ${names[activeView]} · Atlas`;
    closeProductMenu();
    closeHistory();
    if (scrollTarget) {
      requestAnimationFrame(() => document.getElementById(scrollTarget)?.scrollIntoView({ block: 'start' }));
    } else if (focus) {
      window.scrollTo({ top: 0, behavior: 'instant' });
      const visibleHeading = activeView === 'console'
        ? document.querySelector(`#console-${activePanel} h1`)
        : document.querySelector(`#view-${activeView} h1`);
      if (visibleHeading && !visibleHeading.closest('[hidden]')) visibleHeading.focus({ preventScroll: true });
    }
  }

  function navigate(view, scrollTarget = '') {
    const url = new URL(window.location.href);
    url.searchParams.set('view', view);
    if (view !== 'console') url.searchParams.delete('panel');
    else url.searchParams.set('panel', activePanel);
    url.hash = scrollTarget;
    history.pushState(null, '', url);
    renderView(view, { focus: true, scrollTarget });
  }

  function loadRoute() {
    const url = new URL(window.location.href);
    document.querySelector('.preview-bar').hidden = url.searchParams.get('embed') === '1';
    setConsolePanel(url.searchParams.get('panel') || 'overview');
    renderView(url.searchParams.get('view') || 'landing', {
      scrollTarget: url.hash === '#models' ? 'models' : ''
    });
  }

  function setSpecimen(task) {
    if (!tasks[task]) return;
    setTabState('[data-specimen]', task, 'data-specimen');
    specimenContent.setAttribute('aria-labelledby', `specimen-tab-${task}`);
    document.getElementById('specimen-prompt').textContent = tasks[task].prompt;
    document.getElementById('specimen-answer').innerHTML = tasks[task].specimen;
    document.getElementById('specimen-continue').dataset.openTask = task;
    specimenContent.classList.remove('is-changing');
    requestAnimationFrame(() => specimenContent.classList.add('is-changing'));
  }

  function updateComposer() {
    submitDemo.disabled = promptInput.value.trim().length === 0;
  }

  function setWorkspaceTask(task, fillPrompt = true, focus = false) {
    if (!tasks[task]) return;
    activeTask = task;
    taskSelect.value = task;
    document.getElementById('current-task-label').textContent = tasks[task].shortLabel;
    document.querySelectorAll('[data-workspace-task]').forEach((button) => {
      button.setAttribute('aria-pressed', String(button.dataset.workspaceTask === task));
    });
    if (fillPrompt) promptInput.value = tasks[task].prompt;
    composerFeedback.textContent = '';
    updateComposer();
    if (focus) {
      promptInput.focus();
      promptInput.scrollIntoView({ block: 'center' });
    }
  }

  function showDemoResponse(task, prompt) {
    const example = tasks[task];
    welcome.hidden = true;
    conversation.hidden = false;
    document.getElementById('workspace-location').textContent = example.title;
    document.getElementById('conversation-prompt').textContent = prompt;
    document.getElementById('conversation-answer').innerHTML = example.answer;
    document.getElementById('response-disclosure').textContent = `Pilihan Anda: ${models[modelSelect.value]}. Ini contoh tetap untuk tugas ${example.label.toLowerCase()}, bukan respons dari model atau hasil pemrosesan teks Anda.`;
    document.querySelectorAll('[data-history]').forEach((button) => {
      if (button.dataset.history === task) button.setAttribute('aria-current', 'true');
      else button.removeAttribute('aria-current');
    });
    document.getElementById('response-copy-status').textContent = '';
    closeHistory();
    composerFeedback.textContent = 'Contoh ditampilkan di atas. Tidak ada pesan yang dikirim ke AI.';
    requestAnimationFrame(() => conversation.scrollIntoView({ block: 'start' }));
  }

  function newConversation() {
    welcome.hidden = false;
    conversation.hidden = true;
    document.getElementById('workspace-location').textContent = 'Catatan baru';
    document.querySelectorAll('[data-history]').forEach((button) => button.removeAttribute('aria-current'));
    promptInput.value = '';
    setWorkspaceTask('writing', false);
    closeHistory();
    document.getElementById('response-copy-status').textContent = '';
    composerFeedback.textContent = 'Catatan baru. Pilih tugas contoh atau tulis arahan Anda.';
    promptInput.focus();
  }

  function setCodeLanguage(language) {
    if (!apiExamples[language]) return;
    activeLanguage = language;
    setTabState('[data-code-language]', language, 'data-code-language');
    document.getElementById('api-code-panel').setAttribute('aria-labelledby', `code-tab-${language}`);
    document.getElementById('api-code').textContent = apiExamples[language];
    document.getElementById('code-copy-status').textContent = '';
  }

  async function copyText(text, statusElement, button) {
    let copied = false;
    try {
      if (!navigator.clipboard?.writeText) throw new Error('Clipboard API unavailable');
      await navigator.clipboard.writeText(text);
      copied = true;
    } catch {
      const temporaryInput = document.createElement('textarea');
      temporaryInput.value = text;
      temporaryInput.readOnly = true;
      temporaryInput.style.position = 'fixed';
      temporaryInput.style.left = '-9999px';
      temporaryInput.style.top = '0';
      document.body.appendChild(temporaryInput);
      temporaryInput.select();
      try { copied = document.execCommand('copy'); } catch { copied = false; }
      temporaryInput.remove();
      button?.focus({ preventScroll: true });
    }
    if (statusElement) statusElement.textContent = copied ? 'Tersalin ke clipboard.' : 'Clipboard diblokir browser. Pilih dan salin teks secara manual.';
    if (button) {
      const originalText = button.dataset.originalLabel || button.innerHTML;
      button.dataset.originalLabel = originalText;
      button.textContent = copied ? 'Tersalin' : 'Salin manual';
      window.clearTimeout(Number(button.dataset.restoreTimer));
      button.dataset.restoreTimer = String(window.setTimeout(() => { button.innerHTML = originalText; }, 2200));
    }
  }

  document.addEventListener('click', (event) => {
    const viewLink = event.target.closest('a[data-view]');
    if (viewLink && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
      event.preventDefault();
      if (viewLink.dataset.openTask) setWorkspaceTask(viewLink.dataset.openTask);
      navigate(viewLink.dataset.view, viewLink.dataset.scroll || '');
      return;
    }
    const specimenTab = event.target.closest('[data-specimen]');
    if (specimenTab) setSpecimen(specimenTab.dataset.specimen);
    const taskButton = event.target.closest('[data-workspace-task]');
    if (taskButton) setWorkspaceTask(taskButton.dataset.workspaceTask, true, true);
    const historyButton = event.target.closest('[data-history]');
    if (historyButton) {
      setWorkspaceTask(historyButton.dataset.history);
      showDemoResponse(activeTask, tasks[activeTask].prompt);
    }
    const panelButton = event.target.closest('[data-console-panel]');
    if (panelButton) setConsolePanel(panelButton.dataset.consolePanel, true, true);
    const languageTab = event.target.closest('[data-code-language]');
    if (languageTab) setCodeLanguage(languageTab.dataset.codeLanguage);
    const endpointButton = event.target.closest('[data-copy-endpoint]');
    if (endpointButton) copyText('https://api.ultrai.id/v1', document.getElementById('console-copy-status'), endpointButton);
  });

  document.querySelectorAll('[role="tablist"]').forEach((tablist) => {
    tablist.addEventListener('keydown', (event) => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      const tabs = [...tablist.querySelectorAll('[role="tab"]')];
      const currentIndex = tabs.indexOf(document.activeElement);
      if (currentIndex === -1) return;
      event.preventDefault();
      let nextIndex;
      if (event.key === 'Home') nextIndex = 0;
      else if (event.key === 'End') nextIndex = tabs.length - 1;
      else nextIndex = (currentIndex + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
      tabs[nextIndex].focus();
      tabs[nextIndex].click();
    });
  });

  menuToggle.addEventListener('click', () => {
    const open = menuToggle.getAttribute('aria-expanded') !== 'true';
    productNav.classList.toggle('is-open', open);
    menuToggle.setAttribute('aria-expanded', String(open));
  });

  historyToggle.addEventListener('click', () => {
    const open = historyToggle.getAttribute('aria-expanded') !== 'true';
    workspaceRail.classList.toggle('is-open', open);
    historyToggle.setAttribute('aria-expanded', String(open));
    if (open) document.getElementById('new-conversation').focus({ preventScroll: true });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      if (menuToggle.getAttribute('aria-expanded') === 'true') {
        closeProductMenu();
        menuToggle.focus();
      }
      if (historyToggle.getAttribute('aria-expanded') === 'true') {
        closeHistory();
        historyToggle.focus();
      }
    }
  });

  document.getElementById('new-conversation').addEventListener('click', newConversation);
  promptInput.addEventListener('input', () => {
    composerFeedback.textContent = '';
    updateComposer();
  });
  promptInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey) && !submitDemo.disabled) {
      event.preventDefault();
      document.getElementById('workspace-form').requestSubmit();
    }
  });

  taskSelect.addEventListener('change', () => {
    const shouldReplacePrompt = promptInput.value.trim() === '' || Object.values(tasks).some((task) => task.prompt === promptInput.value);
    setWorkspaceTask(taskSelect.value, shouldReplacePrompt);
    composerFeedback.textContent = `Jenis respons contoh: ${tasks[activeTask].label.toLowerCase()}. Tidak ada koneksi ke AI.`;
  });

  modelSelect.addEventListener('change', () => {
    composerFeedback.textContent = `Pilihan demo: ${models[modelSelect.value]}. Pilihan ini tidak memanggil model; respons tetap berupa contoh yang disiapkan.`;
  });

  document.getElementById('workspace-form').addEventListener('submit', (event) => {
    event.preventDefault();
    const prompt = promptInput.value.trim();
    if (!prompt) {
      composerFeedback.textContent = 'Tulis satu arahan atau pilih tugas contoh terlebih dahulu.';
      promptInput.focus();
      return;
    }
    showDemoResponse(activeTask, prompt);
  });

  document.getElementById('copy-response').addEventListener('click', (event) => {
    copyText(`Contoh respons demo UltrAI — bukan respons AI langsung\n\n${document.getElementById('conversation-answer').innerText}`, document.getElementById('response-copy-status'), event.currentTarget);
  });

  document.getElementById('copy-code').addEventListener('click', (event) => {
    copyText(apiExamples[activeLanguage], document.getElementById('code-copy-status'), event.currentTarget);
  });

  document.getElementById('copy-dummy-key').addEventListener('click', (event) => {
    copyText('sk-demo-ULTRAI-NOT-A-REAL-KEY', document.getElementById('key-copy-status'), event.currentTarget);
  });

  document.getElementById('toggle-key').addEventListener('click', (event) => {
    const input = document.getElementById('dummy-key');
    const hidden = input.type === 'text';
    input.type = hidden ? 'password' : 'text';
    event.currentTarget.textContent = hidden ? 'Tampilkan' : 'Sembunyikan';
    event.currentTarget.setAttribute('aria-pressed', String(hidden));
  });

  document.getElementById('usage-filter').addEventListener('change', (event) => {
    const task = event.target.value;
    let visibleRows = 0;
    document.querySelectorAll('[data-usage-task]').forEach((row) => {
      row.hidden = task !== 'all' && row.dataset.usageTask !== task;
      if (!row.hidden) visibleRows += 1;
    });
    document.getElementById('usage-count').textContent = `${visibleRows} baris contoh`;
  });

  window.addEventListener('popstate', loadRoute);
  setCodeLanguage('python');
  setWorkspaceTask('writing', false);
  loadRoute();
})();
