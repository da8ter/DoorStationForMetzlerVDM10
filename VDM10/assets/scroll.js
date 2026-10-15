/* Scrollen wie in den SymDo-Kacheln (Vorlage: bindScrollContainment in SymDoShoppingList).
   html-sdk.js der Visu lauscht an window auf wheel und touchmove und scrollt sonst die ganze
   Visualisierung mit. Deshalb: in Scrollbereichen die Weitergabe immer stoppen und an den
   Rändern das Durchreichen verhindern; außerhalb jede Scroll-Geste abfangen (Capture-Phase). */
(function () {
  'use strict';
  // Übernommen aus dem Musikplayer für Apple Music; Scrollbereiche der VDM10-Kachel
  const SCROLL_AREAS = '.scroll';

  // Ein Kind, das selbst scrollen kann und in dieser Richtung noch Weg hat, bedient sich selbst.
  function innerScroller(target, scrollEl, deltaY) {
    let el = target instanceof Element ? target : null;
    while (el && el !== scrollEl) {
      let own = el.tagName === 'TEXTAREA';
      if (!own) { try { own = /(auto|scroll)/.test(getComputedStyle(el).overflowY); } catch (e) { own = false; } }
      if (own) {
        const max = el.scrollHeight - el.clientHeight;
        if (max > 1 && !((deltaY < 0 && el.scrollTop <= 0) || (deltaY > 0 && el.scrollTop >= max - 1))) return el;
      }
      el = el.parentElement;
    }
    return null;
  }

  // Am Rand nicht weiterreichen; passt alles hinein, gar nicht scrollen.
  function contain(scrollEl, deltaY, e) {
    const max = Math.max(0, scrollEl.scrollHeight - scrollEl.clientHeight);
    if (max <= 0) { e.preventDefault(); return; }
    if ((deltaY < 0 && scrollEl.scrollTop <= 0) || (deltaY > 0 && scrollEl.scrollTop >= max - 1)) e.preventDefault();
  }

  // Spalten der VDM10-Kachel scrollen nur im breiten Layout selbst; sonst Geste durchreichen.
  const active = el => { try { return /(auto|scroll)/.test(getComputedStyle(el).overflowY); } catch (e) { return true; } };

  function bindScrollContainment(scrollEl) {
    let lastY = null, startX = 0, startY = 0, axis = null;

    scrollEl.addEventListener('wheel', e => {
      if (!active(scrollEl)) return;
      // Eine Magic Mouse liefert nie reines deltaY: Weitergabe auch bei waagerechtem Rad stoppen.
      if (Math.abs(e.deltaX) > Math.abs(e.deltaY) || !e.deltaY) { e.stopPropagation(); return; }
      if (!innerScroller(e.target, scrollEl, e.deltaY)) contain(scrollEl, e.deltaY, e);
      e.stopPropagation();
    }, { passive: false });

    scrollEl.addEventListener('touchstart', e => {
      if (!active(scrollEl)) { lastY = null; return; }
      if (!e.touches || e.touches.length !== 1) { lastY = null; return; }
      startX = e.touches[0].clientX;
      startY = lastY = e.touches[0].clientY;
      axis = null;
      e.stopPropagation();
    }, { passive: true });

    scrollEl.addEventListener('touchmove', e => {
      if (!e.touches || e.touches.length !== 1 || !active(scrollEl)) return;
      const x = e.touches[0].clientX, y = e.touches[0].clientY;
      if (axis === null) {
        const dx = Math.abs(x - startX), dy = Math.abs(y - startY);
        // Bis die Achse feststeht nur die Weitergabe stoppen: ein preventDefault im ersten
        // touchmove nimmt der Geste auf iOS für immer das Schieben.
        if (dx <= 8 && dy <= 8) { lastY = y; e.stopPropagation(); return; }
        axis = dx > dy ? 'x' : 'y';
      }
      if (axis === 'x' || lastY === null) { lastY = y; e.stopPropagation(); return; }
      const deltaY = lastY - y;
      if (!innerScroller(e.target, scrollEl, deltaY)) contain(scrollEl, deltaY, e);
      e.stopPropagation();
      lastY = y;
    }, { passive: false });

    const end = () => { lastY = null; axis = null; };
    scrollEl.addEventListener('touchend', end, { passive: true });
    scrollEl.addEventListener('touchcancel', end, { passive: true });
  }

  document.querySelectorAll(SCROLL_AREAS).forEach(bindScrollContainment);
  window.VDMScroll = { bind: bindScrollContainment };

  // Außerhalb der Scrollbereiche: nichts scrollen und die Visu nicht mitnehmen.
  // Schieberegler brauchen ihr touchmove: dort nur die Weitergabe stoppen.
  const outside = target => !(target instanceof Element) || target.closest(SCROLL_AREAS) === null;
  const isSlider = target => target instanceof Element && target.closest('input[type=range]') !== null;
  ['wheel', 'touchmove'].forEach(type => document.addEventListener(type, e => {
    if (!outside(e.target)) return;
    if (!isSlider(e.target)) e.preventDefault();
    e.stopPropagation();
  }, { passive: false, capture: true }));

  // Tatsächliche Balkenbreite messen (je nach Umgebung 6–15 px), damit er mittig im Randstreifen sitzt.
  try {
    const probe = document.createElement('div');
    probe.className = 'scroll';
    probe.style.cssText = 'position:absolute;top:-9999px;left:-9999px;display:block;width:100px;height:100px;padding:0;flex:none';
    probe.innerHTML = '<div style="height:200px"></div>';
    document.body.appendChild(probe);
    const width = probe.offsetWidth - probe.clientWidth;
    probe.remove();
    if (Number.isFinite(width) && width >= 0) document.documentElement.style.setProperty('--sbw', width + 'px');
  } catch (e) { /* Vorgabe --sbw greift */ }
})();
