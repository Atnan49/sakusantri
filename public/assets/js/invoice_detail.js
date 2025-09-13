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
      if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(text).then(function(){
        btn.textContent = 'Tersalin';
        setTimeout(function(){ btn.textContent = 'Salin'; }, 1200);
        }).catch(fallbackCopy);
      } else {
        fallbackCopy();
      }

      function fallbackCopy(){
        // Create a temporary element that's contenteditable for better iOS support
        var temp = document.createElement('div');
        temp.style.position = 'fixed';
        temp.style.left = '-9999px';
        temp.style.whiteSpace = 'pre';
        temp.setAttribute('contenteditable','true');
        temp.textContent = text;
        document.body.appendChild(temp);
        var range = document.createRange();
        range.selectNodeContents(temp);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        try {
          document.execCommand('copy');
          btn.textContent='Tersalin';
          setTimeout(function(){ btn.textContent='Salin'; },1200);
        } catch(e) {
          console && console.warn && console.warn('copy failed', e);
        }
        sel.removeAllRanges();
        document.body.removeChild(temp);
      }
    });
  });
});
