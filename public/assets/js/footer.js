(function(){
  // Footer hide on scroll
  var f = document.getElementById('appFooter');
  if(f){
    var lastY = window.scrollY || 0, ticking=false;
    function onScroll(){
      var y = window.scrollY || 0;
      var hide = y > lastY && y - lastY > 6;
      f.classList.toggle('footer-hide', hide);
      lastY = y; ticking=false;
    }
    window.addEventListener('scroll', function(){ if(!ticking){ ticking=true; requestAnimationFrame(onScroll); } }, { passive:true });
  }
  // Mobile sidebar toggle
  var body = document.body;
  if(body.classList.contains('has-sidebar')){
    var menu = document.getElementById('mainMenu');
    if(menu){
      // Avoid adding a second toggle if ui.js already injected one
      if(!document.querySelector('.nav-toggle')){
        var btn = document.createElement('button');
        btn.className='mobile-nav-toggle';
        btn.type='button';
        btn.addEventListener('click', function(){
          var open = body.classList.toggle('menu-open');
          btn.classList.toggle('active', open);
          // inline transform as a fallback when CSS is overridden
          if(window.matchMedia && window.matchMedia('(max-width: 1100px)').matches){
            menu.style.transform = open ? 'translateX(0)' : 'translateX(-100%)';
          }
        });
        document.body.appendChild(btn);
        document.addEventListener('click', function(e){
          if(!body.classList.contains('menu-open')) return;
          if(menu.contains(e.target) || btn.contains(e.target)) return;
          body.classList.remove('menu-open');
          btn.classList.remove('active');
          if(window.matchMedia && window.matchMedia('(max-width: 1100px)').matches){ menu.style.transform = 'translateX(-100%)'; }
        });
      }
    }
  }
})();
