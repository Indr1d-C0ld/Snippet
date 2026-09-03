/* snippet - mappa delle voci.
   Grafo forza-diretto su <canvas>, senza dipendenze esterne
   (la CSP del portale e' default-src 'self'). */
(function () {
  'use strict';

  var canvas = document.getElementById('map-canvas');
  var statusEl = document.getElementById('map-status');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');

  /* ---- colori dal tema corrente ---- */
  function cssvar(name, fallback) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name);
    return (v && v.trim()) || fallback;
  }
  var COL = {};
  function refreshColors() {
    COL.fg     = cssvar('--fg', '#1c1c1e');
    COL.muted  = cssvar('--fg-muted', '#6b7280');
    COL.border = cssvar('--border', '#d9dade');
    COL.surf   = cssvar('--surface', '#ffffff');
    COL.bg     = cssvar('--bg', '#f7f7f8');
    COL.accent = cssvar('--accent', '#2f6feb');
  }
  refreshColors();
  var EDGE_COL = {
    manual:   function () { return COL.accent; },
    tag:      function () { return '#3fa66a'; },
    keyword:  function () { return COL.muted; },
    temporal: function () { return '#9a6dd7'; }
  };

  /* ---- stato ---- */
  var nodes = [], edges = [], byId = {};
  var view = { scale: 1, x: 0, y: 0 };
  var alpha = 1, running = true, frozen = false;
  var W = 1, H = 1, DPR = Math.max(1, window.devicePixelRatio || 1);

  var P = {
    repulsion: 2600, spring: 0.035, springLen: 78,
    gravity: 0.025, damping: 0.86, vmax: 14,
    alphaDecay: 0.018, alphaMin: 0.02, cell: 64
  };

  function radius(n) { return Math.min(22, 4 + Math.sqrt(n.deg || 0) * 2.3); }

  /* ---- caricamento dati ---- */
  function load() {
    var url = window.SNIPPET_MAP_DATA_URL || 'map_data.php';
    statusEl.textContent = 'Carico il grafo…';
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) {
        if (d.error) throw new Error(d.error);
        init(d);
      })
      .catch(function (e) { statusEl.textContent = 'Errore: ' + e.message; });
  }

  function init(d) {
    nodes = (d.nodes || []).map(function (n, i) {
      var ang = (i / Math.max(1, d.nodes.length)) * Math.PI * 2;
      var rad = 40 + Math.sqrt(d.nodes.length) * 26;
      return Object.assign({}, n, {
        x: Math.cos(ang) * rad + (Math.random() - 0.5) * 40,
        y: Math.sin(ang) * rad + (Math.random() - 0.5) * 40,
        vx: 0, vy: 0, fixed: false
      });
    });
    byId = {};
    nodes.forEach(function (n) { byId[n.id] = n; });
    edges = (d.edges || []).filter(function (e) { return byId[e.s] && byId[e.d]; });

    var orphans = nodes.filter(function (n) { return !n.deg; }).length;
    statusEl.textContent = nodes.length + ' voci · ' + edges.length + ' archi · ' + orphans + ' isolate';

    resize();
    fit();
    alpha = 1; running = true; frozen = false;
    requestAnimationFrame(tick);
  }

  /* ---- simulazione ---- */
  function step() {
    var i, j, n, m, dx, dy, d2, d, f;

    // repulsione con griglia spaziale
    var grid = {}, cs = P.cell;
    for (i = 0; i < nodes.length; i++) {
      n = nodes[i];
      var gx = Math.floor(n.x / cs), gy = Math.floor(n.y / cs);
      (grid[gx + ',' + gy] || (grid[gx + ',' + gy] = [])).push(i);
    }
    for (i = 0; i < nodes.length; i++) {
      n = nodes[i];
      var cx = Math.floor(n.x / cs), cy = Math.floor(n.y / cs);
      for (var ox = -1; ox <= 1; ox++) for (var oy = -1; oy <= 1; oy++) {
        var bucket = grid[(cx + ox) + ',' + (cy + oy)];
        if (!bucket) continue;
        for (var k = 0; k < bucket.length; k++) {
          j = bucket[k]; if (j <= i) continue;
          m = nodes[j];
          dx = n.x - m.x; dy = n.y - m.y;
          d2 = dx * dx + dy * dy || 0.01;
          if (d2 > (cs * 3) * (cs * 3)) continue;
          f = P.repulsion / d2;
          var inv = 1 / Math.sqrt(d2);
          var fx = dx * inv * f, fy = dy * inv * f;
          n.vx += fx; n.vy += fy; m.vx -= fx; m.vy -= fy;
        }
      }
    }

    // molle sugli archi
    for (i = 0; i < edges.length; i++) {
      var e = edges[i];
      n = byId[e.s]; m = byId[e.d];
      dx = m.x - n.x; dy = m.y - n.y;
      d = Math.sqrt(dx * dx + dy * dy) || 0.01;
      var rest = P.springLen + radius(n) + radius(m);
      f = (d - rest) * P.spring * (e.kind === 'manual' ? 1.6 : 1);
      var ux = dx / d, uy = dy / d;
      n.vx += ux * f; n.vy += uy * f;
      m.vx -= ux * f; m.vy -= uy * f;
    }

    // gravita' verso il centro + integrazione
    for (i = 0; i < nodes.length; i++) {
      n = nodes[i];
      if (n.fixed) { n.vx = n.vy = 0; continue; }
      n.vx += -n.x * P.gravity * alpha;
      n.vy += -n.y * P.gravity * alpha;
      n.vx *= P.damping; n.vy *= P.damping;
      var sp = Math.sqrt(n.vx * n.vx + n.vy * n.vy);
      if (sp > P.vmax) { n.vx = n.vx / sp * P.vmax; n.vy = n.vy / sp * P.vmax; }
      n.x += n.vx * alpha * 2.2;
      n.y += n.vy * alpha * 2.2;
    }
  }

  /* ---- rendering ---- */
  function toScreen(x, y) {
    return [x * view.scale + view.x + W / 2, y * view.scale + view.y + H / 2];
  }
  function toWorld(px, py) {
    return [(px - W / 2 - view.x) / view.scale, (py - H / 2 - view.y) / view.scale];
  }

  function draw() {
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
    ctx.clearRect(0, 0, W, H);

    // archi
    ctx.lineCap = 'round';
    for (var i = 0; i < edges.length; i++) {
      var e = edges[i], n = byId[e.s], m = byId[e.d];
      var a = toScreen(n.x, n.y), b = toScreen(m.x, m.y);
      ctx.globalAlpha = e.kind === 'manual' ? 0.75 : 0.4;
      ctx.strokeStyle = (EDGE_COL[e.kind] || EDGE_COL.keyword)();
      ctx.lineWidth = Math.max(1, Math.min(4, (e.score || 1) / 4)) * (e.kind === 'manual' ? 1.4 : 1);
      ctx.beginPath();
      ctx.moveTo(a[0], a[1]); ctx.lineTo(b[0], b[1]);
      ctx.stroke();
    }
    ctx.globalAlpha = 1;

    // nodi
    var showLabels = view.scale > 0.75;
    for (var j = 0; j < nodes.length; j++) {
      var nd = nodes[j];
      var s = toScreen(nd.x, nd.y);
      var r = radius(nd) * Math.min(1.6, Math.max(0.7, view.scale));

      if (!nd.deg) {
        ctx.strokeStyle = COL.muted;
        ctx.setLineDash([3, 3]);
        ctx.lineWidth = 1;
        ctx.beginPath(); ctx.arc(s[0], s[1], r + 3, 0, Math.PI * 2); ctx.stroke();
        ctx.setLineDash([]);
      }

      ctx.beginPath();
      ctx.arc(s[0], s[1], r, 0, Math.PI * 2);
      ctx.fillStyle = nd === hoverNode ? COL.accent : COL.surf;
      ctx.fill();
      ctx.lineWidth = nd.pinned ? 2.5 : 1.2;
      ctx.strokeStyle = nd.pinned ? COL.accent : COL.border;
      ctx.stroke();

      if (showLabels || nd === hoverNode || nd.pinned) {
        var label = (nd.pinned ? '📌 ' : '') + nd.label;
        if (label.length > 42) label = label.slice(0, 41) + '…';
        ctx.font = '12px system-ui, sans-serif';
        ctx.fillStyle = COL.fg;
        ctx.globalAlpha = nd === hoverNode ? 1 : 0.85;
        ctx.fillText(label, s[0] + r + 4, s[1] + 4);
        ctx.globalAlpha = 1;
      }
    }
  }

  function tick() {
    if (running && !frozen) {
      step();
      alpha = Math.max(P.alphaMin, alpha * (1 - P.alphaDecay));
      if (alpha <= P.alphaMin + 1e-4 && !dragNode) running = false;
    }
    draw();
    requestAnimationFrame(tick);
  }

  /* ---- viewport ---- */
  function resize() {
    var host = canvas.parentElement;
    W = host.clientWidth;
    H = host.clientHeight;
    canvas.width = Math.round(W * DPR);
    canvas.height = Math.round(H * DPR);
    canvas.style.width = W + 'px';
    canvas.style.height = H + 'px';
  }
  function fit() {
    if (!nodes.length) return;
    var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    nodes.forEach(function (n) {
      if (n.x < minX) minX = n.x; if (n.x > maxX) maxX = n.x;
      if (n.y < minY) minY = n.y; if (n.y > maxY) maxY = n.y;
    });
    var gw = Math.max(1, maxX - minX), gh = Math.max(1, maxY - minY);
    view.scale = Math.min(3, Math.max(0.15, 0.9 * Math.min(W / gw, H / gh)));
    view.x = -((minX + maxX) / 2) * view.scale;
    view.y = -((minY + maxY) / 2) * view.scale;
    running = true; alpha = Math.max(alpha, 0.3);
  }

  /* ---- interazione ---- */
  var pointers = {}, dragNode = null, panning = false, hoverNode = null;
  var downPos = null, moved = 0, pinchDist = 0;

  function nodeAt(px, py) {
    var w = toWorld(px, py), best = null, bestD = 16 / view.scale;
    for (var i = nodes.length - 1; i >= 0; i--) {
      var n = nodes[i];
      var dx = n.x - w[0], dy = n.y - w[1];
      var dd = Math.sqrt(dx * dx + dy * dy) - radius(n);
      if (dd < bestD) { bestD = dd; best = n; }
    }
    return best;
  }
  function relPos(ev) {
    var rc = canvas.getBoundingClientRect();
    return [ev.clientX - rc.left, ev.clientY - rc.top];
  }

  canvas.addEventListener('pointerdown', function (ev) {
    canvas.setPointerCapture(ev.pointerId);
    var p = relPos(ev);
    pointers[ev.pointerId] = p;
    if (Object.keys(pointers).length === 2) {
      var ps = Object.keys(pointers).map(function (k) { return pointers[k]; });
      pinchDist = Math.hypot(ps[0][0] - ps[1][0], ps[0][1] - ps[1][1]);
      dragNode = null; panning = false;
      return;
    }
    downPos = p; moved = 0;
    dragNode = nodeAt(p[0], p[1]);
    panning = !dragNode;
    if (dragNode) { dragNode.fixed = true; running = true; alpha = Math.max(alpha, 0.3); }
  });

  canvas.addEventListener('pointermove', function (ev) {
    var p = relPos(ev);
    if (pointers[ev.pointerId]) pointers[ev.pointerId] = p;
    var ids = Object.keys(pointers);

    if (ids.length === 2) {
      var ps = ids.map(function (k) { return pointers[k]; });
      var nd = Math.hypot(ps[0][0] - ps[1][0], ps[0][1] - ps[1][1]);
      if (pinchDist > 0) {
        var mid = [(ps[0][0] + ps[1][0]) / 2, (ps[0][1] + ps[1][1]) / 2];
        zoomAt(mid[0], mid[1], nd / pinchDist);
      }
      pinchDist = nd;
      return;
    }

    if (!downPos) {
      var h = nodeAt(p[0], p[1]);
      if (h !== hoverNode) { hoverNode = h; canvas.style.cursor = h ? 'pointer' : 'default'; }
      return;
    }
    moved += Math.hypot(p[0] - downPos[0], p[1] - downPos[1]);
    if (dragNode) {
      var w = toWorld(p[0], p[1]);
      dragNode.x = w[0]; dragNode.y = w[1]; dragNode.vx = dragNode.vy = 0;
      alpha = Math.max(alpha, 0.25); running = true;
    } else if (panning) {
      view.x += p[0] - downPos[0];
      view.y += p[1] - downPos[1];
      downPos = p;
    }
  });

  function endPointer(ev) {
    var p = relPos(ev);
    delete pointers[ev.pointerId];
    if (downPos && moved < 4 && dragNode) {
      var slug = dragNode.slug || dragNode.id;
      window.location.href = 'entry.php?e=' + encodeURIComponent(slug);
      return;
    }
    if (dragNode && moved < 4) dragNode.fixed = false; // tap a vuoto sul nodo: sblocca
    downPos = null; dragNode = null; panning = false;
    if (Object.keys(pointers).length < 2) pinchDist = 0;
  }
  canvas.addEventListener('pointerup', endPointer);
  canvas.addEventListener('pointercancel', endPointer);

  function zoomAt(px, py, factor) {
    var before = toWorld(px, py);
    view.scale = Math.min(6, Math.max(0.08, view.scale * factor));
    var after = toWorld(px, py);
    view.x += (after[0] - before[0]) * view.scale;
    view.y += (after[1] - before[1]) * view.scale;
  }
  canvas.addEventListener('wheel', function (ev) {
    ev.preventDefault();
    var p = relPos(ev);
    zoomAt(p[0], p[1], ev.deltaY < 0 ? 1.12 : 1 / 1.12);
  }, { passive: false });

  /* ---- controlli ---- */
  var fitBtn = document.getElementById('btn-fit');
  var frzBtn = document.getElementById('btn-freeze');
  if (fitBtn) fitBtn.addEventListener('click', fit);
  if (frzBtn) frzBtn.addEventListener('click', function () {
    frozen = !frozen;
    frzBtn.textContent = frozen ? 'Riprendi' : 'Pausa';
    if (!frozen) { running = true; alpha = Math.max(alpha, 0.2); }
  });

  var ro = new ResizeObserver(function () { resize(); });
  ro.observe(canvas.parentElement);
  window.addEventListener('resize', resize);
  if (window.matchMedia) {
    try {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', refreshColors);
    } catch (e) { /* Safari vecchi */ }
  }

  load();
})();
