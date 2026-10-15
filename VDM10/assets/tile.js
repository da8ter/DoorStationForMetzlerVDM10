/* VDM10-Kachel – Steuerzentrale der Türstation. Zustand kommt als {type:'state'} über handleMessage
   (Startzustand eingebettet), Befehle gehen über requestAction an das Modul. Bilder laden nur über
   die Hook-Adressen aus dem Zustand. In einer IIFE, weil die Visu translate/requestAction/openObject
   global einspeist. */
(function () {
  'use strict';
  const cfg = window.VDM_CONFIG || {};
  const T = (key, ...args) => {
    let s = (cfg.texts && cfg.texts[key]) || key;
    args.forEach(v => { s = s.replace(/%d|%s/, v); });
    return s;
  };
  const I = (name, cls) => (window.VDMIcons ? window.VDMIcons.svg(name, cls) : '');
  const $ = id => document.getElementById(id);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const HOLD_MS = 1000;

  let state = null;
  let offset = 0;          // Serverzeit - Browserzeit (s)
  let photoKey = null;     // Bild nur neu setzen, wenn sich die Adresse ändert
  let holding = null;      // laufender Halte-Vorgang {door, timer}
  const busy = {};         // Tür -> Zeitpunkt bis „Wird geöffnet…“

  function action(ident, value) {
    try { requestAction(ident, value); } catch (e) { /* außerhalb von Symcon */ }
  }

  // Ränder der Visu (Kachelname, Vollbild-Symbol) aus der iframe-Adresse
  const params = new URLSearchParams(location.search);
  ['margintop', 'marginside', 'marginbottom'].forEach(name => {
    const v = parseInt(params.get(name), 10);
    if (!isNaN(v)) document.documentElement.style.setProperty('--' + name, v + 'px');
  });

  function layout() {
    const w = window.innerWidth, h = window.innerHeight;
    document.documentElement.classList.toggle('compact', h <= 330 || (w <= 300 && h <= 480));
    document.documentElement.classList.toggle('wide', w >= 720 && h > 330);
  }

  const now = () => Date.now() / 1000 + offset;
  function ago(ts, short) {
    if (!ts) return T('Never');
    const d = Math.max(0, now() - ts);
    if (d < 60) return T('just now');
    if (d < 3600) return T('%d min ago', Math.floor(d / 60));
    if (d < 6 * 3600) return T('%d h ago', Math.floor(d / 3600));
    const date = new Date(ts * 1000), today = new Date((now()) * 1000);
    const time = date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    if (date.toDateString() === today.toDateString()) return time;
    const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
    if (date.toDateString() === yesterday.toDateString()) return T('Yesterday') + (short ? '' : ' ' + time);
    const day = date.toLocaleDateString(undefined, { day: '2-digit', month: '2-digit' });
    return short ? day : day + ' ' + time;
  }

  const METHOD = { finger: ['finger', 'Fingerprint'], card: ['card', 'Card'], pin: ['pin', 'PIN'], face: ['face', 'Face'] };
  const KIND = {
    ring: ['bell', 'orange'], unlock: ['doorOpen', 'accent'], hold_on: ['lockOpen', 'orange'], hold_off: ['lock', ''],
    access: ['access', 'green'], access_denied: ['denied', 'red'], motion: ['motion', ''], tamper: ['tamper', 'red'],
    door_open: ['doorOpen', ''], door_closed: ['door', ''], door_alarm: ['alarm', 'red'], online: ['online', 'green'],
    offline: ['offline', 'red'], sip_on: ['sipOn', 'green'], sip_off: ['sipOff', 'red'], sm_online: ['smOn', 'green'], sm_offline: ['smOff', 'red'],
  };

  function renderHead() {
    $('name').textContent = state.name || '';
    $('sub').textContent = T('Last ring') + ': ' + ago(state.lastRing);
    const sip = state.sip === 'connect' ? 'ok' : (state.sip === 'unregistered' ? 'bad' : '');
    $('chips').innerHTML =
      '<span class="chip ' + (state.online ? 'ok' : 'bad') + '">' + esc(T(state.online ? 'Online' : 'Offline')) + '</span>' +
      '<span class="chip ' + sip + '">' + esc(T('SIP')) + '</span>' +
      '<span class="chip ' + (state.stream ? 'ok' : 'bad') + '">' + esc(T('Stream')) + '</span>';
  }

  function holdButton(door) {
    const opening = (busy[door] || 0) > Date.now();
    return '<button class="hold' + (opening ? ' busy' : '') + '" data-door="' + door + '">' + I('unlock') +
      '<span class="hold-label">' + esc(T(opening ? 'Opening…' : 'Open door')) + '</span><span class="hold-no">' + esc(T('Door %d', door)) + '</span>' +
      (opening ? '' : '<small>' + esc(T('Hold to open')) + '</small>') + '</button>';
  }

  function renderBanner() {
    const el = $('ringBanner');
    el.hidden = !(state.ringing || state.call === 1);
    if (el.hidden) { el.innerHTML = ''; return; }
    el.innerHTML = '<div class="ring-title">' + I('ring') + '<span>' + esc(T('Someone is ringing')) + '</span></div>' +
      '<div class="ring-actions"><button class="btn danger" data-reject="1">' + I('reject') + esc(T('Reject')) + '</button>' + holdButton(1) + '</div>';
  }

  function renderPhoto() {
    const snap = state.snapshot;
    const key = (snap ? snap.url : '') + '|' + state.live;
    const el = $('photo');
    if (key !== photoKey) {
      photoKey = key;
      const tools = '<div class="photo-tools">' +
        (state.live ? '<button class="glass-btn" data-live="1" title="' + esc(T('Live view')) + '">' + I('video') + '</button>' : '') +
        '<button class="glass-btn" data-snap="1" title="' + esc(T('New picture')) + '">' + I('camera') + '</button></div>';
      el.innerHTML = (snap && snap.url ? '<img src="' + esc(snap.url) + '" alt="" data-full="' + esc(snap.url) + '">' :
        '<div class="photo-empty">' + I('camera') + '<span>' + esc(T('No picture yet')) + '</span></div>') +
        '<div class="photo-meta" id="photoMeta"></div>' + tools;
    }
    const meta = $('photoMeta');
    if (meta) meta.textContent = snap ? ago(snap.ts) : '';
  }

  function renderDoors() {
    if (holding) return; // laufenden Halte-Vorgang nicht unterbrechen
    const multi = state.doors.length > 1;
    $('doors').classList.toggle('multi', multi);
    $('doors').innerHTML = state.doors.map(d => {
      const contact = d.contact === null || d.contact === undefined ? '' :
        '<span class="chip ' + (d.alarm ? 'bad' : (d.contact ? '' : 'ok')) + '">' + esc(T(d.alarm ? 'Alarm' : (d.contact ? 'Open' : 'Closed'))) + '</span>';
      return '<div class="door">' +
        (multi || contact ? '<div class="door-head"><span>' + esc(multi ? T('Door %d', d.no) : '') + '</span>' + contact + '</div>' : '') +
        holdButton(d.no) +
        '<div class="switch-row"><span>' + esc(T('Permanently open')) + '</span><button class="switch' + (d.hold ? ' on' : '') +
        '" data-hold="' + d.no + '" aria-pressed="' + d.hold + '"></button></div></div>';
    }).join('');
  }

  function renderToggles() {
    const tog = (ident, on, icon, offIcon, label) => '<button class="tog' + (on ? ' on' : '') + '" data-toggle="' + ident + '" data-on="' + on + '">' +
      I(on ? icon : offIcon) + esc(T(label)) + '</button>';
    let html = tog('RING_ENABLED', state.ring, 'speaker', 'speakerOff', 'Ringtone') +
      tog('MOTION_DETECTION', state.motionDet, 'motion', 'motion', 'Motion detection');
    if (state.sm !== null && state.sm !== undefined) {
      html += '<span class="tog' + (state.sm ? ' on' : '') + '">' + I(state.sm ? 'smOn' : 'smOff') + esc(T('Security module')) + '</span>';
    }
    $('toggles').innerHTML = html;
  }

  function renderAccess() {
    const list = state.access || [];
    $('access').innerHTML = '<h3>' + I('access') + esc(T('Recent access')) + '</h3>' + (list.length === 0 ?
      '<div class="empty">' + esc(T('No access yet')) + '</div>' :
      '<div class="people scroll-x">' + list.map(p => {
        const m = METHOD[p.method];
        const initials = (p.name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
        const face = p.photo ? '<img src="' + esc(p.photo) + '" alt="">' : (p.denied ? I('denied') : (initials ? esc(initials) : I('user')));
        return '<div class="person' + (p.denied ? ' denied' : '') + '"><div class="avatar">' + face + '</div>' +
          '<b>' + esc(p.denied ? T('Unknown') : (p.name || T('Unknown'))) + '</b><small>' + (m ? I(m[0]) : '') + esc(ago(p.ts, true)) + '</small></div>';
      }).join('') + '</div>');
  }

  function renderLog() {
    const list = state.log || [];
    $('log').innerHTML = '<h3>' + I('events') + esc(T('Events')) + '</h3>' + (list.length === 0 ?
      '<div class="empty">' + esc(T('No events yet')) + '</div>' : list.map(e => {
        const k = KIND[e.kind] || ['events', ''];
        const icon = e.kind === 'access' && METHOD[e.method] ? METHOD[e.method][0] : k[0];
        const sub = [e.name, METHOD[e.method] ? T(METHOD[e.method][1]) : ''].filter(Boolean).join(' · ');
        return '<div class="row"><span class="ico ' + k[1] + '">' + I(icon) + '</span>' +
          '<span class="txt"><b>' + esc(e.text) + '</b>' + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span>' +
          (e.thumb ? '<button class="thumb" data-full="' + esc(e.full) + '" style="background-image:url(\'' + esc(e.thumb) + '\')"></button>' : '') +
          '<span class="time">' + esc(ago(e.ts)) + '</span></div>';
      }).join(''));
  }

  function render() {
    if (!state) return;
    renderHead();
    renderBanner();
    renderPhoto();
    renderDoors();
    renderToggles();
    renderAccess();
    renderLog();
    $('lastRing').textContent = T('Last ring') + ': ' + ago(state.lastRing);
  }

  function notice(text) {
    const el = $('notice');
    el.textContent = text;
    el.hidden = false;
    clearTimeout(notice.timer);
    notice.timer = setTimeout(() => { el.hidden = true; }, 4000);
  }

  // Tür öffnen nur nach 1 s Halten: kein versehentliches Öffnen beim Scrollen oder Antippen
  function startHold(btn) {
    const door = parseInt(btn.dataset.door, 10);
    if (!door || (busy[door] || 0) > Date.now()) return;
    btn.classList.add('holding');
    holding = { door, btn, timer: setTimeout(() => {
      holding = null;
      busy[door] = Date.now() + 3000;
      action('DOOR' + door, true);
      setTimeout(render, 3100);
      render();
    }, HOLD_MS) };
  }
  function cancelHold() {
    if (!holding) return;
    clearTimeout(holding.timer);
    holding.btn.classList.remove('holding');
    holding = null;
    notice(T('Hold to open'));
    render();
  }
  document.addEventListener('pointerdown', e => {
    const btn = e.target.closest('.hold');
    if (btn) { e.preventDefault(); startHold(btn); }
  });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(type => document.addEventListener(type, e => {
    if (holding && (type !== 'pointerleave' || e.target === holding.btn)) cancelHold();
  }, true));
  document.addEventListener('contextmenu', e => { if (e.target.closest('.hold')) e.preventDefault(); });

  document.addEventListener('click', e => {
    const t = e.target.closest('button, img[data-full]');
    if (!t || !state) return;
    if (t.dataset.reject) action('CALL_CONTROL', 2);
    else if (t.dataset.live && state.live) { try { openObject(state.live); } catch (err) { /* außerhalb von Symcon */ } }
    else if (t.dataset.snap) { action('TileSnapshot', true); notice(T('New picture') + ' …'); }
    else if (t.dataset.hold) {
      const door = state.doors.find(d => String(d.no) === t.dataset.hold);
      if (door) { door.hold = !door.hold; action('DOOR' + door.no + '_HOLD', door.hold); renderDoors(); }
    } else if (t.dataset.toggle) {
      const on = t.dataset.on !== 'true';
      if (t.dataset.toggle === 'RING_ENABLED') state.ring = on; else state.motionDet = on;
      action(t.dataset.toggle, on);
      renderToggles();
    } else if (t.dataset.full) {
      $('overlayImg').src = t.dataset.full;
      $('overlay').hidden = false;
    } else if (t.id === 'overlayClose') {
      $('overlay').hidden = true;
    }
  });
  $('overlay').addEventListener('click', e => { if (e.target.id === 'overlay') $('overlay').hidden = true; });
  $('overlayClose').innerHTML = I('close');

  function receive(data) {
    let msg;
    try { msg = typeof data === 'string' ? JSON.parse(data) : data; } catch (e) { return; }
    if (!msg || msg.type !== 'state') return;
    state = msg;
    if (typeof msg.now === 'number') offset = msg.now - Date.now() / 1000;
    render();
  }
  window.handleMessage = receive;
  window.addEventListener('message', ev => {
    if (ev.source !== window && ev.source !== window.parent) return;
    receive(ev.data);
  });
  window.addEventListener('resize', layout);

  layout();
  receive(cfg.state);
  setInterval(render, 30000); // relative Zeiten nachführen (keine Animation)
})();
