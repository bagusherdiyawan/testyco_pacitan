(() => {
  const rows = document.querySelector('#pos-lines');
  const add = document.querySelector('#add-pos-line');
  if (!rows || !add) return;

  let next = rows.children.length;

  function filter(row, clear = false, autoSelect = false) {
    const product = row.querySelector('[data-product]');
    const unit = row.querySelector('[data-unit]');
    const stock = row.querySelector('[data-stock]');
    if (!product || !unit || !stock) return;
    const pack = product.selectedOptions[0]?.dataset.pack;
    let firstEligible = '';
    for (const option of stock.options) {
      if (!option.value) continue;
      const eligible =
        option.dataset.productId === product.value &&
        (unit.value === 'pack'
          ? option.dataset.bucket === 'usable' && option.dataset.pack === pack
          : option.dataset.bucket !== 'usable');
      option.hidden = !eligible;
      option.disabled = !eligible;
      if (eligible && !firstEligible) {
        firstEligible = option.value;
      }
    }
    if (clear || stock.selectedOptions[0]?.disabled) {
      stock.value = autoSelect ? firstEligible : '';
    }
  }

  function updateEstimate() {
    const estEl = document.getElementById('pos-live-estimate');
    if (!estEl) return;
    let total = 0;
    for (const row of rows.children) {
      const product = row.querySelector('[data-product]');
      const unit = row.querySelector('[data-unit]');
      const qtyInput = row.querySelector('input[type="number"]');
      if (!product || !unit || !qtyInput) continue;
      const opt = product.selectedOptions[0];
      const price = parseInt(opt?.dataset.price || '0', 10) || 0;
      const pack = parseInt(opt?.dataset.pack || '1', 10) || 1;
      const portion = parseInt(opt?.dataset.portion || '1', 10) || 1;
      const qty = Math.max(0, parseInt(qtyInput.value || '0', 10) || 0);
      const mult = unit.value === 'pack' ? pack : unit.value === 'portion' ? portion : 1;
      total += price * mult * qty;
    }
    estEl.textContent = 'Rp ' + total.toLocaleString('id-ID');
  }

  rows.addEventListener('change', (e) => {
    if (e.target.matches('[data-product],[data-unit]')) {
      filter(e.target.closest('.pos-row'), true, true);
    }
    updateEstimate();
  });

  rows.addEventListener('input', () => updateEstimate());

  rows.addEventListener('click', (e) => {
    const button = e.target.closest('[data-remove-pos]');
    if (button && rows.children.length > 1) {
      button.closest('.pos-row').remove();
      updateEstimate();
    }
  });

  function addRow() {
    if (rows.children.length >= 100) return null;
    const copy = rows.firstElementChild.cloneNode(true);
    copy.querySelector('legend').textContent = `Item ${next + 1}`;
    copy.querySelectorAll('[name]').forEach((el) => {
      el.name = el.name.replace(/lines\[\d+\]/, `lines[${next}]`);
      if (el.tagName === 'INPUT') el.value = '1';
    });
    rows.append(copy);
    filter(copy, true, true);
    next++;
    updateEstimate();
    return copy;
  }

  add.addEventListener('click', () => addRow());

  // Quick-click catalog cards, condition chips & search
  const quickSearch = document.getElementById('pos-quick-search');
  const quickFilters = document.getElementById('pos-quick-filters');
  const quickGrid = document.getElementById('pos-quick-grid');
  let activeCondition = 'all';

  function applyQuickFilter() {
    if (!quickGrid) return;
    const q = (quickSearch ? quickSearch.value : '').trim().toLowerCase();
    for (const card of quickGrid.querySelectorAll('[data-quick-product]')) {
      const hay = (card.dataset.search || '').toLowerCase();
      const cond = card.dataset.condition || 'frozen';
      const matchSearch = q === '' || hay.includes(q);
      const matchCond = activeCondition === 'all' || cond === activeCondition;
      card.hidden = !(matchSearch && matchCond);
    }
  }

  if (quickSearch) {
    quickSearch.addEventListener('input', applyQuickFilter);
  }
  if (quickFilters) {
    quickFilters.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-filter-cond]');
      if (!btn) return;
      activeCondition = btn.dataset.filterCond || 'all';
      quickFilters.querySelectorAll('[data-filter-cond]').forEach((el) => {
        const selected = el === btn;
        el.className = selected
          ? 'px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-700 text-white'
          : 'px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700 hover:bg-slate-200';
      });
      applyQuickFilter();
    });
  }

  if (quickGrid) {
    quickGrid.addEventListener('click', (e) => {
      const card = e.target.closest('[data-quick-product]');
      if (!card) return;
      const pid = card.dataset.quickProduct;
      const existing = Array.from(rows.children).find((r) => {
        const sel = r.querySelector('[data-product]');
        const stk = r.querySelector('[data-stock]');
        return sel && sel.value === pid && stk && stk.value !== '';
      });
      if (existing) {
        const qtyInput = existing.querySelector('input[type="number"]');
        if (qtyInput) {
          qtyInput.value = String((parseInt(qtyInput.value || '0', 10) || 0) + 1);
          updateEstimate();
        }
        return;
      }
      const firstRow = rows.firstElementChild;
      const firstStock = firstRow?.querySelector('[data-stock]');
      const targetRow =
        rows.children.length === 1 && firstStock && firstStock.value === ''
          ? firstRow
          : addRow();
      if (!targetRow) return;
      const sel = targetRow.querySelector('[data-product]');
      if (sel) {
        sel.value = pid;
        filter(targetRow, true, true);
        updateEstimate();
      }
    });
  }

  for (const row of rows.children) filter(row);
  updateEstimate();
})();
