(() => {
  const rows = document.querySelector('#order-lines');
  const add = document.querySelector('#add-order-line');
  if (!rows || !add) return;
  let next = rows.children.length;
  add.addEventListener('click', () => {
    if (rows.children.length >= 100) return;
    const copy = rows.firstElementChild.cloneNode(true);
    copy.querySelector('legend').textContent = `Item ${next + 1}`;
    copy.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/lines\[\d+\]/, `lines[${next}]`); if (input.tagName === 'INPUT') input.value = '1'; });
    rows.append(copy); next++;
  });
  rows.addEventListener('click', event => {
    const button = event.target.closest('[data-remove-row]');
    if (button && rows.children.length > 1) button.closest('.order-row').remove();
  });
})();
