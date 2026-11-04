'use strict';
const resetParams = new URLSearchParams(window.location.hash.slice(1));
for (const name of ['email', 'token']) {
  const field = document.querySelector(`form input[name="${name}"]`);
  if (field && resetParams.has(name)) field.value = resetParams.get(name);
}
if (window.location.hash) window.history.replaceState(null, '', window.location.pathname);
