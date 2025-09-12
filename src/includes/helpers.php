<?php
// filepath: c:\xampp\htdocs\saku_santri\src\includes\helpers.php
function url(string $path = ""): string {
  $p = "/" . ltrim($path, "/");
  return rtrim(BASE_URL, "/") . $p;
}
function abs_url(string $path = ""): string {
  // Build absolute URL using detected origin and BASE_URL
  $p = "/" . ltrim($path, "/");
  return rtrim(APP_ORIGIN, "/") . rtrim(BASE_URL, "/") . $p;
}
function canonical_path(string $path = ""): string {
  // Normalize path: single leading slash, no trailing slash (except root)
  $p = "/" . ltrim($path, "/");
  if ($p !== "/") { $p = rtrim($p, "/"); }
  return $p;
}
function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
}

// Normalize monetary amount: clamp negative to 0, round to 2 decimals, cast to float
function normalize_amount($val): float {
  if(!is_numeric($val)) return 0.0;
  $v = (float)$val;
  if($v < 0) $v = 0.0;
  // Avoid binary float artifacts by formatting then casting
  return (float) number_format(round($v + 0.0000001, 2), 2, '.', '');
}

// Format rupiah consistently (default without decimals)
if(!function_exists('format_rp')){
  function format_rp(float $v, int $dec=0): string { return 'Rp '.number_format($v,$dec,',','.'); }
}

