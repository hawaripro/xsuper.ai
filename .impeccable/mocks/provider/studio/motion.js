(() => {
  'use strict';

  const stage = document.querySelector('#studio-motion-stage');
  const toggle = document.querySelector('#motion-toggle');
  const replay = document.querySelector('#motion-replay');
  const caption = document.querySelector('#motion-caption');
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const ease = 'cubic-bezier(.16,1,.3,1)';
  const duration = 6000;
  let animations = [];
  let workspaceAnimations = [];
  let clock = null;
  let frame = 0;
  let stageVisible = false;
  let workspaceVisible = false;
  let manuallyPaused = false;
  let hasStarted = false;
  let completed = false;
  let currentView = document.body.dataset.view || 'landing';

  function cancelAnimations(items) {
    items.forEach(animation => animation.cancel());
  }

  function animate(element, keyframes, options) {
    if (!element?.animate) return null;
    return element.animate(keyframes, { easing: ease, fill: 'none', ...options });
  }

  function updateButton() {
    const paused = manuallyPaused || completed || reduced.matches;
    toggle.setAttribute('aria-pressed', String(manuallyPaused));
    toggle.setAttribute('aria-label', completed ? 'Putar animasi kembali' : paused ? 'Lanjutkan animasi' : 'Jeda animasi');
    toggle.title = toggle.getAttribute('aria-label');
    toggle.querySelector('use').setAttribute('href', paused ? '#i-replay' : '#i-pause');
    toggle.disabled = reduced.matches;
    replay.disabled = reduced.matches;
    if (reduced.matches) {
      toggle.title = 'Gerakan dikurangi sesuai pengaturan perangkat';
      replay.title = 'Gerakan dikurangi sesuai pengaturan perangkat';
    } else {
      replay.title = 'Putar ulang animasi';
    }
  }

  function tick() {
    cancelAnimationFrame(frame);
    if (!clock || clock.playState !== 'running') return;
    const elapsed = Number(clock.currentTime || 0);
    caption.textContent = elapsed < 1750 ? 'Bawa idemu.' : elapsed < 3900 ? 'Temukan bentuknya.' : 'Lanjutkan jadi karya.';
    if (elapsed >= duration - 20) {
      completed = true;
      stage.dataset.motionState = 'complete';
      updateButton();
      return;
    }
    frame = requestAnimationFrame(tick);
  }

  function syncPlayback() {
    const shouldRun = currentView === 'landing' && stageVisible && !document.hidden && !manuallyPaused && !completed && !reduced.matches;
    animations.forEach(animation => {
      if (animation.playState === 'finished') return;
      if (shouldRun) animation.play();
      else animation.pause();
    });
    stage.dataset.motionState = reduced.matches ? 'reduced' : completed ? 'complete' : shouldRun ? 'playing' : 'paused';
    if (shouldRun) tick();
    else cancelAnimationFrame(frame);
    updateButton();
  }

  function playStage() {
    cancelAnimations(animations);
    cancelAnimationFrame(frame);
    animations = [];
    clock = null;
    completed = false;
    manuallyPaused = false;
    hasStarted = true;
    if (reduced.matches || !stage.animate) {
      caption.textContent = 'Dari pikiran. Jadi kemungkinan.';
      completed = true;
      syncPlayback();
      return;
    }
    const add = (selector, frames, settings) => {
      const animation = animate(stage.querySelector(selector), frames, settings);
      if (animation) animations.push(animation);
      return animation;
    };
    clock = animate(stage, [{ outlineOffset: '0px' }, { outlineOffset: '0px' }], { duration, easing: 'linear' });
    animations.push(clock);
    add('.motion-input', [
      { transform: 'translateX(-17px)', opacity: .65 },
      { transform: 'translateX(0)', opacity: 1, offset: .2 },
      { transform: 'translateX(0)', opacity: 1 },
    ], { duration: 1400 });
    add('.motion-input-line', [{ transform: 'scaleX(.1)' }, { transform: 'scaleX(1)' }], { duration: 750, delay: 350 });
    add('.plane-back', [
      { transform: 'translate(-58px,27px) rotate(-55deg) skew(8deg,8deg)', opacity: .5 },
      { transform: 'translate(-24px,14px) rotate(-25deg) skew(8deg,8deg)', opacity: 1 },
    ], { duration: 1800, delay: 250 });
    add('.plane-middle', [
      { transform: 'translate(15px,-20px) rotate(12deg) skew(8deg,8deg)', opacity: .5 },
      { transform: 'translate(-10px,6px) rotate(-25deg) skew(8deg,8deg)', opacity: 1 },
    ], { duration: 1800, delay: 500 });
    add('.plane-front', [
      { transform: 'translate(28px,17px) rotate(-8deg) skew(8deg,8deg)', filter: 'brightness(.8)' },
      { transform: 'translate(0,0) rotate(-25deg) skew(8deg,8deg)', filter: 'brightness(1.12)', offset: .6 },
      { transform: 'translate(0,0) rotate(-25deg) skew(8deg,8deg)', filter: 'brightness(1)' },
    ], { duration: 2200, delay: 700 });
    add('.packet-left', [
      { transform: 'translateX(-8px)', opacity: 0 },
      { opacity: 1, offset: .15 },
      { transform: 'translateX(65px)', opacity: 1, offset: .8 },
      { transform: 'translateX(77px)', opacity: 0 },
    ], { duration: 1300, delay: 900, easing: 'cubic-bezier(.4,0,.2,1)' });
    add('.packet-right', [
      { transform: 'translateX(0)', opacity: 0 },
      { opacity: 1, offset: .15 },
      { transform: 'translateX(50px)', opacity: 1, offset: .8 },
      { transform: 'translateX(60px)', opacity: 0 },
    ], { duration: 1100, delay: 2300, easing: 'cubic-bezier(.4,0,.2,1)' });
    add('.motion-output', [
      { transform: 'translateY(11px)', opacity: .6 },
      { transform: 'translateY(0)', opacity: 1 },
    ], { duration: 1500, delay: 2700 });
    ['.output-line-one', '.output-line-two', '.output-line-three'].forEach((selector, index) => {
      add(selector, [{ transform: 'scaleX(.18)' }, { transform: 'scaleX(1)' }], { duration: 950, delay: 3100 + index * 300 });
    });
    add('.motion-output strong', [{ clipPath: 'inset(0 86% 0 0)' }, { clipPath: 'inset(0 0 0 0)' }], { duration: 1500, delay: 3200 });
    add('.output-cursor', [{ opacity: 1 }, { opacity: .15 }, { opacity: 1 }], { duration: 700, iterations: 3, delay: 3600, easing: 'linear' });
    clock.addEventListener('finish', () => {
      completed = true;
      caption.textContent = 'Lanjutkan jadi karya.';
      stage.dataset.motionState = 'complete';
      cancelAnimationFrame(frame);
      updateButton();
    }, { once: true });
    syncPlayback();
  }

  function wakeWorkspace() {
    cancelAnimations(workspaceAnimations);
    workspaceAnimations = [];
    if (reduced.matches || document.hidden || currentView !== 'workspace') return;
    const sigil = document.querySelector('.workspace-sigil');
    if (!sigil || !workspaceVisible || !sigil.getBoundingClientRect().height) return;
    [
      ['.orbit-back', [{ transform: 'rotate(-32deg) scale(.84)' }, { transform: 'rotate(0deg) scale(1)' }], 1900],
      ['.orbit-front', [{ transform: 'rotate(42deg) scale(.88)' }, { transform: 'rotate(0deg) scale(1)' }], 2200],
      ['.sigil-core', [{ transform: 'translateY(7px) rotate(-12deg)' }, { transform: 'translateY(-3px) rotate(3deg)', offset: .65 }, { transform: 'translateY(0) rotate(0)' }], 2200],
      ['.sigil-flare', [{ opacity: .2, transform: 'scale(.4)' }, { opacity: 1, transform: 'scale(1)', offset: .6 }, { opacity: .65, transform: 'scale(.85)' }], 2200],
    ].forEach(([selector, frames, ms]) => {
      const animation = animate(sigil.querySelector(selector), frames, { duration: ms });
      if (animation) workspaceAnimations.push(animation);
    });
  }

  function workspaceFeedback() {
    if (reduced.matches || document.hidden) return;
    const area = document.querySelector('.workspace-composer');
    if (area?.getBoundingClientRect().height) animate(area, [{ transform: 'translateY(3px)' }, { transform: 'translateY(0)' }], { duration: 230 });
  }

  toggle.addEventListener('click', () => {
    if (completed) { playStage(); return; }
    manuallyPaused = !manuallyPaused;
    syncPlayback();
  });
  replay.addEventListener('click', playStage);

  new IntersectionObserver(entries => {
    stageVisible = entries[0].isIntersecting;
    if (stageVisible && currentView === 'landing' && !hasStarted) playStage();
    else syncPlayback();
  }, { threshold: .2 }).observe(stage);

  const sigil = document.querySelector('.workspace-sigil');
  if (sigil) new IntersectionObserver(entries => {
    workspaceVisible = entries[0].isIntersecting;
    if (workspaceVisible) wakeWorkspace();
    else cancelAnimations(workspaceAnimations);
  }, { threshold: .3 }).observe(sigil);

  document.addEventListener('studio:view', event => {
    currentView = event.detail.view;
    if (currentView === 'landing' && stageVisible && !hasStarted) playStage();
    else syncPlayback();
    if (currentView !== 'workspace') cancelAnimations(workspaceAnimations);
  });
  document.addEventListener('studio:mode', () => { wakeWorkspace(); workspaceFeedback(); });
  document.addEventListener('studio:response', event => {
    if (event.detail.phase === 'start') workspaceFeedback();
  });
  document.addEventListener('visibilitychange', () => {
    syncPlayback();
    if (document.hidden) cancelAnimations(workspaceAnimations);
  });
  reduced.addEventListener('change', () => {
    cancelAnimations(workspaceAnimations);
    if (reduced.matches) {
      cancelAnimations(animations);
      animations = [];
      clock = null;
      completed = true;
      caption.textContent = 'Dari pikiran. Jadi kemungkinan.';
    } else {
      hasStarted = false;
      completed = false;
      if (stageVisible && currentView === 'landing') playStage();
    }
    syncPlayback();
  });
  window.addEventListener('pagehide', () => {
    cancelAnimationFrame(frame);
    cancelAnimations(animations);
    cancelAnimations(workspaceAnimations);
  });
  updateButton();
})();
