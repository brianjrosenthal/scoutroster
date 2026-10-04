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
  var progressEl = document.getElementById('progress');
  var progressBar = document.querySelector('#progress .track i');
  var progressThumb = document.querySelector('#progress .thumb');
  var progressTip = document.querySelector('#progress .tip');
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

  // Clock bookkeeping. A section is a run of CUES (music segments), each with
  // its own start_at within the section. Within a cue the clock is the audio
  // element's currentTime plus an offset (if the cue has a track), or a wall
  // clock. When a track restarts (loop) the offset carries the elapsed time
  // forward, so the clock never jumps.
  var cueIndex = -1;
  var cue = null;
  var wallStart = 0, pausedAt = 0, pausedTotal = 0;
  var offset = 0;
  var looping = false;          // a restart crossfade is in progress
  var seekHold = null;          // {value,a} while an audio seek is pending: cueElapsed() returns value
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

  function cueHasMusic() { return !!(cue && cue.track && cue.music_mode !== 'none'); }

  // Seconds into the current cue.
  function cueElapsed() {
    if (!cue) return 0;
    if (seekHold) return seekHold.value;
    if (cueHasMusic()) return (audios[activeAudio].currentTime || 0) + offset;
    var now = paused ? pausedAt : performance.now();
    return (now - wallStart - pausedTotal) / 1000 + offset;
  }

  // Seconds into the current section.
  function elapsed() {
    if (!section || !cue) return 0;
    return cue.start_at + cueElapsed();
  }

  // Photo index for a time within a section (-1 during the title card).
  function photoIndexAt(sec, t) {
    var starts = sec.photo_starts;
    var idx = -1;
    for (var i = 0; i < starts.length; i++) { if (t >= starts[i]) idx = i; else break; }
    return idx;
  }

  // Where a track should restart from the top: the server's loop point, or
  // the real end of the audio minus the crossfade if that comes first (the
  // stored duration can be a little longer than what this browser reports).
  function restartPoint(a) {
    var p = (cue.music_mode === 'loop' && cue.loop_at) ? cue.loop_at : Infinity;
    if (a.duration && isFinite(a.duration)) p = Math.min(p, a.duration - C.music_loop_xfade_seconds);
    return p;
  }

  // ---------------------------------------------------------------------------
  // Sections
  // ---------------------------------------------------------------------------

  // Seconds of slideshow before section i begins.
  function cumulativeFor(i) {
    var t = 0;
    for (var k = 0; k < i && k < manifest.sections.length; k++) t += manifest.sections[k].section_seconds;
    return t;
  }

  function startSection(i) {
    if (i >= manifest.sections.length) { showEndCard(); return; }
    cumulativeBefore = cumulativeFor(i);
    sectionIndex = i;
    section = manifest.sections[i];
    shownIdx = -1;
    nextPreloaded = false;
    state = 'PLAYING';

    // Photos off, title card on.
    layers.forEach(function (l) { l.classList.remove('active', 'hold'); l.classList.add('fade-out'); });
    captionEl.classList.add('hidden');
    card.querySelector('h1').textContent = section.title;
    card.querySelector('p').textContent = section.date || '';
    card.classList.remove('hidden');
    requestAnimationFrame(function () { card.classList.add('show'); });

    enterCue(0, 0);

    // Warm the first photos.
    for (var k = 0; k < 3 && k < section.photos.length; k++) preload(section.photos[k].url);
    if (paused) resume();
  }

  // Start cue k of the current section, $at seconds into it: switch to the
  // other <audio> element with a crossfade (a hard cut when the browser cannot
  // set volume), seek the music to the matching point (wrapping into the
  // track if it would have looped) and reset the cue clock so that
  // elapsed() === cue.start_at + at.
  function enterCue(k, at) {
    cueIndex = k;
    cue = section.cues[k];
    offset = 0;
    looping = false;
    seekHold = null;
    at = Math.max(0, at || 0);

    var prev = audios[activeAudio];
    var next = audios[1 - activeAudio];
    activeAudio = 1 - activeAudio;
    var xf = C.section_xfade_seconds;

    if (cueHasMusic()) {
      if (next.src !== cue.track.url) next.src = cue.track.url;
      var target = at;
      var loopAt = cue.loop_at || (next.duration && isFinite(next.duration) ? next.duration - C.music_loop_xfade_seconds : 0);
      if (loopAt > 0 && at >= loopAt) target = at - Math.floor(at / loopAt) * loopAt;
      seekAudio(next, target, at);
      setVolume(next, canFade ? 0 : 1);
      if (!paused) next.play().catch(function (e) { showError('Music could not start: ' + (e.message || e.name)); });
      if (!canFade) { try { prev.pause(); } catch (e) {} }
      fadeIn(next, xf);
    } else {
      wallStart = performance.now() - at * 1000;
      pausedTotal = 0;
      if (paused) pausedAt = performance.now();
    }
    fadeOutAndStop(prev, xf);
  }

  // Move an audio element to $target seconds while the cue clock must read
  // $cueSeconds. currentTime can read back the OLD position for a moment
  // after it is set (Safari especially), so the clock is held at $cueSeconds
  // until the browser confirms the seek; then the offset is set from the
  // confirmed position, which also absorbs a clamped seek.
  function seekAudio(a, target, cueSeconds) {
    seekHold = { value: cueSeconds, a: a };
    var done = function () {
      if (!seekHold || seekHold.a !== a) return;
      seekHold = null;
      offset = cueSeconds - (a.currentTime || 0);
    };
    var apply = function () {
      try { a.currentTime = target; } catch (e) {}
      if (a.seeking) a.addEventListener('seeked', done, { once: true }); else done();
    };
    if (a.readyState >= 1) apply(); else a.addEventListener('loadedmetadata', apply, { once: true });
    setTimeout(function () { if (seekHold && seekHold.a === a) done(); }, 1500); // never hold forever
  }

  // Start the current cue's track again from the top on the other element,
  // crossfading, and carry the elapsed time forward so the clock is continuous.
  function restartTrack() {
    var a = audios[activeAudio];
    var other = audios[1 - activeAudio];
    looping = true;
    var carried = cueElapsed();
    if (other.src !== cue.track.url) other.src = cue.track.url;
    try { other.currentTime = 0; } catch (e) {}
    setVolume(other, canFade ? 0 : 1);
    other.play().catch(function () {});
    fadeOutAndStop(a, C.music_loop_xfade_seconds);
    fadeIn(other, C.music_loop_xfade_seconds);
    activeAudio = 1 - activeAudio;
    offset = carried; // the new element starts at 0
    setTimeout(function () { looping = false; }, C.music_loop_xfade_seconds * 1000 + 200);
  }

  var fades = [];
  // An element being started again must not be stopped by a fade-out that
  // was scheduled for it earlier (e.g. a cue switch followed quickly by a restart).
  function cancelFades(a) { fades = fades.filter(function (f) { return f.a !== a; }); }
  function fadeIn(a, secs) { cancelFades(a); fades.push({ a: a, from: 0, to: 1, start: performance.now(), dur: secs * 1000, stop: false }); }
  function fadeOutAndStop(a, secs) {
    cancelFades(a);
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
      var dur = (idx + 1 < section.photo_starts.length ? section.photo_starts[idx + 1] : section.section_seconds) - section.photo_starts[idx];
      incoming.style.setProperty('--kb', (dur + xf) + 's');
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
    cumulativeBefore = cumulativeFor(manifest.sections.length);
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
      progressThumb.style.left = '100%';
      if (e >= C.end_card_seconds) finish();
      return;
    }
    if (state !== 'PLAYING' || !section) return;

    var t = elapsed();
    var n = section.photos.length;

    // Which photo should be up?
    if (t >= section.section_seconds) { startSection(sectionIndex + 1); return; }
    var idx = photoIndexAt(section, t);
    if (idx >= n) { startSection(sectionIndex + 1); return; }
    if (idx >= 0 && idx !== shownIdx) showPhoto(idx);

    // Next cue (another track) due?
    if (!seekHold && cueIndex + 1 < section.cues.length && t >= section.cues[cueIndex + 1].start_at) {
      enterCue(cueIndex + 1, t - section.cues[cueIndex + 1].start_at);
    }

    // Music: restart the track when it runs out while photos remain, and
    // fade it out over the last seconds of the cue.
    if (cueHasMusic()) {
      var a = audios[activeAudio];
      var cueEnd = cue.start_at + cue.segment_seconds;
      if (!seekHold && !looping && cueEnd - t > C.music_loop_xfade_seconds && (a.ended || a.currentTime >= restartPoint(a))) {
        restartTrack();
      }
      if (cue.fade_out_at !== null && t >= cue.fade_out_at) {
        setVolume(audios[activeAudio], Math.max(0, (cueEnd - t) / C.music_fade_out_seconds));
      }
    }
    // Preload the next section's first track and photo ~10 s early on the idle element.
    if (!nextPreloaded && section.section_seconds - t < 10 && sectionIndex + 1 < manifest.sections.length) {
      nextPreloaded = true;
      var ns = manifest.sections[sectionIndex + 1];
      var idle = audios[1 - activeAudio];
      var nt = ns.cues[0] && ns.cues[0].track;
      if (nt && !looping && idle.paused && cueIndex + 1 >= section.cues.length) { idle.src = nt.url; idle.load(); }
      if (ns.photos[0]) preload(ns.photos[0].url);
    }

    // HUD.
    if (!progressEl.isDragging || !progressEl.isDragging()) {
      var pct = Math.min(100, ((cumulativeBefore + t) / manifest.total_seconds) * 100);
      progressBar.style.width = pct + '%';
      progressThumb.style.left = pct + '%';
      progressEl.setAttribute('aria-valuenow', String(Math.round(pct)));
    }
    hudText.textContent = section.title + (shownIdx >= 0 ? ' · ' + (shownIdx + 1) + ' / ' + n : '')
      + (section.cues.length > 1 && cue.track ? ' · \u266b ' + cue.track.title : '')
      + ' · ' + fmt(cumulativeBefore + t) + ' / ' + fmt(manifest.total_seconds);
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
    if (cueHasMusic()) audios[activeAudio].play().catch(function () {});
    var p = document.getElementById('paused');
    if (p) p.remove();
  }

  function seekPhotos(delta) {
    if (state !== 'PLAYING' || !section) return;
    var idx = Math.max(0, photoIndexAt(section, elapsed())) + delta;
    idx = Math.max(0, Math.min(section.photos.length - 1, idx));
    seekTo(cumulativeBefore + section.photo_starts[idx] + 0.05);
  }

  function nextSection() { if (state === 'PLAYING') startSection(sectionIndex + 1); }
  function prevSection() {
    if (state !== 'PLAYING') return;
    // Early in a section, go back one; otherwise restart this one.
    startSection(elapsed() > C.title_card_seconds + 2 || sectionIndex === 0 ? sectionIndex : sectionIndex - 1);
  }

  // ---------------------------------------------------------------------------
  // Seeking (scrubber)
  // ---------------------------------------------------------------------------

  // Describe a point in the whole slideshow: which section and how far into it.
  function locate(globalSeconds) {
    var t = Math.max(0, Math.min(manifest.total_seconds, globalSeconds));
    var acc = 0;
    for (var i = 0; i < manifest.sections.length; i++) {
      var len = manifest.sections[i].section_seconds;
      if (t < acc + len) return { section: i, within: t - acc };
      acc += len;
    }
    return { section: manifest.sections.length, within: t - acc }; // end card
  }

  function describe(globalSeconds) {
    var loc = locate(globalSeconds);
    if (loc.section >= manifest.sections.length) return manifest.end_card.title + ' \u00b7 ' + fmt(globalSeconds);
    var sec = manifest.sections[loc.section];
    var idx = photoIndexAt(sec, loc.within);
    var where = idx < 0 ? 'title' : (idx + 1) + ' / ' + sec.photos.length;
    return sec.title + ' \u00b7 ' + where + ' \u00b7 ' + fmt(globalSeconds);
  }

  // Jump to an absolute point: the right section and cue, music moved to the
  // matching point, the right photo shown immediately.
  function seekTo(globalSeconds) {
    if (state === 'IDLE' || state === 'READY' || state === 'DONE') return;
    var loc = locate(globalSeconds);
    if (loc.section >= manifest.sections.length) { showEndCard(); return; }
    var wasPaused = paused;
    if (state !== 'PLAYING' || loc.section !== sectionIndex) startSection(loc.section);
    var ts = Math.max(0, Math.min(section.section_seconds - 0.05, loc.within));

    var k = 0;
    for (var i = 0; i < section.cues.length; i++) { if (ts >= section.cues[i].start_at) k = i; }
    enterCue(k, ts - section.cues[k].start_at);

    var idx = photoIndexAt(section, ts);
    if (idx < 0) {
      shownIdx = -1;
      layers.forEach(function (l) { l.classList.remove('active'); clearLayerAnims(l); });
      captionEl.classList.add('hidden');
      card.classList.remove('hidden');
      card.classList.add('show');
    } else if (idx !== shownIdx) {
      showPhoto(Math.min(idx, section.photos.length - 1));
    }
    if (wasPaused && !paused) pause(); // startSection() resumes; stay paused if we were
  }

  function wireScrubber() {
    var dragging = false;

    function fractionFrom(clientX) {
      var r = progressEl.getBoundingClientRect();
      return Math.max(0, Math.min(1, (clientX - r.left) / r.width));
    }
    function preview(frac) {
      var pct = frac * 100;
      progressThumb.style.left = pct + '%';
      progressBar.style.width = pct + '%';
      progressTip.style.left = pct + '%';
      progressTip.textContent = describe(frac * manifest.total_seconds);
      progressTip.classList.remove('hidden');
    }

    progressEl.addEventListener('pointerdown', function (e) {
      if (state === 'IDLE' || state === 'READY' || state === 'DONE') return;
      e.preventDefault();
      e.stopPropagation();
      dragging = true;
      progressEl.classList.add('dragging');
      progressEl.setPointerCapture(e.pointerId);
      preview(fractionFrom(e.clientX));
    });
    progressEl.addEventListener('pointermove', function (e) {
      if (dragging) { preview(fractionFrom(e.clientX)); activity(); return; }
      if (manifest && state !== 'IDLE' && state !== 'READY') {
        var f = fractionFrom(e.clientX);
        progressTip.style.left = (f * 100) + '%';
        progressTip.textContent = describe(f * manifest.total_seconds);
        progressTip.classList.remove('hidden');
      }
    });
    progressEl.addEventListener('pointerleave', function () { if (!dragging) progressTip.classList.add('hidden'); });
    function finish(e) {
      if (!dragging) return;
      dragging = false;
      progressEl.classList.remove('dragging');
      progressTip.classList.add('hidden');
      try { progressEl.releasePointerCapture(e.pointerId); } catch (x) {}
      seekTo(fractionFrom(e.clientX) * manifest.total_seconds);
    }
    progressEl.addEventListener('pointerup', finish);
    progressEl.addEventListener('pointercancel', function () { dragging = false; progressEl.classList.remove('dragging'); progressTip.classList.add('hidden'); });
    // Keep a click on the bar from toggling pause on the stage.
    progressEl.addEventListener('click', function (e) { e.stopPropagation(); });
    // While dragging, the frame loop must not move the thumb under the pointer.
    progressEl.isDragging = function () { return dragging; };
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
    wireScrubber();
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
  // Read-only snapshot of the clock, for debugging from the console.
  window.SLIDESHOW_STATE = function () {
    var a = audios[activeAudio];
    return { state: state, section: sectionIndex, cue: cueIndex, elapsed: section ? elapsed() : null, offset: offset, seekHold: !!seekHold,
             looping: looping, activeAudio: activeAudio, currentTime: a.currentTime, audioPaused: a.paused, audioEnded: a.ended, paused: paused, shownIdx: shownIdx };
  };

  load();
  if (beginBtn) {
    var restoreLabel = setInterval(function () {
      if (state === 'READY') { beginBtn.textContent = 'Click to begin'; clearInterval(restoreLabel); }
    }, 100);
  }
})();
