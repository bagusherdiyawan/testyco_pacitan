(() => {
  const container = document.getElementById('denom-calculator');
  if (!container) return;
  const countedInput = document.querySelector('input[name="counted_cash"]');
  const retainedInput = document.querySelector('input[name="retained_cash"]');
  const depositPreview = document.getElementById('deposit-preview');

  const updateDeposit = () => {
    if (!countedInput || !depositPreview) return;
    const counted = parseInt(countedInput.value || '0', 10) || 0;
    const retained = retainedInput && retainedInput.value !== ''
      ? (parseInt(retainedInput.value, 10) || 0)
      : counted;
    const deposit = Math.max(0, counted - retained);
    depositPreview.textContent = 'Rp ' + deposit.toLocaleString('id-ID');
  };

  container.addEventListener('input', () => {
    let total = 0;
    container.querySelectorAll('[data-denom]').forEach((el) => {
      const denom = parseInt(el.dataset.denom || '0', 10);
      const qty = Math.max(0, parseInt(el.value || '0', 10) || 0);
      total += denom * qty;
    });
    if (countedInput) {
      countedInput.value = String(total);
    }
    updateDeposit();
  });

  if (countedInput) {
    countedInput.addEventListener('input', updateDeposit);
  }
  if (retainedInput) {
    retainedInput.addEventListener('input', updateDeposit);
  }
  updateDeposit();
})();
