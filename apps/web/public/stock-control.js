document.querySelectorAll('form').forEach(form => {
  const select = form.querySelector('[data-stock-batch]');
  const revision = form.querySelector('[data-stock-revision]');
  if (!select || !revision) return;
  const update = () => { revision.value = select.selectedOptions[0]?.dataset.revision ?? ''; };
  select.addEventListener('change', update);
  update();
});
