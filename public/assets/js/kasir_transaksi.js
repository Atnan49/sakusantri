'use strict';
// Kasir transaksi page logic: format nominal ke Rupiah & quick amount chips
(function(){
  const hidden = document.getElementById('nominal');
  const disp   = document.getElementById('nominalDisplay');
  if(!hidden || !disp) return; // Nothing to do

  function onlyDigits(str){ return (str||'').replace(/[^0-9]/g,''); }
  function formatIDR(n){
    try{ return 'Rp '+Number(n||0).toLocaleString('id-ID'); }
    catch(e){ return 'Rp '+(n||0); }
  }
  function sync(val){
    const v = parseInt(val||0,10) || 0;
    hidden.value = String(v);
    disp.value = formatIDR(v);
  }
  // On manual typing
  disp.addEventListener('input', () => {
    const raw = onlyDigits(disp.value);
    sync(raw);
  });
  disp.addEventListener('focus', () => {
    // Select all for quick replacement
    setTimeout(()=>{ try{ disp.select(); }catch(e){} }, 0);
  });
  // Initialize
  sync(onlyDigits(disp.value));

  // Quick amount chips
  document.querySelectorAll('.qa-chip').forEach(ch => {
    ch.addEventListener('click', () => {
      const v = parseInt(ch.getAttribute('data-val'),10) || 0;
      sync(v);
      // Optional visual active state
      document.querySelectorAll('.qa-chip').forEach(c=>c.classList.remove('active'));
      ch.classList.add('active');
      enableSubmitIfValid();
    });
  });

  // Disable submit until a value entered (safety agar kasir wajib masukkan nominal baru setiap transaksi)
  const form = document.querySelector('.js-kasir-form');
  if(form){
    const submitBtn = form.querySelector('button[type=submit]');
    function enableSubmitIfValid(){
      if(!submitBtn) return;
      const val = parseInt(hidden.value||'0',10)||0;
      submitBtn.disabled = val <= 0;
    }
    // initial state
    enableSubmitIfValid();
    disp.addEventListener('input', enableSubmitIfValid);
    // Hook custom event from page inline script after success
    window.addEventListener('kasir:trx:success', () => {
      // Clear value & deactivate chips so kasir pasti input ulang
      sync(0);
      document.querySelectorAll('.qa-chip').forEach(c=>c.classList.remove('active'));
      enableSubmitIfValid();
      // Refocus untuk cepat input berikutnya
      setTimeout(()=>{ try{ disp.focus(); }catch(e){} }, 30);
    });
  }
})();
