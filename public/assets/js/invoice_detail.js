document.addEventListener('DOMContentLoaded', function() {
  var buktiInput = document.getElementById('buktiInput');
  var btnBayar = document.getElementById('btnBayarSpp');
  if (buktiInput && btnBayar) {
    buktiInput.addEventListener('change', function() {
      btnBayar.disabled = !buktiInput.files.length;
    });
  }
});
