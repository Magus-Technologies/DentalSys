/* Autocompletado de catálogo para campos de tratamiento.
 * Uso:
 *   - Define window.CATALOGO = [{id, nombre, precio, cat}, ...]
 *   - (opcional) window.MON = 'S/'
 *   - Marca el input de nombre con class="ac-nombre" autocomplete="off"
 *   - En la misma fila (<tr>) debe existir el input oculto del id (type="hidden")
 *     y el input de precio con class="precio-inp".
 * Al elegir una opción se rellenan id, nombre y precio, y se dispara el evento
 * "input" sobre el precio para que se recalculen los totales.
 */
(function () {
  let box = null, input = null, items = [], idx = -1;

  // Estilos (una sola vez)
  if (!document.getElementById('acStyle')) {
    const st = document.createElement('style');
    st.id = 'acStyle';
    st.textContent = `
      .ac-box{position:absolute;z-index:4000;background:var(--bg2,#15202e);border:1px solid var(--bd2,rgba(255,255,255,.14));border-radius:8px;box-shadow:0 10px 28px rgba(0,0,0,.45);max-height:270px;overflow-y:auto;font-size:13px;min-width:240px}
      .ac-item{display:flex;justify-content:space-between;gap:12px;padding:8px 12px;cursor:pointer;color:var(--t,#e8edf2);border-bottom:1px solid var(--bd2,rgba(255,255,255,.05))}
      .ac-item:last-child{border-bottom:0}
      .ac-item.active,.ac-item:hover{background:rgba(0,212,238,.16)}
      .ac-item .ac-name{display:flex;flex-direction:column}
      .ac-item .ac-cat{font-size:10px;color:var(--t3,#7990a5)}
      .ac-item .ac-price{color:var(--c,#00d4ee);white-space:nowrap;font-weight:600}
      .ac-empty{padding:9px 12px;color:var(--t2,#9fb0c0)}
    `;
    document.head.appendChild(st);
  }

  const cat = () => (Array.isArray(window.CATALOGO) ? window.CATALOGO : []);
  const money = v => (window.MON || 'S/') + ' ' + (parseFloat(v) || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  function close() { if (box) { box.remove(); box = null; } input = null; items = []; idx = -1; }

  function ensure() {
    if (box) return;
    box = document.createElement('div');
    box.className = 'ac-box';
    document.body.appendChild(box);
    // mousedown para no perder el foco antes del click
    box.addEventListener('mousedown', e => {
      const it = e.target.closest('.ac-item');
      if (it) { e.preventDefault(); pick(parseInt(it.dataset.i, 10)); }
    });
  }

  function open(inp) {
    input = inp;
    const q = inp.value.trim().toLowerCase();
    let list = cat();
    if (q) list = list.filter(t => (t.nombre || '').toLowerCase().includes(q) || (t.cat || '').toLowerCase().includes(q));
    items = list.slice(0, 10); idx = -1;
    ensure();
    box.innerHTML = items.length
      ? items.map((t, i) => `<div class="ac-item" data-i="${i}"><span class="ac-name"><span>${esc(t.nombre)}</span>${t.cat ? `<span class="ac-cat">${esc(t.cat)}</span>` : ''}</span><span class="ac-price">${money(t.precio)}</span></div>`).join('')
      : '<div class="ac-empty">Sin coincidencias en el catálogo</div>';
    position();
  }

  function position() {
    if (!box || !input) return;
    const r = input.getBoundingClientRect();
    box.style.left = (r.left + window.scrollX) + 'px';
    box.style.top = (r.bottom + window.scrollY + 2) + 'px';
    box.style.minWidth = Math.max(r.width, 240) + 'px';
  }

  function highlight() {
    if (!box) return;
    [...box.querySelectorAll('.ac-item')].forEach((el, i) => el.classList.toggle('active', i === idx));
    const act = box.querySelector('.ac-item.active');
    if (act) act.scrollIntoView({ block: 'nearest' });
  }

  function pick(i) {
    const t = items[i]; if (!t || !input) return;
    const row = input.closest('tr') || input.closest('.ac-row') || input.parentElement;
    const hid = row && row.querySelector('input[type="hidden"]');
    if (hid) hid.value = t.id;
    input.value = t.nombre;
    const px = row && row.querySelector('.precio-inp');
    if (px) {
      px.value = (parseFloat(t.precio) || 0).toFixed(2);
      px.dispatchEvent(new Event('input', { bubbles: true })); // recalcula totales
    }
    close();
    if (px) { px.focus(); px.select && px.select(); } else if (input) { input.blur(); }
  }

  document.addEventListener('input', e => { if (e.target.matches('.ac-nombre')) open(e.target); });
  document.addEventListener('keydown', e => {
    if (!box || !input || e.target !== input) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min(idx + 1, items.length - 1); highlight(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(idx - 1, 0); highlight(); }
    else if (e.key === 'Enter') { if (idx >= 0) { e.preventDefault(); pick(idx); } }
    else if (e.key === 'Escape') { close(); }
  });
  document.addEventListener('click', e => { if (box && !box.contains(e.target) && e.target !== input) close(); });
  window.addEventListener('scroll', () => { if (box) position(); }, true);
  window.addEventListener('resize', () => { if (box) position(); });
})();
