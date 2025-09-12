<?php
// analytics.php - reusable aggregation helpers for dashboard & reports
// All functions are read-only and safe to call multiple times (internally cached per request)

if(!function_exists('format_rp')){
  // Fallback (should be defined in helpers.php after patch). Kept defensive.
  function format_rp(float $v, int $dec=0): string { return 'Rp '.number_format($v,$dec,',','.'); }
}

if(!function_exists('analytics_dashboard_core')){
  /**
   * Get core dashboard metrics.
   * @return array{
   *   total_wali:int,
   *   total_saldo:float,
   *   invoice:array{pending:int,partial:int,overdue:int,paid:int,canceled:int},
   *   outstanding:float,
   *   payments:array{pending_invoice:int,pending_topup:int,pending_total:int}
   * }
   */
  function analytics_dashboard_core(mysqli $conn): array {
    static $cache=null; if($cache!==null) return $cache;
    $invoice = ['pending'=>0,'partial'=>0,'overdue'=>0,'paid'=>0,'canceled'=>0];
    $total_wali=0; $total_saldo=0.0; $outstanding=0.0; $pending_invoice=0; $pending_topup=0;
    $invoice_total_amount = 0.0; // total nominal seluruh tagihan SPP (semua status)
    // Total wali
    if($rs=mysqli_query($conn,"SELECT COUNT(id) c FROM users WHERE role='wali_santri'")){ $total_wali=(int)(mysqli_fetch_assoc($rs)['c']??0); }
    // Total saldo (cached di users.saldo)
    if($rs=mysqli_query($conn,"SELECT SUM(saldo) s FROM users")){ $total_saldo=(float)(mysqli_fetch_assoc($rs)['s']??0); }
  // Invoice status counts + outstanding + total nominal
  if($rs=mysqli_query($conn,"SELECT status, COUNT(*) c, SUM(amount-paid_amount) os, SUM(amount) ta FROM invoice GROUP BY status")){
      while($r=mysqli_fetch_assoc($rs)){
        $st=$r['status']; if(isset($invoice[$st])){ $invoice[$st]=(int)$r['c']; }
        if(in_array($st,['pending','partial','overdue'],true)) $outstanding += (float)($r['os']??0);
    $invoice_total_amount += (float)($r['ta']??0);
      }
    }
    // Pending payments (invoice linked vs top-up) statuses awaiting admin action
    $statusList="('awaiting_confirmation','awaiting_proof','initiated')"; // include initiated for early visibility
    if($rs=mysqli_query($conn,"SELECT invoice_id IS NULL AS is_topup, COUNT(*) c FROM payment WHERE status IN $statusList GROUP BY is_topup")){
      while($r=mysqli_fetch_assoc($rs)){
        if((int)$r['is_topup']===1) $pending_topup += (int)$r['c']; else $pending_invoice += (int)$r['c'];
      }
    }
    $cache=[
      'total_wali'=>$total_wali,
      'total_saldo'=>$total_saldo,
      'invoice'=>$invoice,
      'outstanding'=>$outstanding,
  'payments'=>[
        'pending_invoice'=>$pending_invoice,
        'pending_topup'=>$pending_topup,
        'pending_total'=>$pending_invoice+$pending_topup
  ],
  'invoice_total_amount'=>$invoice_total_amount
    ];
    return $cache;
  }
}

if(!function_exists('analytics_recent_pending_payments')){
  /**
   * Get recent pending payments (invoice or topup) limited.
   * @param bool $invoice True = invoice payments, False = topups
   * @return array<int,array<string,mixed>>
   */
  function analytics_recent_pending_payments(mysqli $conn, int $limit=5, bool $invoice=true): array {
    $rows=[]; $limit=max(1,min($limit,50));
    $statuses="('awaiting_confirmation','awaiting_proof','initiated')";
    if($invoice){
      $sql="SELECT p.id,p.amount,p.status,p.created_at,u.nama_wali,i.period FROM payment p JOIN users u ON p.user_id=u.id LEFT JOIN invoice i ON p.invoice_id=i.id WHERE p.invoice_id IS NOT NULL AND p.status IN $statuses ORDER BY p.created_at DESC LIMIT $limit";
    } else {
      $sql="SELECT p.id,p.amount,p.status,p.created_at,u.nama_wali FROM payment p JOIN users u ON p.user_id=u.id WHERE p.invoice_id IS NULL AND p.status IN $statuses ORDER BY p.created_at DESC LIMIT $limit";
    }
    $res=mysqli_query($conn,$sql); while($res && $r=mysqli_fetch_assoc($res)) $rows[]=$r; return $rows;
  }
}

if(!function_exists('analytics_invoice_distribution')){
  /**
   * Distribution + outstanding for a given period (or all if null)
   * @return array{dist:array{pending:int,partial:int,overdue:int,paid:int,canceled:int},outstanding:float,total:int}
   */
  function analytics_invoice_distribution(mysqli $conn, ?string $period): array {
    $dist=['pending'=>0,'partial'=>0,'overdue'=>0,'paid'=>0,'canceled'=>0]; $outstanding=0.0;
    $where='1=1'; if($period){ $p=mysqli_real_escape_string($conn,$period); $where.=" AND period='$p'"; }
    $sql="SELECT status, COUNT(*) c, SUM(amount-paid_amount) os FROM invoice WHERE $where GROUP BY status";
    $res=mysqli_query($conn,$sql); while($res && $r=mysqli_fetch_assoc($res)){
      $st=$r['status']; if(isset($dist[$st])) $dist[$st]=(int)$r['c'];
      if(in_array($st,['pending','partial','overdue'],true)) $outstanding += (float)($r['os']??0);
    }
    return ['dist'=>$dist,'outstanding'=>$outstanding,'total'=>array_sum($dist)];
  }
}

?>
