// JS untuk halaman kirim_saku.php (top up wallet wali)
(function(){
    // Currency formatting for step 1
    const amtHidden = document.getElementById('jumlah');
    const amtDisplay = document.getElementById('jumlahDisplay');
    const form = document.getElementById('topupAmountForm');
    const jumlahErr = document.getElementById('jumlahError');
    function onlyDigits(s){return (s||'').replace(/[^0-9]/g,'');}
    function rupiah(n){try{return 'Rp ' + Number(n).toLocaleString('id-ID');}catch(e){return 'Rp ' + n;}}
    if(amtDisplay && amtHidden){
        let lastRaw='';
    function formatNow(){
            const caretPos = amtDisplay.selectionStart;
            const beforeLen = amtDisplay.value.length;
            const raw=onlyDigits(amtDisplay.value);
            lastRaw=raw;
            amtHidden.value=raw||0;
            amtDisplay.value = raw? rupiah(raw) : 'Rp 0';
            // Approx caret restore (ke akhir)
            amtDisplay.setSelectionRange(amtDisplay.value.length, amtDisplay.value.length);
        }
        amtDisplay.addEventListener('focus',()=>{
            if(!lastRaw) lastRaw=onlyDigits(amtDisplay.value);
        });
        amtDisplay.addEventListener('input',formatNow);
        amtDisplay.addEventListener('blur',formatNow);
        formatNow();
    }
    // Pastikan nilai tersembunyi terisi & validasi minimum sebelum submit
    if(form && amtHidden){
        form.addEventListener('submit',function(e){
            // Sync latest value
            if(amtDisplay){
                const raw=onlyDigits(amtDisplay.value);
                amtHidden.value = raw || 0;
            }
            const val = parseInt(amtHidden.value||'0',10);
            if(!val || val<=0){
                e.preventDefault();
                if(jumlahErr){ jumlahErr.textContent='Masukkan nominal top-up.'; }
                amtDisplay?.focus();
                return;
            }
            if(val<10000){
                e.preventDefault();
                if(jumlahErr){ jumlahErr.textContent='Minimal top-up Rp 10.000.'; }
                amtDisplay?.focus();
                return;
            }
            if(jumlahErr){ jumlahErr.textContent=''; }
        });
    }
    // Drag & drop style for upload step
    const drop=document.querySelector('.upload-drop');
    if(drop){
        const input=drop.querySelector('input[type=file]');
        const fileBox=document.getElementById('fileChosen');
        const fileNameEl=document.getElementById('fileChosenName');
        const clearBtn=document.getElementById('fileClearBtn');
        function showFile(){
            if(input.files && input.files.length){
                const f=input.files[0];
                if(fileNameEl){ fileNameEl.textContent = f.name + ' (' + (Math.round(f.size/1024)) + ' KB)'; }
                fileBox && (fileBox.hidden=false);
            } else { hideFile(); }
        }
        function hideFile(){ if(fileBox){ fileBox.hidden=true; } if(fileNameEl){ fileNameEl.textContent=''; } }
        input.addEventListener('change',showFile);
        clearBtn?.addEventListener('click',()=>{ input.value=''; hideFile(); input.focus(); });
        ['dragenter','dragover'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();e.stopPropagation();drop.classList.add('drag');}));
        ['dragleave','drop'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();e.stopPropagation();drop.classList.remove('drag');}));
        drop.addEventListener('drop',e=>{if(e.dataTransfer && e.dataTransfer.files.length){input.files=e.dataTransfer.files; showFile();}});
    }
})();
