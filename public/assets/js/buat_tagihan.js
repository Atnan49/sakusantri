// JS khusus halaman buat_tagihan.php
(function(){
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
    console.log('[DEBUG] submitGenerate called, bulan:', m);
    alert('[DEBUG] submitGenerate called, bulan: '+m);
    if(!qVal){alert('Nominal belum diisi');return;}
    var v=parseInt(qVal.value,10)||0;
    if(!v){alert('Nominal belum diisi');return;}
    if(!confirm('Generate tagihan bulan ini?')) return;
    var bulanGen = document.getElementById('bulan_gen');
    var jumlahGen = document.getElementById('jumlah_gen');
    var form = document.getElementById('quickGenForm');
    if(!bulanGen||!jumlahGen||!form){alert('[DEBUG] Form tidak ditemukan!');console.log('[DEBUG] bulanGen:',bulanGen,'jumlahGen:',jumlahGen,'form:',form);return;}
    bulanGen.value=m;
    jumlahGen.value=v;
    alert('[DEBUG] Form ditemukan, akan submit!');
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