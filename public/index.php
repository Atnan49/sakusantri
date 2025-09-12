<?php
require_once __DIR__ . "/../src/includes/init.php";
if (!empty($_SESSION["role"])) {
  header("Location: " . ($_SESSION["role"] === "admin" ? url("admin/") : url("wali/")));
  exit;
}
require_once __DIR__ . "/../src/includes/header.php";
?>

<main class="login-container">
  <?php
    // Optional blurred HIMATIF background
    $bgCandidates = [
      'assets/img/HIMATIF(1).png',
      'assets/img/HIMATIF1 (1).png',
      'assets/img/HIMATIF.png',
      'assets/img/himatif.png'
    ];
    $himatifBgRel = null; $bgv='';
    foreach($bgCandidates as $rel){ $abs = BASE_PATH.'/public/'.$rel; if(file_exists($abs)){ $himatifBgRel = $rel; $bgv='?v='.filemtime($abs); break; } }
    if($himatifBgRel): ?>
      <div class="bg-himatif-blur" style="background-image:url('<?php echo e(url($himatifBgRel).$bgv); ?>')"></div>
  <?php endif; ?>
  <!-- Brand Header (Desktop) -->
  <div class="brand-header">
    <div class="brand-logo">
      <?php
        // Optional: show HIMATIF partner logo if present
        $himatifCandidates = [
          'assets/img/HIMATIF(1).png',
          'assets/img/HIMATIF1 (1).png',
          'assets/img/HIMATIF.png',
          'assets/img/himatif.png'
        ];
        $himatifRel = null; $hv='';
        foreach($himatifCandidates as $rel){ $abs = BASE_PATH.'/public/'.$rel; if(file_exists($abs)){ $himatifRel = $rel; $hv='?v='.filemtime($abs); break; } }
      ?>
      <?php /* Partner logo (HIMATIF) removed per request; leave code commented for easy restore
      if($himatifRel): ?>
        <img src="<?php echo url($himatifRel).$hv; ?>" alt="HIMATIF" title="HIMATIF" class="partner-icon js-hide-on-error" width="38" height="38" />
      <?php endif; */ ?>
      <img src="<?php echo url('assets/img/logo.png'); ?>" alt="SakuSantri" class="logo-icon js-hide-on-error">
      <span class="brand-text">SakuSantri</span>
    </div>
  </div>

  <!-- Mobile Header Circle -->
  <div class="mobile-header">
    <div class="mobile-logo">
      <div class="mobile-logo-row">
  <?php /* Partner mobile logo removed per request */ ?>
        <img src="<?php echo url('assets/img/logo.png'); ?>" alt="SakuSantri" class="logo-mobile js-hide-on-error">
      </div>
      <span class="brand-mobile">SakuSantri</span>
    </div>
  </div>

  <!-- Main Content -->
  <div class="login-main">
    <!-- Left Side (Desktop) -->
    <div class="login-left">
      <div class="illustration">
        <img src="<?php echo url('assets/img/hero.png'); ?>" alt="Ilustrasi Santri" class="hero-img" loading="lazy" decoding="async">
      </div>
      <p class="tagline">SakuSantri hadir sebagai solusi digital untuk mendampingi kehidupan santri yang tertib, teratur, dan produktif di era modern.</p>
    </div>

    <!-- Right Side (Login Card) -->
    <div class="login-right">
      <!-- LOGIN Badge (Mobile) -->
  <div class="login-badge">MASUK</div>
      
      <!-- Login Card -->
      <div class="login-card">
        <!-- LOGIN Cap (Desktop) -->
        <div class="login-cap">
          <span>MASUK</span>
        </div>
        
        <!-- Login Form Panel -->
        <div class="login-panel">
          <?php
            if(isset($_GET['pesan'])){
              if($_GET['pesan'] === 'gagal'){
                echo '<div class="alert error">Login gagal! NIS atau password salah.</div>';
              } elseif($_GET['pesan'] === 'sesi_berakhir') {
                echo '<div class="alert info">Sesi berakhir. Silakan login kembali.</div>';
              } elseif($_GET['pesan'] === 'logout') {
                echo '<div class="alert info">Berhasil logout.</div>';
              }
            }
          ?>
          <form action="<?php echo url('login'); ?>" method="POST" class="login-form" novalidate>
            <div class="form-field icon-holder">
              <label for="nisn">
                NIS
              </label>
              <span class="material-symbols-outlined form-icon" aria-hidden="true">mail</span>
              <input type="text" id="nisn" name="nisn" required autocomplete="username" minlength="3" maxlength="30" pattern="[A-Za-z0-9._-]{3,}" title="ID minimal 3 karakter (huruf/angka/titik/garis/underscore)">
            </div>
            <div class="form-field icon-holder">
              <label for="password">Password</label>
              <span class="material-symbols-outlined form-icon" aria-hidden="true">key</span>
              <div class="password-field">
                <input type="password" id="password" name="password" required autocomplete="current-password" minlength="8" aria-describedby="pwHelp">
                <button type="button" class="password-toggle" aria-label="Tampilkan password" aria-pressed="false">
                  <span class="material-symbols-outlined" aria-hidden="true">visibility</span>
                </button>
              </div>
              <small id="pwHelp" class="sr-only">Minimal 8 karakter.</small>
            </div>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            
            <!-- MASUK Button -->
            <button type="submit" class="btn-masuk">MASUK</button>
          </form>
          
          <!-- Google login removed per request -->
        </div>
      </div>
    </div>
  </div>

  <!-- Decorative Circles -->
  <div class="circle-decoration circle-left"></div>
  <div class="circle-decoration circle-right"></div>
</main>
<script src="assets/js/main.js" defer></script>
<?php require_once __DIR__ . "/../src/includes/footer.php"; ?>
