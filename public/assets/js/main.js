document.addEventListener('DOMContentLoaded', () => {
  const pw = document.getElementById('password');
  const btn = document.querySelector('.password-toggle');
  if(pw && btn){
    btn.addEventListener('click', () => {
      const showing = pw.type === 'text';
      pw.type = showing ? 'password' : 'text';
      btn.setAttribute('aria-pressed', String(!showing));
      btn.setAttribute('aria-label', showing ? 'Tampilkan password' : 'Sembunyikan password');
      const icon = btn.querySelector('.material-symbols-outlined');
      if(icon){ icon.textContent = showing ? 'visibility' : 'visibility_off'; }
    });
  }
});
