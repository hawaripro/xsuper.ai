export {};

const names = { atlas: 'Atlas', studio: 'Studio', signal: 'Signal' };
const surfaces = { landing: 'Landing', workspace: 'Workspace', console: 'Console' };
const state = { variant: 'studio', surface: 'workspace', device: 'desktop' };
const storageKey = 'ultrai-provider-design-choice';
const frame = document.querySelector('#explorer-frame');
const decisionStatus = document.querySelector('#decision-status');
const direction = document.querySelector('#direction-select');
const feedback = document.querySelector('#feedback');
direction.value = 'studio';
document.querySelector('#surface-select').value = 'workspace';
renderSelection();

function pressGroup(selector, attribute, value) {
  document.querySelectorAll(selector).forEach(button => {
    button.setAttribute('aria-pressed', String(button.dataset[attribute] === value));
  });
}

function renderExplorer() {
  frame.src = `/${state.variant}/?view=${state.surface}&embed=1`;
  frame.title = `Prototype interaktif ${names[state.variant]}, ${surfaces[state.surface]}`;
  document.querySelector('#full-link').href = `/${state.variant}/?view=${state.surface}`;
  document.querySelector('#explorer-caption').textContent = `${names[state.variant]} / ${surfaces[state.surface]}`;
  document.querySelector('#surface-select').value = state.surface;
  pressGroup('[data-variant]', 'variant', state.variant);
}

function syncFullSizeLink() {
  try {
    const url = new URL(frame.contentWindow.location.href);
    const variant = url.pathname.split('/')[1];
    if (url.origin !== location.origin || !Object.hasOwn(names, variant)) return;
    url.searchParams.delete('embed');
    document.querySelector('#full-link').href = `${url.pathname}${url.search}${url.hash}`;
  } catch {
    // Keep the gallery-selected route while the next local frame is loading.
  }
}

const fullSizeLink = document.querySelector('#full-link');
fullSizeLink.addEventListener('pointerdown', syncFullSizeLink);
fullSizeLink.addEventListener('focus', syncFullSizeLink);
fullSizeLink.addEventListener('click', syncFullSizeLink);
frame.addEventListener('load', syncFullSizeLink);

function renderSelection() {
  document.querySelectorAll('[data-pick]').forEach(button => {
    const selected = button.dataset.pick === direction.value;
    button.setAttribute('aria-pressed', String(selected));
    button.textContent = `${selected ? 'Dipilih: ' : 'Pilih '}${names[button.dataset.pick]}`;
  });
}

function choiceText() {
  const label = direction.options[direction.selectedIndex].textContent;
  return `Pilihan mockup UltrAI: ${label}.${feedback.value.trim() ? `\nCatatan: ${feedback.value.trim()}` : ''}\nIni pilihan arah desain; implementasi produksi menunggu konfirmasi.`;
}

function resizePreviews() {
  document.querySelectorAll('[data-miniature]').forEach(preview => {
    preview.style.setProperty('--preview-scale', String(preview.clientWidth / 1440));
  });
}

new ResizeObserver(resizePreviews).observe(document.querySelector('.concept-grid'));
resizePreviews();

document.querySelectorAll('[data-compare]').forEach(button => {
  button.addEventListener('click', () => {
    const surface = button.dataset.compare;
    pressGroup('[data-compare]', 'compare', surface);
    document.querySelectorAll('[data-concept]').forEach(article => {
      const variant = article.dataset.concept;
      article.querySelector('iframe').src = `/${variant}/?view=${surface}&embed=1`;
      article.querySelector('iframe').title = `Pratinjau ${names[variant]}, ${surfaces[surface]}`;
      article.querySelector('.preview-link').href = `/${variant}/?view=${surface}`;
    });
    state.surface = surface;
    renderExplorer();
  });
});

document.querySelectorAll('[data-variant]').forEach(button => {
  button.addEventListener('click', () => {
    state.variant = button.dataset.variant;
    renderExplorer();
  });
});

document.querySelectorAll('[data-explore]').forEach(button => {
  button.addEventListener('click', () => {
    state.variant = button.dataset.explore;
    renderExplorer();
    document.querySelector('#explore').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
  });
});

document.querySelector('#surface-select').addEventListener('change', event => {
  state.surface = event.target.value;
  renderExplorer();
});

document.querySelectorAll('[data-device]').forEach(button => {
  if (button.tagName !== 'BUTTON') return;
  button.addEventListener('click', () => {
    state.device = button.dataset.device;
    document.querySelector('.explorer-stage').dataset.device = state.device;
    pressGroup('button[data-device]', 'device', state.device);
  });
});

document.querySelectorAll('[data-pick]').forEach(button => {
  button.addEventListener('click', () => {
    direction.value = button.dataset.pick;
    renderSelection();
    decisionStatus.textContent = `${names[direction.value]} dipilih. Tambahkan catatan, lalu simpan atau salin untuk chat.`;
    document.querySelector('#decision').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
  });
});
direction.addEventListener('change', renderSelection);

document.querySelector('#decision-form').addEventListener('submit', event => {
  event.preventDefault();
  if (!direction.reportValidity()) return;
  const selection = { direction: direction.value, feedback: feedback.value.trim(), savedAt: new Date().toISOString() };
  try {
    localStorage.setItem(storageKey, JSON.stringify(selection));
    decisionStatus.textContent = 'Pilihan tersimpan di browser ini. Klik “Salin untuk chat”, lalu kirim di percakapan untuk konfirmasi.';
  } catch {
    decisionStatus.textContent = 'Browser tidak mengizinkan penyimpanan lokal. Pilihan tetap terlihat; salin dan kirim melalui chat.';
  }
});

document.querySelector('#copy-decision').addEventListener('click', async () => {
  if (!direction.reportValidity()) return;
  try {
    await navigator.clipboard.writeText(choiceText());
    decisionStatus.textContent = 'Pilihan disalin. Tempel di chat untuk mengonfirmasi arah desain.';
  } catch {
    decisionStatus.textContent = `Clipboard tidak tersedia. Salin teks ini: ${choiceText()}`;
  }
});

try {
  const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
  if (saved && [...direction.options].some(option => option.value === saved.direction)) {
    direction.value = saved.direction;
    feedback.value = typeof saved.feedback === 'string' ? saved.feedback : '';
    renderSelection();
    decisionStatus.textContent = 'Pilihan lokal sebelumnya dimuat. Belum ada perubahan pada aplikasi asli.';
  }
} catch {
  decisionStatus.textContent = 'Penyimpanan lokal tidak tersedia. Kamu tetap bisa membandingkan dan menyalin pilihan.';
}
