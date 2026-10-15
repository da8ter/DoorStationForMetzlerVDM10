/* VDM10-Kachel – Symbole aus Font Awesome Light, das die Visualisierung unter /icons.js mitbringt
   (dort ist nur der Light-Schnitt vollständig). Übernommen aus dem Musikplayer für Apple Music:
   das SVG entsteht direkt aus den Icon-Daten statt über <i>-Ersetzung und steht sofort im DOM. */
(function () {
  'use strict';
  // Kachel-Name → Font-Awesome-Name
  const NAMES = {
    bell: 'bell', ring: 'bell-ring', unlock: 'door-open', door: 'door-closed', doorOpen: 'door-open', lockOpen: 'lock-open',
    lock: 'lock', finger: 'fingerprint', card: 'id-card', pin: 'keyboard', face: 'face-viewfinder', access: 'key',
    denied: 'ban', motion: 'person-walking', tamper: 'triangle-exclamation', alarm: 'siren-on', online: 'wifi',
    offline: 'wifi-slash', sipOn: 'phone', sipOff: 'phone-slash', smOn: 'shield-check', smOff: 'shield-xmark',
    camera: 'camera', video: 'video', close: 'xmark', speaker: 'volume', speakerOff: 'volume-slash', user: 'user',
    reject: 'phone-hangup', events: 'list-timeline', gallery: 'images', prev: 'chevron-left', next: 'chevron-right',
  };

  // [Breite, Höhe, Ligaturen, Unicode, Pfad] oder null
  function definition(name) {
    const iconName = NAMES[name] || name;
    const fa = window.FontAwesome;
    if (fa && typeof fa.findIconDefinition === 'function') {
      const found = fa.findIconDefinition({ prefix: 'fal', iconName });
      if (found && found.icon) return found.icon;
    }
    const ns = window.___FONT_AWESOME___;
    return (ns && ns.styles && ns.styles.fal && ns.styles.fal[iconName]) || null;
  }
  const pathOf = icon => (Array.isArray(icon[4]) ? icon[4].join(' ') : icon[4]);

  function svg(name, className) {
    const icon = definition(name);
    const cls = 'fa-icon' + (className ? ' ' + className : '');
    if (!icon) return '<svg class="' + cls + '" viewBox="0 0 512 512" aria-hidden="true"></svg>';
    return '<svg class="' + cls + '" viewBox="0 0 ' + icon[0] + ' ' + icon[1] + '" aria-hidden="true"><path fill="currentColor" d="' + pathOf(icon) + '"/></svg>';
  }

  // Als CSS-Bild (Maske oder Hintergrund) für Pseudoelemente, die kein SVG im DOM tragen können
  function url(name, color) {
    const icon = definition(name);
    if (!icon) return 'none';
    const markup = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + icon[0] + ' ' + icon[1] + '"><path fill="' + (color || '#000') + '" d="' + pathOf(icon) + '"/></svg>';
    return 'url("data:image/svg+xml,' + encodeURIComponent(markup) + '")';
  }

  window.VDMIcons = { svg, url };
})();
