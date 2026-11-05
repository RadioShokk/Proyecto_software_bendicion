'use strict';
(() => {
  const $ = (s, context = document) => context.querySelector(s);
  const $$ = (s, context = document) => Array.from(context.querySelectorAll(s));
  const fmt = value => 'C$ ' + (Number.isFinite(value) ? value : 0).toLocaleString('en-US', {minimumFractionDigits: 2,maximumFractionDigits: 2});
  const menu = $('#menu-toggle');
  if (menu) {
    menu.addEventListener('click', () => {
      const nav = $('#sidebar'); const opened = nav.classList.toggle('open');
      menu.setAttribute('aria-expanded', String(opened));
    });
  }
  $$('[data-table-search]').forEach(field => field.addEventListener('input', () => {
    const value = field.value.trim().toLocaleLowerCase('es');
    $$(`#${field.dataset.tableSearch} tbody tr`).forEach(row => {
      row.hidden = !row.textContent.toLocaleLowerCase('es').includes(value);
    });
  }));
  $$('button[data-confirm]').forEach(button => button.addEventListener('click', e => {
    if (!confirm(button.dataset.confirm)) e.preventDefault();
  }));
  const printButton = $('#print-receipt');
  if (printButton) printButton.addEventListener('click', () => window.print());

  const form = $('form[data-kind]');
  if (form) {
    const area = $('#line-items');
    const template = $('#item-template');
    const kind = form.dataset.kind;
    const add = () => {
      const item = template.content.firstElementChild.cloneNode(true);
      $('.remove-line', item).addEventListener('click', () => {
        if (area.children.length <= 1) return;
        item.remove(); recalculate();
      });
      item.addEventListener('input', recalculate);
      item.addEventListener('change', recalculate);
      area.appendChild(item);
      recalculate();
      return item;
    };
    const recalculate = () => {
      let totalCents = 0;
      $$('.line-item', area).forEach(item => {
        const select = $('.line-product', item);
        const opt = select.selectedOptions[0];
        const qtyField = $('.line-qty', item);
        const qty = Math.max(0, Math.floor(Number(qtyField.value) || 0));
        const unit = kind === 'sale' ? Number(opt?.dataset.price || 0) : Number($('.unit-cost', item).value || 0);
        const cents = Math.round(unit * 100) * qty;
        totalCents += cents;
        if (kind === 'sale') {
          $('.line-total', item).textContent = fmt(cents / 100);
          const available = Number(opt?.dataset.stock || 0);
          qtyField.max = available || '';
          const rx = opt?.dataset.rx === '1';
          const info = rx ? 'Requiere prescripción. Verifique el procedimiento de dispensación.' : (available ? `Disponibles: ${available}` : '');
          $('.line-hint', item).textContent = info;
          $('.line-hint', item).classList.toggle('rx', rx);
        }
      });
      if (kind === 'sale') {
        $('#sale-subtotal').textContent = fmt(totalCents / 100);
        $('#sale-total').textContent = fmt(totalCents / 100);
        const received = Math.round(Number($('#cash-received')?.value || 0) * 100);
        $('#sale-change').textContent = fmt(Math.max(0, received - totalCents) / 100);
      } else {
        $('#purchase-total').textContent = fmt(totalCents / 100);
      }
    };
    $('[data-add-line]').addEventListener('click', () => {
      if (area.children.length < 40) { const row = add(); $('.line-product',row).focus(); }
    });
    if (kind === 'sale') $('#cash-received').addEventListener('input', recalculate);
    add();
    form.addEventListener('submit', e => {
      const selected = $$('.line-product',area).map(el => el.value).filter(Boolean);
      if (!selected.length) { e.preventDefault(); alert('Seleccione al menos un producto.');return; }
      if (kind === 'sale') {
        const stocks = new Map();
        for (const line of $$('.line-item',area)) {
          const product = $('.line-product',line); const opt = product.selectedOptions[0];
          const qty = Number($('.line-qty',line).value);
          stocks.set(product.value, (stocks.get(product.value) || 0) + qty);
          if (stocks.get(product.value) > Number(opt?.dataset.stock || 0)) {
            e.preventDefault(); alert(`Existencias insuficientes para: ${opt?.textContent || 'producto'}.`); return;
          }
        }
      }
    });
  }
  const type = $('#movement-type');
  if (type) {
    const product = $('[name="product_id"]');const lot = $('#adjust-lot');
    const toggle = () => {
      const exit = type.value === 'salida';
      $$('.exit-only').forEach(x => {x.hidden = !exit;});
      $$('.entry-only').forEach(x => {x.hidden = exit; x.querySelectorAll('input').forEach(i => {i.disabled=exit;});});
      lot.required = exit; lot.disabled = !exit;
      $$('option[data-product-id]',lot).forEach(option => {option.hidden = option.dataset.productId !== product.value; option.disabled=option.hidden;});
      if (lot.selectedOptions[0]?.disabled) lot.value = '';
    };
    type.addEventListener('change',toggle);product.addEventListener('change',toggle);toggle();
  }
})();
