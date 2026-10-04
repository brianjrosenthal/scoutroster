// Slideshow player (slideshow_play.php). Fetches the manifest, then plays each
// section: a title card, the event's photos crossfading with a slow zoom, and
// the section's music. The clock for a section WITH music is the <audio>
// element's currentTime, so photos can never drift from the music and pausing
// is free; sections without music run on a wall clock.
//
// Two <audio> elements alternate so music can crossfade between sections and
// loop (restart with a crossfade) when a section has more photos than its
// track covers. Two photo layers alternate for the visual crossfade.
//
// Browser notes: audio can only start inside a user gesture, so everything is
// kicked off from the Begin button. On iPhone HTMLMediaElement.volume is
// read-only (fades become hard cuts, detected at Begin) and requestFullscreen
// is unavailable (the page still fills the viewport).

(function () {
  'use strict';

  var cfg = window.SLIDESHOW || {};
  var stage = document.getElementById('stage');
  var layers = [document.getElementById('layerA'), document.getElementById('layerB')];
  var audios = [document.getElementById('audioA'), document.getElementById('audioB')];
  var card = document.getElementById('card');
  var captionEl = document.getElementById('caption');
  var hud = document.getElementById('hud');
  var hudText = document.getElementById('hudText');
  var progressBar = document.querySelector('#progress i');
  var overlay = document.getElementById('overlay');
  var beginBtn = document.getElementById('begin');
  var errorEl = document.getElementById('error');

  var manifest = null;
  var C = null;                 // manifest.constants
  var state = 'IDLE';           // IDLE | READY | PLAYING | END_CARD | DONE
  var sectionIndex = -1;
  var section = null;
  var shownIdx = -1;            // photo index currently displayed (-1 = title card)
  var activeLayer = 0;
  var activeAudio = 0;
  var paused = false;
  var canFade = true;
  var showCaptions = true;
  var rafId = null;
  var cumulativeBefore = 0;     // seconds of earlier sections, for the progress bar

  // Section clock bookkeeping.
  var wallStart = 0, pausedAt = 0, pausedTotal = 0;
  var offset = 0;               // seek adjustment from arrow keys
  var loopCount = 0;
  var looping = false;          // a loop crossfade is in progress
  var endCardStart = 0;
  var nextPreloaded = false;

  // ---------------------------------------------------------------------------
  // Utilities
  // ---------------------------------------------------------------------------

  function showError(text) {
    errorEl.textContent = text;
    errorEl.classList.remove('hidden');
  }

  function fmt(sec) {
    sec = Math.max(0, Math.round(sec));
    return Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2);
  }

  function setVolume(a, v) {
    if (!canFade) return;
    try { a.volume = Math.max(0, Math.min(1, v)); } catch (e) {}
  }

  function preload(url) {
    if (!url) return;
    var img = new Image();
    img.src = url;
  }

  // ---------------------------------------------------------------------------
  // Clock
  // ---------------------------------------------------------------------------

  function elapsed() {
    if (!section) return 0;
    if (section.music_mode !== 'none' && section.track) {
      var a = audios[activeAudio];
      var loopAt = section.loop_at || (a.duration && isFinite(a.duration) ? a.duration : 0);
      return loopCount * loopAt + (a.currentTime || 0) + offset;
    }
    var now = paused ? pausedAt : performance.now();
    return (now - wallStart - pausedTotal) / 1000 + offset;
  }

  // ---------------------------------------------------------------------------
  // Sections
  // ---------------------------------------------------------------------------

  function startSection(i) {
    if (i >= manifest.sections.length) { showEndCard(); return; }
    if (section) cumulativeBefore += section.section_seconds;
    sectionIndex = i;
    section = manifest.sections[i];
    shownIdx = -1;
    offset = 0;
    loopCount = 0;
    looping = false;
    nextPreloaded = false;
    wallStart = performance.now();
    pausedTotal = 0;
    state = 'PLAYING';

    // Photos off, title card on.
    layers.forEach(function (l) { l.classList.remove('active', 'hold'); l.classList.add('fade-out'); });
    captionEl.classList.add('hidden');
    card.querySelector('h1').textContent = section.title;
    card.querySelector('p').textContent = section.date || '';
    card.classList.remove('hidden');
    requestAnimationFrame(function () { card.classList.add('show'); });

    // Music: crossfade from the previous section's element to the other one.
    var prev = audios[activeAudio];
    var next = audios[1 - activeAudio];
    activeAudio = 1 - activeAudio;
    if (section.track && section.music_mode !== 'none') {
      if (next.src !== section.track.url) next.src = section.track.url;
      next.currentTime = 0;
      setVolume(next, canFade ? 0 : 1);
      next.play().catch(function (e) { showError('Music could not start: ' + (e.message || e.name)); });
      if (!canFade) { try { prev.pause(); } catch (e) {} }
    } else {
      // No music this section: fade the previous one out.
    }
    fadeOutAndStop(prev, C.section_xfade_seconds);
    if (section.track && section.music_mode !== 'none') fadeIn(next, C.section_xfade_seconds);

    // Warm the first photos.
    for (var k = 0; k < 3 && k < section.photos.length; k++) preload(section.photos[k].url);
    if (paused) resume();
  }

  var fades = [];
  function fadeIn(a, secs) { fades.push({ a: a, from: 0, to: 1, start: performance.now(), dur: secs * 1000, stop: false }); }
  function fadeOutAndStop(a, secs) {
    if (a.paused && a.currentTime === 0) return;
    var from = canFade ? a.volume : 1;
    fades.push({ a: a, from: from, to: 0, start: performance.now(), dur: secs * 1000, stop: true });
  }
  function runFades(now) {
    for (var i = fades.length - 1; i >= 0; i--) {
      var f = fades[i];
      var t = Math.min(1, (now - f.start) / f.dur);
      if (canFade) setVolume(f.a, f.from + (f.to - f.from) * t);
      if (t >= 1) {
        if (f.stop) { try { f.a.pause(); } catch (e) {} }
        fades.splice(i, 1);
      } else if (!canFade && f.stop) {
        try { f.a.pause(); } catch (e) {}
        fades.splice(i, 1);
      }
    }
  }

  // Transitions. The slideshow's setting (manifest.transition) is one of:
  //   'mix'    mostly crossfades with occasional variety, chosen per photo by
  //            its id so a replay (and stepping back and forth) is consistent;
  //   'random' a different transition for every photo, truly random;
  //   a name   that transition every time, alternating direction where it
  //            has one (slide, push, wipe).
  // The first photo after a title card always fades in. Ken Burns motions are
  // chosen per photo independently of the transition.
  var CATALOG = {
    fade:  ['fade'],
    slide: ['slide-left', 'slide-right'],
    push:  ['push-left', 'push-right'],
    wipe:  ['wipe', 'wipe-down', 'wipe-diag'],
    iris:  ['iris'],
    zoom:  ['zoom'],
    blur:  ['blur'],
    flip:  ['flip'],
    spin:  ['spin']
  };
  var MIX = ['fade', 'fade', 'slide-left', 'fade', 'wipe', 'fade', 'zoom', 'fade', 'fade', 'push-right', 'fade', 'blur', 'fade', 'iris', 'fade', 'flip', 'fade', 'wipe-diag', 'fade', 'spin'];
  var ALL = Object.keys(CATALOG).reduce(function (a, k) { return a.concat(CATALOG[k]); }, []);
  var MOTIONS = ['kb-in', 'kb-pan-left', 'kb-out', 'kb-pan-up', 'kb-in-tl', 'kb-pan-right', 'kb-out-tr', 'kb-pan-down', 'kb-in-br'];
  var LAYER_ANIM_CLASSES = ALL.map(function (e) { return 'enter-' + e; }).concat(['leave-push-left', 'leave-push-right', 'hold', 'fade-out']);

  function variant(list, seed, salt) {
    var h = (seed * 2654435761 + salt * 40503) >>> 0;
    return list[h % list.length];
  }

  function pickEntrance(photo, idx) {
    if (idx === 0) return 'fade';
    var mode = manifest.transition || 'mix';
    var seed = photo.id || (idx + 1);
    if (mode === 'random') return ALL[Math.floor(Math.random() * ALL.length)];
    if (CATALOG[mode]) return CATALOG[mode][idx % CATALOG[mode].length];
    return variant(MIX, seed, sectionIndex + 1);
  }

  function clearLayerAnims(layer) {
    LAYER_ANIM_CLASSES.forEach(function (c) { layer.classList.remove(c); });
  }

  function showPhoto(idx) {
    var photo = section.photos[idx];
    var incoming = layers[1 - activeLayer];
    var outgoing = layers[activeLayer];
    var img = incoming.querySelector('img');
    var bg = incoming.querySelector('.bg');
    activeLayer = 1 - activeLayer;
    shownIdx = idx;

    var seed = photo.id || (idx + 1);
    var entrance = pickEntrance(photo, idx);
    var motion = variant(MOTIONS, seed, 7);
    var xf = C.photo_crossfade_seconds;

    incoming.classList.remove('active');
    clearLayerAnims(incoming);
    img.className = '';
    img.onload = null;
    img.onerror = function () {
      // Keep the previous photo up; the slot still consumes its time so the
      // timeline stays locked to the music.
      incoming.classList.remove('active');
      clearLayerAnims(outgoing);
      outgoing.classList.add('active');
    };
    img.onload = function () {
      incoming.style.setProperty('--xf', xf + 's');
      incoming.style.setProperty('--kb', (section.seconds_per_photo + xf) + 's');
      void incoming.offsetWidth; // restart animations when a layer is reused
      img.className = motion;
      incoming.classList.add('active', 'enter-' + entrance);
      outgoing.classList.remove('active');
      clearLayerAnims(outgoing);
      // How the previous photo leaves: fade under a crossfade, get pushed by a
      // push, otherwise stay put until the new photo has covered it.
      var leave = entrance === 'fade' ? 'fade-out' : (entrance.indexOf('push-') === 0 ? 'leave-' + entrance : 'hold');
      outgoing.classList.add(leave);
      setTimeout(function () { outgoing.classList.remove(leave); }, xf * 1000 + 150);
    };
    img.src = photo.url;
    bg.style.backgroundImage = 'url("' + photo.url.replace(/"/g, '%22') + '")';
    if (img.complete && img.naturalWidth > 0) img.onload();

    card.classList.remove('show');
    setTimeout(function () { if (shownIdx >= 0) card.classList.add('hidden'); }, 800);

    if (showCaptions && photo.caption) {
      captionEl.textContent = photo.caption;
      captionEl.classList.remove('hidden');
    } else {
      captionEl.classList.add('hidden');
    }
    for (var k = idx + 1; k <= idx + 3 && k < section.photos.length; k++) preload(section.photos[k].url);
  }

  function showEndCard() {
    if (section) cumulativeBefore += section.section_seconds;
    state = 'END_CARD';
    section = null;
    endCardStart = performance.now();
    layers.forEach(function (l) { l.classList.remove('active', 'hold'); l.classList.add('fade-out'); });
    captionEl.classList.add('hidden');
    card.querySelector('h1').textContent = manifest.end_card.title;
    card.querySelector('p').textContent = manifest.end_card.subtitle || '';
    card.classList.remove('hidden');
    requestAnimationFrame(function () { card.classList.add('show'); });
    audios.forEach(function (a) { fadeOutAndStop(a, C.music_fade_out_seconds); });
    hudText.textContent = manifest.end_card.title;
  }

  function finish() {
    state = 'DONE';
    if (rafId) cancelAnimationFrame(rafId);
    audios.forEach(function (a) { try { a.pause(); } catch (e) {} });
    overlay.innerHTML = '';
    var h1 = document.createElement('h1'); h1.textContent = manifest.title;
    var row = document.createElement('div'); row.className = 'row';
    var again = document.createElement('button'); again.type = 'button'; again.textContent = 'Play again';
    again.addEventListener('click', function () { location.reload(); });
    var exit = document.createElement('button'); exit.type = 'button'; exit.textContent = 'Exit';
    exit.addEventListener('click', exitPlayer);
    row.appendChild(again); row.appendChild(exit);
    overlay.appendChild(h1); overlay.appendChild(row);
    overlay.classList.remove('hidden');
  }

  // ---------------------------------------------------------------------------
  // Per-frame
  // ---------------------------------------------------------------------------

  function frame(now) {
    rafId = requestAnimationFrame(frame);
    runFades(now);
    if (paused) return;

    if (state === 'END_CARD') {
      var e = (now - endCardStart) / 1000;
      progressBar.style.width = '100%';
      if (e >= C.end_card_seconds) finish();
      return;
    }
    if (state !== 'PLAYING' || !section) return;

    var t = elapsed();
    var n = section.photos.length;
    var spp = section.seconds_per_photo;

    // Which photo should be up?
    if (t >= C.title_card_seconds) {
      var idx = Math.floor((t - C.title_card_seconds) / spp);
      if (idx >= n) { startSection(sectionIndex + 1); return; }
      if (idx !== shownIdx) showPhoto(idx);
    }

    // Music envelope.
    if (section.track && section.music_mode !== 'none') {
      var a = audios[activeAudio];
      if (section.music_mode === 'loop') {
        var loopAt = section.loop_at || (a.duration && isFinite(a.duration) ? a.duration - C.music_loop_xfade_seconds : null);
        if (loopAt && !looping && a.currentTime >= loopAt) {
          // Restart the same track on the other element with a crossfade.
          looping = true;
          var other = audios[1 - activeAudio];
          var carried = loopCount * (section.loop_at || loopAt) + a.currentTime; // elapsed so far, pre-swap
          other.src = section.track.url;
          other.currentTime = 0;
          setVolume(other, canFade ? 0 : 1);
          other.play().catch(function () {});
          fadeOutAndStop(a, C.music_loop_xfade_seconds);
          fadeIn(other, C.music_loop_xfade_seconds);
          if (!section.loop_at) section.loop_at = loopAt;
          loopCount = Math.floor(carried / section.loop_at);
          activeAudio = 1 - activeAudio;
          setTimeout(function () { looping = false; }, C.music_loop_xfade_seconds * 1000 + 200);
        }
      }
      if (section.fade_out_at !== null && t >= section.fade_out_at) {
        var remaining = section.section_seconds - t;
        setVolume(a, Math.max(0, remaining / C.music_fade_out_seconds));
      }
      // Preload the next section's track ~10 s early on the idle element.
      if (!nextPreloaded && section.section_seconds - t < 10 && sectionIndex + 1 < manifest.sections.length) {
        nextPreloaded = true;
        var ns = manifest.sections[sectionIndex + 1];
        var idle = audios[1 - activeAudio];
        if (ns.track && !looping && idle.paused) { idle.src = ns.track.url; idle.load(); }
        if (ns.photos[0]) preload(ns.photos[0].url);
      }
    } else if (!nextPreloaded && section.section_seconds - t < 10 && sectionIndex + 1 < manifest.sections.length) {
      nextPreloaded = true;
      var ns2 = manifest.sections[sectionIndex + 1];
      if (ns2.track) { var idle2 = audios[1 - activeAudio]; idle2.src = ns2.track.url; idle2.load(); }
      if (ns2.photos[0]) preload(ns2.photos[0].url);
    }

    // HUD.
    progressBar.style.width = Math.min(100, ((cumulativeBefore + t) / manifest.total_seconds) * 100) + '%';
    hudText.textContent = section.title + (shownIdx >= 0 ? ' · ' + (shownIdx + 1) + ' / ' + n : '') + ' · ' + fmt(cumulativeBefore + t) + ' / ' + fmt(manifest.total_seconds);
  }

  // ---------------------------------------------------------------------------
  // Controls
  // ---------------------------------------------------------------------------

  function pause() {
    if (paused || state !== 'PLAYING') return;
    paused = true;
    pausedAt = performance.now();
    audios.forEach(function (a) { try { if (!a.paused) a.pause(); } catch (e) {} });
    var p = document.createElement('div'); p.id = 'paused'; p.textContent = '❚❚';
    stage.appendChild(p);
  }

  function resume() {
    if (!paused) return;
    paused = false;
    pausedTotal += performance.now() - pausedAt;
    var a = audios[activeAudio];
    if (section && section.track && section.music_mode !== 'none') a.play().catch(function () {});
    var p = document.getElementById('paused');
    if (p) p.remove();
  }

  function seekPhotos(delta) {
    if (state !== 'PLAYING' || !section) return;
    var t = elapsed();
    var spp = section.seconds_per_photo;
    var n = section.photos.length;
    var idx = Math.max(0, Math.floor((t - C.title_card_seconds) / spp)) + delta;
    idx = Math.max(0, Math.min(n - 1, idx));
    var target = C.title_card_seconds + idx * spp + 0.05;
    offset += target - t;
    if (shownIdx !== idx) showPhoto(idx);
  }

  function nextSection() { if (state === 'PLAYING') startSection(sectionIndex + 1); }
  function prevSection() {
    if (state !== 'PLAYING') return;
    if (elapsed() > C.title_card_seconds + 2 || sectionIndex === 0) {
      cumulativeBefore -= section.section_seconds; section = null; // restart this one
      startSection(sectionIndex);
    } else {
      cumulativeBefore -= section.section_seconds;
      var prev = manifest.sections[sectionIndex - 1];
      cumulativeBefore -= prev.section_seconds;
      section = null;
      startSection(sectionIndex - 1);
    }
  }

  function toggleFullscreen() {
    var d = document;
    if (d.fullscreenElement) { if (d.exitFullscreen) d.exitFullscreen(); }
    else if (d.documentElement.requestFullscreen) d.documentElement.requestFullscreen().catch(function () {});
  }

  function exitPlayer() {
    if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(function () {});
    location.href = cfg.exitUrl || '/slideshows.php';
  }

  document.addEventListener('keydown', function (e) {
    if (state === 'IDLE' || state === 'READY') return;
    switch (e.key) {
      case ' ': e.preventDefault(); if (state === 'PLAYING') { paused ? resume() : pause(); } break;
      case 'ArrowRight': e.preventDefault(); seekPhotos(1); break;
      case 'ArrowLeft': e.preventDefault(); seekPhotos(-1); break;
      case 'n': case 'N': nextSection(); break;
      case 'p': case 'P': prevSection(); break;
      case 'c': case 'C': showCaptions = !showCaptions; if (!showCaptions) captionEl.classList.add('hidden'); else if (section && shownIdx >= 0 && section.photos[shownIdx].caption) { captionEl.textContent = section.photos[shownIdx].caption; captionEl.classList.remove('hidden'); } break;
      case 'f': case 'F': toggleFullscreen(); break;
      case 'Escape':
        // The browser consumes the first Esc to leave fullscreen; a second one exits.
        if (!document.fullscreenElement) exitPlayer();
        break;
    }
  });

  stage.addEventListener('click', function (e) {
    if (state !== 'PLAYING') return;
    if (e.target.closest('#overlay')) return;
    paused ? resume() : pause();
  });

  // Hide the cursor and HUD after a few idle seconds.
  var idleTimer = null;
  function activity() {
    hud.classList.remove('idle');
    stage.classList.remove('idle');
    if (idleTimer) clearTimeout(idleTimer);
    idleTimer = setTimeout(function () { if (state === 'PLAYING' && !paused) { hud.classList.add('idle'); stage.classList.add('idle'); } }, 3000);
  }
  ['mousemove', 'keydown', 'touchstart', 'click'].forEach(function (ev) { document.addEventListener(ev, activity, { passive: true }); });

  // ---------------------------------------------------------------------------
  // Boot
  // ---------------------------------------------------------------------------

  function begin() {
    beginBtn.disabled = true;
    // Feature-detect volume control (read-only on iPhone => hard cuts).
    try { audios[0].volume = 0.5; canFade = audios[0].volume === 0.5; audios[0].volume = 1; } catch (e) { canFade = false; }
    // Unlock both elements inside the gesture.
    audios.forEach(function (a) { try { a.load(); } catch (e) {} });
    if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(function () {});
    overlay.classList.add('hidden');
    activity();
    startSection(0);
    rafId = requestAnimationFrame(frame);
  }

  function load() {
    fetch(cfg.manifestUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { throw new Error('Server error (' + r.status + ').'); }); })
      .then(function (data) {
        if (!data || !data.ok) throw new Error((data && data.error) || 'Could not load the slideshow.');
        manifest = data;
        C = data.constants;
        if (!manifest.sections.length) throw new Error('This slideshow has no photos yet.');
        state = 'READY';
        if (beginBtn) { beginBtn.disabled = false; beginBtn.addEventListener('click', begin); }
      })
      .catch(function (e) {
        showError(e.message || 'Could not load the slideshow.');
        if (beginBtn) beginBtn.disabled = true;
      });
  }

  if (beginBtn) { beginBtn.disabled = true; beginBtn.textContent = 'Loading…'; }
  load();
  if (beginBtn) {
    var restoreLabel = setInterval(function () {
      if (state === 'READY') { beginBtn.textContent = 'Click to begin'; clearInterval(restoreLabel); }
    }, 100);
  }
})();
