// Event photos (event_photos.php): upload straight to Cloudflare R2 with
// presigned URLs minted by event_photo_presign.php, then tell
// event_photo_attach.php what was uploaded. The server never sees the bytes.
//
// For each chosen file the browser:
//   1. hashes it (SHA-256, for same-event dedup) and reads the EXIF capture
//      date (JPEG only) so photos sort chronologically;
//   2. decodes it, applying EXIF orientation, and draws two JPEG renditions
//      on a canvas: "display" (2048px long edge) and "thumb" (400px);
//   3. asks for three presigned PUT URLs sharing one stem, PUTs the three
//      blobs with XMLHttpRequest (fetch cannot report upload progress);
//   4. POSTs attach, which HEADs the objects, records the row and returns
//      the rendered tile plus its position in the gallery order.
//
// Also wires the gallery: lightbox, caption / exclude-from-slideshow / delete
// for the uploader or admins, and the admin "Reorder photos" mode.
//
// window.Pack440Photos.putToStorage is exposed for the slideshow editor's
// music upload, which follows the same presign -> PUT -> attach pattern.

(function () {
  'use strict';

  // ---------------------------------------------------------------------------
  // Shared helpers
  // ---------------------------------------------------------------------------

  function humanMB(bytes) {
    return bytes >= 1024 * 1024 ? (bytes / (1024 * 1024)).toFixed(1) + ' MB' : Math.round(bytes / 1024) + ' KB';
  }

  function postForm(url, fields) {
    var form = new URLSearchParams();
    Object.keys(fields).forEach(function (k) {
      if (fields[k] !== undefined && fields[k] !== null) form.set(k, String(fields[k]));
    });
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: form.toString()
    }).then(function (r) {
      return r.text().then(function (text) {
        var data = null;
        try { data = JSON.parse(text); } catch (e) { /* not JSON */ }
        if (!data) throw new Error(r.ok ? 'Unexpected response from the server.' : 'Server error (' + r.status + ').');
        if (!data.ok) throw new Error(data.error || 'Request failed.');
        return data;
      });
    });
  }

  function storageErrorDetail(text) {
    if (!text) return '';
    var code = (text.match(/<Code>([^<]*)<\/Code>/) || [])[1];
    var message = (text.match(/<Message>([^<]*)<\/Message>/) || [])[1];
    var parts = [code, message].filter(Boolean);
    return parts.length ? ' — ' + parts.join(': ') : '';
  }

  // PUT one blob to R2 using a presign grant {url, headers}. Resolves with the grant.
  function putToStorage(blob, grant, onProgress) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open('PUT', grant.url, true);
      Object.keys(grant.headers || {}).forEach(function (name) {
        xhr.setRequestHeader(name, grant.headers[name]);
      });
      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable && onProgress) onProgress(e.loaded, e.total);
      };
      xhr.onload = function () {
        if (xhr.status >= 200 && xhr.status < 300) { resolve(grant); return; }
        var detail = storageErrorDetail(xhr.responseText);
        if (xhr.status === 403) {
          reject(new Error('Storage refused the upload (403' + detail + '). The upload link may have expired or the bucket CORS rule may be missing (Admin → Photo Storage).'));
        } else {
          reject(new Error('Storage returned HTTP ' + xhr.status + detail + '.'));
        }
      };
      xhr.onerror = function () {
        reject(new Error('Network error while uploading. If this repeats, the bucket may be missing its CORS rule (Admin → Photo Storage).'));
      };
      xhr.onabort = function () { reject(new Error('Upload cancelled.')); };
      xhr.send(blob);
    });
  }

  window.Pack440Photos = { putToStorage: putToStorage, postForm: postForm };

  // ---------------------------------------------------------------------------
  // Image analysis: SHA-256, EXIF capture date, decode + renditions
  // ---------------------------------------------------------------------------

  function sha256Hex(buffer) {
    if (!(window.crypto && crypto.subtle && crypto.subtle.digest)) return Promise.resolve(null);
    return crypto.subtle.digest('SHA-256', buffer).then(function (hash) {
      return Array.prototype.map.call(new Uint8Array(hash), function (b) {
        return ('0' + b.toString(16)).slice(-2);
      }).join('');
    }).catch(function () { return null; });
  }

  // Read EXIF DateTimeOriginal (0x9003) or DateTime (0x0132) from a JPEG.
  // Returns 'YYYY-MM-DD HH:MM:SS' or null. Only the first 128 KB are examined.
  function exifDate(buffer) {
    try {
      var view = new DataView(buffer, 0, Math.min(buffer.byteLength, 131072));
      if (view.byteLength < 4 || view.getUint16(0) !== 0xFFD8) return null;
      var offset = 2;
      while (offset + 4 <= view.byteLength) {
        if (view.getUint8(offset) !== 0xFF) return null;
        var marker = view.getUint8(offset + 1);
        if (marker === 0xD8 || marker === 0x01 || (marker >= 0xD0 && marker <= 0xD7)) { offset += 2; continue; }
        if (marker === 0xDA || marker === 0xD9) return null; // image data / end: no EXIF
        var length = view.getUint16(offset + 2);
        if (marker === 0xE1 && offset + 10 <= view.byteLength && view.getUint32(offset + 4) === 0x45786966) { // "Exif"
          return parseTiff(view, offset + 10);
        }
        offset += 2 + length;
      }
    } catch (e) { /* malformed: ignore */ }
    return null;
  }

  function parseTiff(view, tiff) {
    if (tiff + 8 > view.byteLength) return null;
    var bo = view.getUint16(tiff);
    var little;
    if (bo === 0x4949) little = true; else if (bo === 0x4D4D) little = false; else return null;
    var u16 = function (o) { return view.getUint16(o, little); };
    var u32 = function (o) { return view.getUint32(o, little); };
    var readAscii = function (o, n) {
      var s = '';
      for (var i = 0; i < n && o + i < view.byteLength; i++) {
        var c = view.getUint8(o + i);
        if (c === 0) break;
        s += String.fromCharCode(c);
      }
      return s;
    };
    var readIfd = function (ifdOffset) {
      var out = {};
      var abs = tiff + ifdOffset;
      if (abs + 2 > view.byteLength) return out;
      var count = u16(abs);
      for (var i = 0; i < count; i++) {
        var entry = abs + 2 + i * 12;
        if (entry + 12 > view.byteLength) break;
        var tag = u16(entry), type = u16(entry + 2), n = u32(entry + 4);
        var valueOffset = entry + 8;
        if (type === 2) { // ASCII
          var strOffset = n > 4 ? tiff + u32(valueOffset) : valueOffset;
          out[tag] = readAscii(strOffset, n);
        } else if (type === 4) {
          out[tag] = u32(valueOffset);
        }
      }
      return out;
    };
    var ifd0 = readIfd(u32(tiff + 4));
    var dt = null;
    if (ifd0[0x8769]) {
      var exif = readIfd(ifd0[0x8769]);
      dt = exif[0x9003] || exif[0x9004] || null;
    }
    if (!dt) dt = ifd0[0x0132] || null;
    if (!dt) return null;
    var m = /^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})/.exec(dt);
    if (!m || m[1] === '0000') return null;
    return m[1] + '-' + m[2] + '-' + m[3] + ' ' + m[4] + ':' + m[5] + ':' + m[6];
  }

  function pad2(n) { return (n < 10 ? '0' : '') + n; }

  function localDateTime(ms) {
    var d = new Date(ms);
    if (isNaN(d.getTime()) || ms <= 0) return null;
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + ' '
      + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
  }

  // Decode a file into something drawImage() accepts, with EXIF orientation applied.
  function decodeImage(file) {
    var viaImg = function () {
      return new Promise(function (resolve, reject) {
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function () {
          var done = function () { resolve({ source: img, width: img.naturalWidth, height: img.naturalHeight, release: function () { URL.revokeObjectURL(url); } }); };
          if (img.decode) img.decode().then(done, done); else done();
        };
        img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('undecodable')); };
        img.src = url;
      });
    };
    if (window.createImageBitmap) {
      return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
        return { source: bmp, width: bmp.width, height: bmp.height, release: function () { if (bmp.close) bmp.close(); } };
      }).catch(viaImg);
    }
    return viaImg();
  }

  function renditionBlob(decoded, longEdge, quality) {
    var w = decoded.width, h = decoded.height;
    var scale = Math.min(1, longEdge / Math.max(w, h));
    var cw = Math.max(1, Math.round(w * scale)), ch = Math.max(1, Math.round(h * scale));
    var canvas = document.createElement('canvas');
    canvas.width = cw; canvas.height = ch;
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff'; // transparent PNGs get a white background in the JPEG renditions
    ctx.fillRect(0, 0, cw, ch);
    ctx.drawImage(decoded.source, 0, 0, cw, ch);
    return new Promise(function (resolve, reject) {
      canvas.toBlob(function (blob) {
        if (blob) resolve(blob); else reject(new Error('Could not encode the image.'));
      }, 'image/jpeg', quality);
    });
  }

  function guessType(name) {
    var ext = (name.split('.').pop() || '').toLowerCase();
    return { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', gif: 'image/gif' }[ext] || '';
  }

  // ---------------------------------------------------------------------------
  // Uploader
  // ---------------------------------------------------------------------------

  function wireUploader(form, grid) {
    var eventId = form.getAttribute('data-event-id');
    var presignUrl = form.getAttribute('data-presign-url');
    var attachUrl = form.getAttribute('data-attach-url');
    var maxBytes = parseInt(form.getAttribute('data-max-bytes'), 10) || Infinity;
    var displayEdge = parseInt(form.getAttribute('data-display-edge'), 10) || 2048;
    var thumbEdge = parseInt(form.getAttribute('data-thumb-edge'), 10) || 400;
    var csrf = (form.querySelector('input[name="csrf"]') || {}).value || '';
    var fileInput = form.querySelector('[data-role="file"]');
    var dropzone = form.querySelector('[data-role="dropzone"]');
    var queueEl = document.getElementById('uploadQueue');
    var CONCURRENCY = 2;

    var queue = [];
    var active = 0;

    window.addEventListener('beforeunload', function (e) {
      if (active > 0 || queue.length > 0) { e.preventDefault(); e.returnValue = ''; }
    });

    function addFiles(files) {
      Array.prototype.forEach.call(files, function (f) {
        queue.push({ file: f, row: makeRow(f) });
      });
      pump();
    }

    function pump() {
      while (active < CONCURRENCY && queue.length > 0) {
        var job = queue.shift();
        active++;
        uploadOne(job).catch(function () {}).then(function () { active--; pump(); });
      }
    }

    function makeRow(file) {
      var li = document.createElement('li');
      li.className = 'upload-row';
      li.innerHTML = '<span class="name"></span><span class="status">Waiting…</span><div class="bar"><i></i></div>';
      li.querySelector('.name').textContent = file.name + ' (' + humanMB(file.size) + ')';
      queueEl.appendChild(li);
      return li;
    }

    function setStatus(row, text, isError) {
      var s = row.querySelector('.status');
      s.textContent = text;
      s.classList.toggle('is-error', !!isError);
      if (isError) row.classList.add('failed');
    }

    function setProgress(row, pct) {
      row.querySelector('.bar i').style.width = Math.max(0, Math.min(100, pct)) + '%';
    }

    function uploadOne(job) {
      var file = job.file, row = job.row;
      var type = (file.type || guessType(file.name)).split(';')[0].toLowerCase();
      if (type === 'image/jpg') type = 'image/jpeg';
      if (['image/jpeg', 'image/png', 'image/webp', 'image/gif'].indexOf(type) === -1) {
        setStatus(row, 'Not a supported image type' + (/hei[cf]$/i.test(file.name) ? ' (HEIC). Convert it to JPEG and try again.' : '.'), true);
        return Promise.resolve();
      }
      if (file.size <= 0) { setStatus(row, 'This file is empty.', true); return Promise.resolve(); }
      if (file.size > maxBytes) { setStatus(row, 'Too large: over the ' + humanMB(maxBytes) + ' limit.', true); return Promise.resolve(); }

      var info = { type: type, sha: null, takenAt: null, source: 'upload', width: 0, height: 0 };
      var decoded = null, displayBlob = null, thumbBlob = null;

      setStatus(row, 'Reading…');
      return file.arrayBuffer().then(function (buf) {
        if (type === 'image/jpeg') info.takenAt = exifDate(buf);
        if (info.takenAt) info.source = 'exif';
        else {
          info.takenAt = localDateTime(file.lastModified);
          info.source = info.takenAt ? 'file' : 'upload';
        }
        return sha256Hex(buf);
      }).then(function (sha) {
        info.sha = sha;
        return decodeImage(file).catch(function () {
          throw new Error('Could not read this image' + (type === 'image/jpeg' ? '' : ' (HEIC or unsupported?)') + '. Convert it to JPEG and try again.');
        });
      }).then(function (d) {
        decoded = d;
        info.width = d.width; info.height = d.height;
        setStatus(row, 'Resizing…');
        return renditionBlob(d, displayEdge, 0.85);
      }).then(function (b) {
        displayBlob = b;
        return renditionBlob(decoded, thumbEdge, 0.82);
      }).then(function (b) {
        thumbBlob = b;
        decoded.release();
        setStatus(row, 'Preparing upload…');
        return postForm(presignUrl, { csrf: csrf, event_id: eventId, content_type: type, size: file.size, sha256: info.sha });
      }).then(function (data) {
        if (data.duplicate) {
          setStatus(row, 'Already in this event.');
          row.classList.add('done');
          return null;
        }
        var blobs = [
          { blob: thumbBlob, grant: data.grants.thumb },
          { blob: displayBlob, grant: data.grants.display },
          { blob: file, grant: data.grants.original }
        ];
        var total = blobs.reduce(function (s, b) { return s + b.blob.size; }, 0);
        var doneBytes = 0;
        var chain = Promise.resolve();
        blobs.forEach(function (b) {
          chain = chain.then(function () {
            return putToStorage(b.blob, b.grant, function (loaded) {
              var pct = ((doneBytes + loaded) / total) * 100;
              setProgress(row, pct);
              setStatus(row, 'Uploading… ' + Math.round(pct) + '%');
            }).then(function () { doneBytes += b.blob.size; });
          });
        });
        return chain.then(function () {
          setProgress(row, 100);
          setStatus(row, 'Saving…');
          return postForm(attachUrl, {
            csrf: csrf, event_id: eventId, stem: data.stem, content_type: type,
            width: info.width, height: info.height, byte_length: file.size, sha256: info.sha,
            taken_at: info.takenAt, taken_at_source: info.source
          });
        }).then(function (res) {
          insertTile(grid, res.tile_html, res.index);
          updateCount(res.count);
          setStatus(row, 'Done');
          row.classList.add('done');
          setTimeout(function () { if (row.parentNode) row.parentNode.removeChild(row); }, 4000);
        });
      }).catch(function (e) {
        if (decoded) { try { decoded.release(); } catch (x) {} }
        setStatus(row, e.message || 'Upload failed.', true);
      });
    }

    if (fileInput) {
      fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files.length) addFiles(fileInput.files);
        fileInput.value = '';
      });
    }
    if (dropzone) {
      ['dragenter', 'dragover'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.add('over'); });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) { e.preventDefault(); dropzone.classList.remove('over'); });
      });
      dropzone.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
      });
    }
  }

  // ---------------------------------------------------------------------------
  // Gallery: tiles, count, lightbox
  // ---------------------------------------------------------------------------

  function tiles(grid) { return Array.prototype.slice.call(grid.querySelectorAll('.photo-tile')); }

  function insertTile(grid, html, index) {
    var wrap = document.createElement('div');
    wrap.innerHTML = html;
    var tile = wrap.firstElementChild;
    if (!tile) return;
    var all = tiles(grid);
    if (index >= 0 && index < all.length) grid.insertBefore(tile, all[index]); else grid.appendChild(tile);
    var empty = document.getElementById('emptyNote');
    if (empty) empty.remove();
    renumber(grid);
  }

  function renumber(grid) {
    tiles(grid).forEach(function (t, i) { t.setAttribute('data-index', String(i)); });
  }

  function updateCount(n) {
    var c = document.getElementById('photoCount');
    var s = document.getElementById('photoCountS');
    if (c) c.textContent = String(n);
    if (s) s.textContent = n === 1 ? '' : 's';
  }

  function replaceTile(grid, oldTile, html) {
    var wrap = document.createElement('div');
    wrap.innerHTML = html;
    var fresh = wrap.firstElementChild;
    if (!fresh) return oldTile;
    fresh.setAttribute('data-index', oldTile.getAttribute('data-index'));
    oldTile.replaceWith(fresh);
    return fresh;
  }

  function wireGallery(grid) {
    var csrf = grid.getAttribute('data-csrf') || '';
    var updateUrl = grid.getAttribute('data-update-url');
    var deleteUrl = grid.getAttribute('data-delete-url');
    var reorderUrl = grid.getAttribute('data-reorder-url');
    var eventId = grid.getAttribute('data-event-id');
    var reordering = false;

    // --- Lightbox ---------------------------------------------------------
    var lb = document.getElementById('photoLightbox');
    var lbImg = document.getElementById('lightboxImg');
    var lbMeta = document.getElementById('lightboxMeta');
    var lbCaptionView = document.getElementById('lightboxCaptionView');
    var lbCaptionForm = document.getElementById('lightboxCaptionForm');
    var lbCaptionInput = document.getElementById('lightboxCaptionInput');
    var lbToggle = document.getElementById('lightboxToggle');
    var lbDelete = document.getElementById('lightboxDelete');
    var lbOriginal = document.getElementById('lightboxOriginal');
    var current = null; // the tile being shown

    function show(el, on) { if (el) el.classList.toggle('hidden', !on); }

    function openLightbox(tile) {
      current = tile;
      lbImg.src = tile.getAttribute('data-display-url');
      lbImg.alt = tile.getAttribute('data-caption') || '';
      lbOriginal.href = tile.getAttribute('data-original-url');
      var meta = [];
      var taken = tile.getAttribute('data-taken-text');
      var uploader = tile.getAttribute('data-uploader');
      if (taken) meta.push(taken);
      if (uploader) meta.push('Uploaded by ' + uploader);
      meta.push((parseInt(tile.getAttribute('data-index'), 10) + 1) + ' of ' + tiles(grid).length);
      lbMeta.textContent = meta.join(' · ');
      var canModify = tile.getAttribute('data-can-modify') === '1';
      var caption = tile.getAttribute('data-caption') || '';
      if (canModify) {
        show(lbCaptionView, false);
        show(lbCaptionForm, true);
        lbCaptionInput.value = caption;
        var excluded = tile.getAttribute('data-excluded') === '1';
        lbToggle.textContent = excluded ? 'Include in slideshow' : 'Exclude from slideshow';
        show(lbToggle, true);
        show(lbDelete, true);
      } else {
        lbCaptionView.textContent = caption;
        show(lbCaptionView, caption !== '');
        show(lbCaptionForm, false);
        show(lbToggle, false);
        show(lbDelete, false);
      }
      lb.classList.remove('hidden');
      lb.setAttribute('aria-hidden', 'false');
      preloadNeighbors(tile);
      if (history.replaceState) history.replaceState(null, '', '#photo-' + tile.getAttribute('data-photo-id'));
    }

    function closeLightbox() {
      lb.classList.add('hidden');
      lb.setAttribute('aria-hidden', 'true');
      lbImg.src = '';
      current = null;
      if (history.replaceState) history.replaceState(null, '', location.pathname + location.search);
    }

    function neighbor(dir) {
      if (!current) return null;
      var all = tiles(grid);
      var i = all.indexOf(current) + dir;
      if (i < 0 || i >= all.length) return null;
      return all[i];
    }

    function preloadNeighbors(tile) {
      [1, -1].forEach(function (d) {
        var all = tiles(grid);
        var n = all[all.indexOf(tile) + d];
        if (n) { var img = new Image(); img.src = n.getAttribute('data-display-url'); }
      });
    }

    document.getElementById('lightboxClose').addEventListener('click', closeLightbox);
    document.getElementById('lightboxPrev').addEventListener('click', function () { var n = neighbor(-1); if (n) openLightbox(n); });
    document.getElementById('lightboxNext').addEventListener('click', function () { var n = neighbor(1); if (n) openLightbox(n); });
    lb.addEventListener('click', function (e) { if (e.target === lb) closeLightbox(); });
    document.addEventListener('keydown', function (e) {
      if (lb.classList.contains('hidden')) return;
      if (e.key === 'Escape') closeLightbox();
      else if (e.key === 'ArrowLeft') { var p = neighbor(-1); if (p) openLightbox(p); }
      else if (e.key === 'ArrowRight') { var n = neighbor(1); if (n) openLightbox(n); }
    });

    lbCaptionForm.addEventListener('submit', function () {
      if (!current) return;
      var tile = current;
      postForm(updateUrl, { csrf: csrf, photo_id: tile.getAttribute('data-photo-id'), action: 'caption', caption: lbCaptionInput.value })
        .then(function (res) {
          current = replaceTile(grid, tile, res.tile_html);
          lbCaptionInput.blur();
        })
        .catch(function (e) { alert(e.message); });
    });

    lbToggle.addEventListener('click', function () { if (current) toggleExclude(current, function (fresh) { current = fresh; openLightbox(fresh); }); });
    lbDelete.addEventListener('click', function () {
      if (!current) return;
      var next = neighbor(1) || neighbor(-1);
      var tile = current;
      deletePhoto(tile, function () { if (next && next.parentNode) openLightbox(next); else closeLightbox(); });
    });

    // --- Tile actions -----------------------------------------------------
    function toggleExclude(tile, done) {
      var exclude = tile.getAttribute('data-excluded') === '1' ? '0' : '1';
      postForm(updateUrl, { csrf: csrf, photo_id: tile.getAttribute('data-photo-id'), action: 'exclude', exclude: exclude })
        .then(function (res) {
          var fresh = replaceTile(grid, tile, res.tile_html);
          if (done) done(fresh);
        })
        .catch(function (e) { alert(e.message); });
    }

    function deletePhoto(tile, done) {
      if (!confirm('Delete this photo? This cannot be undone.')) return;
      postForm(deleteUrl, { csrf: csrf, photo_id: tile.getAttribute('data-photo-id') })
        .then(function (res) {
          tile.remove();
          renumber(grid);
          updateCount(res.count);
          if (done) done();
        })
        .catch(function (e) { alert(e.message); });
    }

    grid.addEventListener('click', function (e) {
      var tile = e.target.closest('.photo-tile');
      if (!tile || !grid.contains(tile)) return;
      if (reordering) {
        e.preventDefault();
        if (e.target.closest('.move-left')) moveTile(tile, -1);
        else if (e.target.closest('.move-right')) moveTile(tile, 1);
        return;
      }
      if (e.target.closest('.toggle-slideshow')) { e.preventDefault(); toggleExclude(tile); return; }
      if (e.target.closest('.delete')) { e.preventDefault(); deletePhoto(tile); return; }
      if (e.target.closest('.photo-open')) { e.preventDefault(); openLightbox(tile); }
    });

    // Deep link: #photo-123 opens that photo.
    var m = /^#photo-(\d+)$/.exec(location.hash || '');
    if (m) {
      var target = grid.querySelector('.photo-tile[data-photo-id="' + m[1] + '"]');
      if (target) openLightbox(target);
    }

    // --- Admin reorder mode -----------------------------------------------
    var reorderBtn = document.getElementById('reorderBtn');
    var saveBtn = document.getElementById('saveOrderBtn');
    var cancelBtn = document.getElementById('cancelOrderBtn');
    var resetBtn = document.getElementById('resetOrderBtn');
    var originalOrder = null;

    function setReordering(on) {
      reordering = on;
      grid.classList.toggle('reordering', on);
      show(reorderBtn, !on);
      show(saveBtn, on);
      show(cancelBtn, on);
      if (resetBtn) show(resetBtn, !on);
      tiles(grid).forEach(function (t) { t.draggable = on; });
    }

    function moveTile(tile, dir) {
      var all = tiles(grid);
      var i = all.indexOf(tile);
      var j = i + dir;
      if (j < 0 || j >= all.length) return;
      if (dir < 0) grid.insertBefore(tile, all[j]); else grid.insertBefore(all[j], tile);
      renumber(grid);
    }

    if (reorderBtn) {
      reorderBtn.addEventListener('click', function () {
        originalOrder = tiles(grid);
        setReordering(true);
      });
      cancelBtn.addEventListener('click', function () {
        if (originalOrder) originalOrder.forEach(function (t) { grid.appendChild(t); });
        renumber(grid);
        setReordering(false);
      });
      saveBtn.addEventListener('click', function () {
        var ids = tiles(grid).map(function (t) { return t.getAttribute('data-photo-id'); }).join(',');
        saveBtn.disabled = true;
        postForm(reorderUrl, { csrf: csrf, event_id: eventId, action: 'set', ids: ids })
          .then(function () { location.reload(); })
          .catch(function (e) { saveBtn.disabled = false; alert(e.message); });
      });

      var dragging = null;
      grid.addEventListener('dragstart', function (e) {
        var tile = e.target.closest('.photo-tile');
        if (!reordering || !tile) return;
        dragging = tile;
        tile.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', tile.getAttribute('data-photo-id')); } catch (x) {}
      });
      grid.addEventListener('dragover', function (e) {
        if (!reordering || !dragging) return;
        e.preventDefault();
        var over = e.target.closest('.photo-tile');
        if (!over || over === dragging) return;
        var rect = over.getBoundingClientRect();
        var before = (e.clientX - rect.left) < rect.width / 2;
        if (before) grid.insertBefore(dragging, over); else grid.insertBefore(dragging, over.nextSibling);
      });
      grid.addEventListener('drop', function (e) { if (reordering) e.preventDefault(); });
      grid.addEventListener('dragend', function () {
        if (dragging) dragging.classList.remove('dragging');
        dragging = null;
        renumber(grid);
      });
    }
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        if (!confirm('Clear the manual order and sort these photos by the date they were taken?')) return;
        postForm(reorderUrl, { csrf: csrf, event_id: eventId, action: 'reset' })
          .then(function () { location.reload(); })
          .catch(function (e) { alert(e.message); });
      });
    }
  }

  // ---------------------------------------------------------------------------

  function init() {
    var grid = document.getElementById('photoGrid');
    if (grid) wireGallery(grid);
    var form = document.getElementById('photoUploader');
    if (form && grid) wireUploader(form, grid);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
