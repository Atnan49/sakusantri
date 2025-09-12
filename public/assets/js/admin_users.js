// Admin Users (Kelola Pengguna) interactions
// Assumes no inline JS (CSP compliant). All event binding delegated after DOMContentLoaded.
(function(){
  'use strict';
  const $ = (sel,ctx=document)=>ctx.querySelector(sel);
  const $$ = (sel,ctx=document)=>Array.from(ctx.querySelectorAll(sel));

  function ready(fn){ if(document.readyState!=='loading') fn(); else document.addEventListener('DOMContentLoaded',fn,{once:true}); }

  function openModal(){ const m=$('#addUserModal'); if(!m) return; m.hidden=false; m.classList.add('open'); const first=m.querySelector('input[name="nama_wali"]'); if(first) first.focus(); document.body.classList.add('modal-open'); }
  function closeModal(e){ const m=$('#addUserModal'); if(!m) return; if(e){ if(e.target.matches('[data-close]') || e.target===m){} else return; } m.classList.remove('open'); m.hidden=true; document.body.classList.remove('modal-open'); }

  function toggleInline(panelId){ const el=document.getElementById(panelId); if(!el) return; el.hidden=!el.hidden; }

  function bindRowToggles(){
    document.addEventListener('click',e=>{
      const btn=e.target.closest('.reset-toggle');
      if(btn){ e.preventDefault(); toggleInline('resetPanel'+btn.dataset.id); return; }
      const av=e.target.closest('.avatar-toggle');
      if(av){ e.preventDefault(); toggleInline('avatarPanel'+av.dataset.id); return; }
    });
  }

  function bindModal(){
    const openBtn = $('#btnOpenAdd');
    if(openBtn){ openBtn.addEventListener('click',openModal); }
    document.addEventListener('click',closeModal);
    document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ closeModal(); } });
  }

  // Simple client-side search UX enhancements (debounce + clear button already in HTML)
  function bindSearch(){
    const fQ = $('#fQ'); const clearBtn=$('#btnClearSearch'); const form=$('#searchForm');
    if(!fQ || !form) return;
    let t=null; const DEBOUNCE=400;
    fQ.addEventListener('input',()=>{
      if(clearBtn) clearBtn.hidden = fQ.value==='';
      if(t) clearTimeout(t); t=setTimeout(()=>{ form.submit(); }, DEBOUNCE);
    });
    if(clearBtn){ clearBtn.addEventListener('click',()=>{ fQ.value=''; clearBtn.hidden=true; form.submit(); }); }
  }

  // Highlight search term in table (progressive enhancement)
  function highlightTerm(){
    const urlParams=new URLSearchParams(location.search); const term=(urlParams.get('q')||'').trim(); if(term==='') return;
    const safe=term.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
    const re=new RegExp('('+safe+')','gi');
    $$('#addUserModal, .pengguna-table td').forEach(cell=>{
      if(cell.children.length) return; // skip complex cells
      const txt=cell.textContent; if(!txt || !re.test(txt)) return; cell.innerHTML=txt.replace(re,'<mark>$1</mark>');
    });
  }

  ready(()=>{ bindModal(); bindRowToggles(); bindSearch(); highlightTerm(); });
})();
