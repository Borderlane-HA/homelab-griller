/* HomeLab Griller v2 — progressive enhancement, no dependencies */
(() => {
  'use strict';
  const byId = id => document.getElementById(id);
  const el = (tag, cls, value) => { const node = document.createElement(tag); if (cls) node.className = cls; if (value != null) node.textContent = value; return node; };
  const form = byId('guest-order-form');
  if (form) {
    const wiz = byId('order-wizard');
    const panels = [...wiz.querySelectorAll('.wizard-panel')];
    const next = byId('wizard-next'), back = byId('wizard-back'), submit = byId('wizard-submit');
    const countLabel = byId('cart-counter').textContent.replace(/^\d+\s*/, '') || 'items';
    const draftKey = 'griller-v2-draft:' + location.pathname + location.search.split('&edit=')[0] + ':' + (wiz.dataset.edit || '0');
    let step = 1;
    let activeCategory = '';
    const inputs = () => [...form.querySelectorAll('.menu-product')];
    const quantity = card => card.querySelector('.qty-input');
    const selected = () => inputs().filter(card => Number(quantity(card).value) > 0);
    const count = () => selected().reduce((n, card) => n + Number(quantity(card).value), 0);
    const updateCount = () => { byId('cart-counter').textContent = String(count()) + ' ' + countLabel; };
    const saveDraft = () => {
      const values = [...form.elements].filter(x => x.name && !['hidden','submit'].includes(x.type)).map(x => ({name:x.name, value:x.value, checked:x.checked, type:x.type}));
      try { sessionStorage.setItem(draftKey, JSON.stringify(values)); } catch (_) {}
    };
    try {
      if (!wiz.dataset.edit || wiz.dataset.edit === '0') {
        const saved = JSON.parse(sessionStorage.getItem(draftKey) || 'null');
        if (saved) for (const v of saved) {
          const matches = [...form.elements].filter(x => x.name === v.name && x.value === v.value && x.type === v.type);
          if (v.type === 'checkbox' || v.type === 'radio') matches.forEach(x => x.checked = v.checked);
          else [...form.elements].filter(x => x.name === v.name && x.type === v.type).forEach(x => x.value = v.value);
        }
      }
    } catch (_) {}
    const review = () => {
      const target = byId('order-review'); target.replaceChildren();
      if (!selected().length) { target.appendChild(el('p', 'muted', wiz.dataset.emptyLabel || 'Please add at least one item.')); return; }
      selected().forEach(card => {
        const row = el('div', 'review-line');
        row.appendChild(el('strong', '', quantity(card).value + '× ' + card.dataset.product));
        const options = [...card.querySelectorAll('input[type=checkbox]:checked')].map(x => x.value);
        const doneness = card.querySelector('select')?.selectedOptions[0]?.textContent;
        if (doneness && doneness !== '—') options.push(doneness);
        const note = card.querySelector('input[name$="[note]"]')?.value;
        if (note) options.push(note);
        row.appendChild(el('small', 'muted', options.join(' · ')));
        target.appendChild(row);
      });
    };
    const show = n => {
      step = n;
      panels.forEach(p => p.hidden = Number(p.dataset.step) !== step);
      byId('step-counter').textContent = step + ' / 3';
      byId('wizard-progress').style.width = ((step / 3) * 100) + '%';
      back.hidden = step === 1;
      next.hidden = step === 3;
      submit.hidden = step !== 3;
      if (step === 2) {
        inputs().forEach(card => {card.hidden = Boolean(activeCategory && card.dataset.category !== activeCategory);});
      }
      if (step === 3) review();
      if (step > 1) wiz.scrollIntoView({behavior:'smooth',block:'start'});
    };
    wiz.querySelectorAll('[data-category]').forEach(button => button.addEventListener('click', () => { activeCategory = button.dataset.category; show(2); }));
    byId('choose-other-category').addEventListener('click', () => {activeCategory = ''; show(2);});
    next.addEventListener('click', () => {
      if (step === 1) { activeCategory = ''; show(2); }
      else if (step === 2) { if (!count()) { byId('cart-counter').classList.add('attention');setTimeout(() => byId('cart-counter').classList.remove('attention'),1100); return; } show(3); }
    });
    back.addEventListener('click', () => show(Math.max(1, step - 1)));
    form.querySelectorAll('[data-delta]').forEach(button => button.addEventListener('click', () => {
      const input = button.parentNode.querySelector('.qty-input');
      input.value = Math.min(20, Math.max(0, Number(input.value || 0) + Number(button.dataset.delta)));
      input.dispatchEvent(new Event('input',{bubbles:true}));
    }));
    form.addEventListener('input', () => {updateCount();saveDraft();});
    form.addEventListener('change', () => {updateCount();saveDraft();});
    form.addEventListener('submit', e => { if (!count()) {e.preventDefault();show(2);return;} sessionStorage.removeItem(draftKey); });
    updateCount();show(wiz.dataset.edit !== '0' ? 2 : 1);
  }
  const status = byId('guest-order-status');
  if (status) {
    let seen = [...status.querySelectorAll('[data-order-status="done"]')].map(x => x.textContent);
    const refresh = async () => {
      if (document.hidden || status.contains(document.activeElement)) return;
      try {
        const resp = await fetch(status.dataset.pollUrl, {cache:'no-store',credentials:'same-origin'});
        if (!resp.ok) return;
        const html = await resp.text();
        const fragment = new DOMParser().parseFromString(html,'text/html');
        const ready = [...fragment.querySelectorAll('[data-order-status="done"]')].map(x => x.textContent);
        if (ready.some(v => !seen.includes(v))) {
          showToast(document.documentElement.lang === 'de' ? 'Dein Essen ist fertig!' : (document.documentElement.lang === 'es' ? '¡Tu comida está lista!' : 'Your food is ready!'));
          if ('vibrate' in navigator) navigator.vibrate([100,50,100]);
        }
        seen = ready; status.innerHTML = html;
      } catch (_) { /* quiet retry */ }
    };
    setInterval(refresh, 7000);
  }
  function showToast(message) {
    const node = el('div','ready-toast','✓  ' + message);document.body.appendChild(node);
    setTimeout(() => node.remove(),6000);
  }
  const kitchen = byId('kitchen-live-board');
  const search = byId('kitchen-search');
  if (kitchen) {
    const applySearch = () => {
      const q = (search?.value || '').toLowerCase().trim();
      kitchen.querySelectorAll('.ticket').forEach(ticket => { ticket.hidden = !!q && !(ticket.dataset.search || '').includes(q); });
    };
    if (search) search.addEventListener('input',applySearch);
    setInterval(async () => {
      if (document.hidden || kitchen.matches(':hover') || kitchen.contains(document.activeElement)) return;
      try {
        const res = await fetch(kitchen.dataset.pollUrl,{cache:'no-store',credentials:'same-origin'});
        if (res.ok) {const html=await res.text();if(html!==kitchen.innerHTML){kitchen.innerHTML=html;applySearch();}}
      } catch (_) {}
    },5000);
    const fs = byId('kitchen-fullscreen');
    if (fs) fs.addEventListener('click', () => { if (!document.fullscreenElement) document.documentElement.requestFullscreen?.(); else document.exitFullscreen?.(); });
  }
})();
