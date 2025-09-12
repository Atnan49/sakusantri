(function(){
  // QUICK GENERATE JS ONLY (manual form removed)
  function onlyDigits(s){return (s||'').replace(/[^0-9]/g,'');}
  var qDisp=document.getElementById('quickNominalDisplay');
  var qVal=document.getElementById('quickNominal');
  function fmt(n){return 'Rp '+ (Number(n)||0).toLocaleString('id-ID');}
  function sync(){
    if(!qDisp||!qVal) return;
    var v=parseInt(onlyDigits(qDisp.value),10)||0;
    qVal.value=v;
    qDisp.value=fmt(v);
  }
  if(qDisp&&qVal){
    qDisp.addEventListener('input',sync);
    qDisp.addEventListener('blur',sync);
    sync();
  }
  window.submitGenerate=function(m){
    if(!qVal){alert('Nominal belum diisi');return;}
    var v=parseInt(qVal.value,10)||0;
    if(!v){alert('Nominal belum diisi');return;}
    if(!confirm('Generate tagihan bulan ini?')) return;
    var bulanGen = document.getElementById('bulan_gen');
    var jumlahGen = document.getElementById('jumlah_gen');
    var form = document.getElementById('quickGenForm');
    if(!bulanGen||!jumlahGen||!form){alert('Form tidak ditemukan');return;}
    bulanGen.value=m;
    jumlahGen.value=v;
    form.submit();
  };
  window.submitUpdate=function(m){
    if(!qVal){alert('Nominal belum diisi');return;}
    var v=parseInt(qVal.value,10)||0;
    if(!v){alert('Nominal belum diisi');return;}
    if(!confirm('Update nominal tagihan bulan ini? Hanya mempengaruhi yang menunggu pembayaran.')) return;
    var bulanGenUp = document.getElementById('bulan_gen_up');
    var jumlahGenUp = document.getElementById('jumlah_gen_up');
    var form = document.getElementById('quickUpdateForm');
    if(!bulanGenUp||!jumlahGenUp||!form){alert('Form tidak ditemukan');return;}
    bulanGenUp.value=m;
    jumlahGenUp.value=v;
    form.submit();
  };
})();
// File: public/assets/js/generate_spp.js
(function(){
  const year=document.getElementById('yearSel'); const month=document.getElementById('monthSel'); const typeSel=document.getElementById('typeSel'); const monthWrap=document.getElementById('monthWrap');
  const dAmt=document.getElementById('amountDisplay'); const rAmt=document.getElementById('amountRaw');
  // const quick=document.querySelectorAll('.quick-amt button[data-v]');
  const due=document.getElementById('dueDateInput'); const autoEnd=document.getElementById('autoEnd');
  const pvPeriod=document.getElementById('pvPeriod'); const pvTotalWali=document.getElementById('pvTotalWali'); const pvSudah=document.getElementById('pvSudah'); const pvBuat=document.getElementById('pvBuat'); const pvStatus=document.getElementById('pvStatus'); const btnGen=document.getElementById('btnGenerate');
  // Chips
  const chipPeriod=document.getElementById('chipPeriod'); const chipTotal=document.getElementById('chipTotal'); const chipExisting=document.getElementById('chipExisting'); const chipToCreate=document.getElementById('chipToCreate');
  document.getElementById('btnFocusForm')?.addEventListener('click',()=>{ year?.focus(); });
  function onlyDigits(s){return (s||'').replace(/[^0-9]/g,'');}
  function fmt(n){return 'Rp '+(Number(n)||0).toLocaleString('id-ID');}
  function syncAmount(){
    const v=parseInt(onlyDigits(dAmt.value),10)||0;
    rAmt.value=v;
    dAmt.value=v?fmt(v):'';
    // Fallback: tombol Generate selalu aktif jika nominal > 0
    btnGen.disabled = v<=0 ? true : false;
  }
  function setEndMonth(){ const y=parseInt(year.value,10); const m=parseInt(month.value,10); if(!y||!m) return; const last=new Date(y,m,0).getDate(); due.value=`${y}-${String(m).padStart(2,'0')}-${String(last).padStart(2,'0')}`; }
  let lastReq=0; let t=null;
  function preview(){
    const y=year.value; const curType=typeSel?typeSel.value:'spp';
  const m = (curType==='spp' || curType==='beasiswa') ? month.value : '';
  if(!y || ((curType==='spp' || curType==='beasiswa') && !m)){ if(pvStatus) pvStatus.textContent='Periode belum lengkap'; return;}
    const started=++lastReq; if(pvStatus) pvStatus.textContent='Memuat...';
    const url = `generate_spp.php?preview=1&type=${encodeURIComponent(curType)}&year=${encodeURIComponent(y)}&month=${encodeURIComponent(m||'')}`;
    fetch(url,{headers:{'Accept':'application/json'}})
      .then(r=>r.json()).then(j=>{
        if(started!==lastReq) return;
        if(!j.ok){ if(pvStatus) pvStatus.textContent='Preview gagal'; btnGen.disabled=false; return; }
        if(pvPeriod) pvPeriod.textContent=j.period;
        if(pvTotalWali) pvTotalWali.textContent=j.total_wali;
        if(pvSudah) pvSudah.textContent=j.sudah_ada;
        if(pvBuat) pvBuat.textContent=j.akan_dibuat;
        if(pvStatus) pvStatus.textContent=j.akan_dibuat>0?'Siap generate':'Semua sudah ada';
        // update chips with pulse animation
        const upd=(el,val)=>{ if(!el) return; if(el.textContent!==String(val)){ el.textContent=val; el.classList.remove('pulse'); void el.offsetWidth; el.classList.add('pulse'); } };
        upd(chipPeriod,j.period); upd(chipTotal,j.total_wali); upd(chipExisting,j.sudah_ada); upd(chipToCreate,j.akan_dibuat);
        if(chipToCreate) chipToCreate.classList.toggle('zero', j.akan_dibuat<=0);
        btnGen.disabled = parseInt(onlyDigits(dAmt.value),10)<=0 ? true : false;
      })
      .catch(()=>{ if(started!==lastReq) return; if(pvStatus) pvStatus.textContent='Gagal ambil preview'; btnGen.disabled=false; });
  }
  function schedule(){ if(t) clearTimeout(t); t=setTimeout(preview,250); }
  year.addEventListener('change',()=>{ if(autoEnd.checked && (typeSel.value==='spp'||typeSel.value==='beasiswa')) setEndMonth(); schedule(); });
  if(month) month.addEventListener('change',()=>{ if(autoEnd.checked && (typeSel.value==='spp'||typeSel.value==='beasiswa')) setEndMonth(); schedule(); });
  if(typeSel){
    typeSel.addEventListener('change',()=>{
      const t=typeSel.value;
      if(t==='spp' || t==='beasiswa'){
        monthWrap.style.display='';
        month.setAttribute('required','required');
        if(autoEnd.checked) setEndMonth();
      } else {
        monthWrap.style.display='none';
        month.removeAttribute('required');
        due.value = year.value+'-07-15'; // default due untuk daftar ulang
      }
      schedule();
    });
  }
  dAmt.addEventListener('input',syncAmount); dAmt.addEventListener('blur',syncAmount); syncAmount();
  // quick.forEach(b=>b.addEventListener('click',()=>{ rAmt.value=b.getAttribute('data-v'); dAmt.value=fmt(b.getAttribute('data-v')); syncAmount(); }));
  document.querySelector('.btn-end-month')?.addEventListener('click',()=>{ setEndMonth(); autoEnd.checked=false; });
  document.querySelector('.btn-plus7')?.addEventListener('click',()=>{ const base = due.value? new Date(due.value): new Date(); base.setDate(base.getDate()+7); due.value=base.toISOString().slice(0,10); autoEnd.checked=false; });
  autoEnd.addEventListener('change',()=>{ if(autoEnd.checked && (!typeSel || typeSel.value==='spp')) setEndMonth(); }); if(autoEnd.checked && (!typeSel || typeSel.value==='spp')) setEndMonth(); schedule();
})();

// Single-user form enhancements
(function(){
  const year=document.getElementById('yearSelSingle');
  const month=document.getElementById('monthSelSingle');
  const typeSel=document.getElementById('typeSelSingle');
  const monthWrap=document.getElementById('monthWrapSingle');
  const dAmt=document.getElementById('amountDisplaySingle');
  const rAmt=document.getElementById('amountRawSingle');
  const due=document.getElementById('dueDateInputSingle');
  const autoEnd=document.getElementById('autoEndSingle');
  function onlyDigits(s){return (s||'').replace(/[^0-9]/g,'');}
  function fmt(n){return 'Rp '+(Number(n)||0).toLocaleString('id-ID');}
  function syncAmount(){ const v=parseInt(onlyDigits(dAmt.value),10)||0; rAmt.value=v; dAmt.value=v?fmt(v):''; }
  function setEndMonth(){ const y=parseInt(year.value,10); const m=parseInt(month.value,10); if(!y||!m) return; const last=new Date(y,m,0).getDate(); due.value=`${y}-${String(m).padStart(2,'0')}-${String(last).padStart(2,'0')}`; }
  if(dAmt&&rAmt){ dAmt.addEventListener('input',syncAmount); dAmt.addEventListener('blur',syncAmount); syncAmount(); }
  if(typeSel && monthWrap && month){
    typeSel.addEventListener('change',()=>{
      const t=typeSel.value;
      if(t==='spp' || t==='beasiswa'){ monthWrap.style.display=''; month.setAttribute('required','required'); if(autoEnd?.checked) setEndMonth(); }
      else { monthWrap.style.display='none'; month.removeAttribute('required'); due.value = year.value+'-07-15'; }
    });
  }
  if(autoEnd){ autoEnd.addEventListener('change',()=>{ if(autoEnd.checked && (!typeSel || typeSel.value==='spp' || typeSel.value==='beasiswa')) setEndMonth(); }); if(autoEnd.checked && (!typeSel || typeSel.value==='spp' || typeSel.value==='beasiswa')) setEndMonth(); }
})();
