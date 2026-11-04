const receiptButton = document.querySelector('[data-print-receipt]');
receiptButton?.addEventListener('click', () => {
  const size = document.querySelector('[data-receipt-paper]')?.value;
  const status = document.querySelector('[data-print-status]');
  if (!['58', '80'].includes(size)) { status.textContent = 'Pilih lebar kertas58 atau80 mm sebelum mencetak.'; return; }
  document.documentElement.style.setProperty('--receipt-width', `${size}mm`);
  status.textContent = 'Dialog cetak dibuka. Sistem tidak mengetahui hasil fisik; periksa printer sebelum mencetak salinan lagi.';
  window.print();
});
