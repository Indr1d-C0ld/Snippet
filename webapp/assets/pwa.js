/* snippet - registrazione service worker + coda bozze offline.
   Su compose.php intercetta l'invio: online -> POST ajax e vai alla voce;
   offline -> salva la bozza in IndexedDB e sincronizza al ritorno online.
   Su ogni altra pagina si limita a registrare il SW e a drenare la coda. */
(function () {
  'use strict';

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function (e) {
        console.warn('SW non registrato:', e);
      });
    });
  }

  var DB = 'snippet-drafts', STORE = 'queue';

  function withStore(mode) {
    return new Promise(function (res, rej) {
      var r = indexedDB.open(DB, 1);
      r.onupgradeneeded = function () {
        r.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
      };
      r.onsuccess = function () {
        var tx = r.result.transaction(STORE, mode);
        res(tx.objectStore(STORE));
      };
      r.onerror = function () { rej(r.error); };
    });
  }
  function qAdd(rec) {
    return withStore('readwrite').then(function (s) {
      return new Promise(function (res, rej) {
        var r = s.add(rec); r.onsuccess = function () { res(); }; r.onerror = function () { rej(r.error); };
      });
    });
  }
  function qAll() {
    return withStore('readonly').then(function (s) {
      return new Promise(function (res, rej) {
        var r = s.getAll(); r.onsuccess = function () { res(r.result || []); }; r.onerror = function () { rej(r.error); };
      });
    });
  }
  function qDel(id) {
    return withStore('readwrite').then(function (s) { s.delete(id); });
  }

  function toast(msg) {
    var t = document.getElementById('pwa-toast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'pwa-toast';
      t.style.cssText = 'position:fixed;left:50%;bottom:16px;transform:translateX(-50%);' +
        'background:var(--surface,#fff);color:var(--fg,#111);border:1px solid var(--border,#ccc);' +
        'border-radius:8px;padding:10px 14px;font-size:.85rem;z-index:9999;max-width:92%;box-shadow:0 2px 10px rgba(0,0,0,.15)';
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.display = 'block';
    clearTimeout(t._h);
    t._h = setTimeout(function () { t.style.display = 'none'; }, 4500);
  }

  function csrf() {
    var m = document.querySelector('meta[name="csrf"]');
    return m ? m.getAttribute('content') : '';
  }

  function postDraft(d) {
    var p = new URLSearchParams();
    p.set('csrf', csrf());
    p.set('ajax', '1');
    p.set('body', d.body || '');
    p.set('title', d.title || '');
    p.set('tags', d.tags || '');
    return fetch('compose.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: p.toString(),
      credentials: 'same-origin'
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  function syncQueue() {
    if (!navigator.onLine || !csrf()) return;
    qAll().then(function (items) {
      if (!items.length) return;
      var done = 0;
      (function next(i) {
        if (i >= items.length) {
          if (done) toast(done + (done === 1 ? ' bozza offline sincronizzata' : ' bozze offline sincronizzate'));
          return;
        }
        var it = items[i];
        postDraft(it)
          .then(function (j) { if (j && j.ok) { done++; return qDel(it.id); } })
          .catch(function () { /* riprovo al prossimo online */ })
          .then(function () { next(i + 1); });
      })(0);
    });
  }

  window.addEventListener('online', syncQueue);
  document.addEventListener('DOMContentLoaded', function () {
    syncQueue();

    var form = document.getElementById('composeForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var d = {
        body: form.elements.body.value,
        title: form.elements.title ? form.elements.title.value : '',
        tags: form.elements.tags ? form.elements.tags.value : ''
      };
      if (!d.body.trim()) { toast('Scrivi qualcosa prima di salvare.'); return; }

      var btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;

      postDraft(d)
        .then(function (j) {
          if (j && j.ok && j.url) { location.href = j.url; return; }
          toast('Errore: ' + ((j && j.error) || 'imprevisto'));
          if (btn) btn.disabled = false;
        })
        .catch(function () {
          qAdd({ body: d.body, title: d.title, tags: d.tags, ts: Date.now() }).then(function () {
            form.elements.body.value = '';
            if (form.elements.title) form.elements.title.value = '';
            if (form.elements.tags) form.elements.tags.value = '';
            if (btn) btn.disabled = false;
            toast('Offline: bozza salvata, verrà inviata al ritorno online.');
          });
        });
    });
  });
})();
