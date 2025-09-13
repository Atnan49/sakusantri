document.addEventListener('DOMContentLoaded', function() {
  var buktiInput = document.getElementById('buktiInput');
  var btnBayar = document.getElementById('btnBayarSpp');
  if (buktiInput && btnBayar) {
    buktiInput.addEventListener('change', function() {
      btnBayar.disabled = !buktiInput.files.length;
    });
  }
  // Copy buttons for bank and amount
  document.querySelectorAll('.copy-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var id = btn.getAttribute('data-target');
      var el = document.getElementById(id);
      if(!el) return;
      var text = el.innerText || el.textContent || '';
      if(!text) return;
      navigator.clipboard && navigator.clipboard.writeText(text).then(function(){
        btn.textContent = 'Tersalin';
        setTimeout(function(){ btn.textContent = 'Salin'; }, 1200);
      }).catch(function(){
        // Fallback
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); btn.textContent='Tersalin'; setTimeout(function(){ btn.textContent='Salin'; },1200); } catch(e){}
        document.body.removeChild(ta);
      });
    });
  });
});
