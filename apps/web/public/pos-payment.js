/* POS payment form: show only the fields that apply to the chosen method.
   Hidden fields are still submitted (empty), exactly as before; without JavaScript every field stays visible. */
(function () {
  'use strict';
  var form = document.querySelector('form[action$="/pay"]');
  var method = form && form.querySelector('[name="payment_method"]');
  if (!method) { return; }
  function holder(name) {
    var input = form.querySelector('[name="' + name + '"]');
    return input ? input.closest('label') : null;
  }
  function sync() {
    var cash = method.value === 'cash';
    [['cash_received', cash], ['payment_reference', !cash], ['verified_amount', !cash], ['verified', !cash]].forEach(function (rule) {
      var el = holder(rule[0]);
      if (el) { el.hidden = !rule[1]; }
    });
  }
  method.addEventListener('change', sync);
  sync();
})();
