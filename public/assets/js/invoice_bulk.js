(function(){
  function fmt(n){
    try{ return (n||0).toLocaleString('id-ID'); }catch(e){ return String(n||0); }
  }
  function q(sel,root){ return (root||document).querySelector(sel); }
  function qa(sel,root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }
  var btnToggle = q('#btnBulkToggle');
  var bulkCols = qa('.bulk-col');
  var chkAll = q('#chkAll');
  var bulkBar = q('#bulkBar');
  var btnCancel = q('#btnBulkCancel');
  var btnPay = q('#btnBulkPay');
  var countEl = q('#bulkCount');
  var totalEl = q('#bulkTotal');
  var balance = null; // no wallet balance constraint in proof-upload flow
  var form = q('#bulkForm');
  var inBulk = false;

  function setBulkMode(on){
    inBulk = !!on;
    bulkCols.forEach(function(th){ th.style.display = on? 'table-cell' : 'none'; });
    // mobile cards
    qa('.invoice-entry-mobile .im-bulk').forEach(function(el){ el.style.display = on? 'block' : 'none'; });
    if(on){ bulkBar.style.display = 'block'; } else { bulkBar.style.display = 'none'; }
    // clear checks
    qa('.chkOne').forEach(function(c){ c.checked = false; });
    if(chkAll) chkAll.checked = false;
    updateTotals();
  }

  function updateTotals(){
    var items = qa('.chkOne:checked');
    var total = items.reduce(function(acc, el){ return acc + (parseInt(el.getAttribute('data-remaining')||'0',10)||0); }, 0);
    countEl.textContent = String(items.length);
    totalEl.textContent = fmt(total);
    // enable pay if any selected and total>0
    btnPay.disabled = !(items.length>0 && total>0);
    btnPay.removeAttribute('title');
  }

  if(btnToggle){ btnToggle.addEventListener('click', function(){ setBulkMode(!inBulk); }); }
  if(btnCancel){ btnCancel.addEventListener('click', function(){ setBulkMode(false); }); }
  if(chkAll){ chkAll.addEventListener('change', function(){
    var on = !!chkAll.checked; qa('.chkOne:not(:disabled)').forEach(function(c){ c.checked = on; }); updateTotals();
  }); }
  document.addEventListener('change', function(ev){
    if(!inBulk) return;
    var t = ev.target; if(t && t.classList && t.classList.contains('chkOne')){ updateTotals(); }
  });
  if(form){ form.addEventListener('submit', function(ev){
    if(!inBulk){ ev.preventDefault(); setBulkMode(true); return; }
    var selected = qa('.chkOne:checked');
    if(selected.length===0){ ev.preventDefault(); alert('Pilih minimal satu tagihan.'); return; }
    // proceed to summary/proof page
  }); }
})();
