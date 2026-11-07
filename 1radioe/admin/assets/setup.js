// Setup wizard: renders the TOTP QR code locally (the secret never leaves the browser)
// and copies the config snippet.
(() => {
  'use strict';
  const box = document.getElementById('qr');
  if (box && typeof window.qrcode === 'function') {
    const qr = window.qrcode(0, 'M');
    qr.addData(box.dataset.uri);
    qr.make();
    const img = document.createElement('img');
    img.src = qr.createDataURL(6, 12);
    img.alt = 'QR-Code für die Authenticator-App';
    img.width = img.height = (qr.getModuleCount() + 24) * 6;
    box.append(img);
  }
  document.querySelectorAll('[data-copy]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const text = document.querySelector(btn.dataset.copy).textContent;
      try {
        await navigator.clipboard.writeText(text);
        btn.textContent = 'Kopiert';
      } catch {
        btn.textContent = 'Bitte manuell markieren';
      }
    });
  });
})();
