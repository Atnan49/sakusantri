<?php
// payments.php - foundational helpers for new payment system
// Idempotent creation & status transitions with minimal validation (Phase P0)

if(!function_exists('ledger_post')){
  function ledger_post(mysqli $conn, ?int $userId, string $account, float $debit, float $credit, ?string $refType, ?int $refId, string $note=''): bool {
    // Corrected syntax for ledger_post function
  $stmt = mysqli_prepare($conn, 'INSERT INTO ledger_entries (user_id,account,debit,credit,ref_type,ref_id,note) VALUES (?,?,?,?,?,?,?)');
  if(!$stmt) return false;
  // Perbaiki typo: $RefId menjadi $refId
  mysqli_stmt_bind_param($stmt,'isddsis',$userId,$account,$debit,$credit,$refType,$refId,$note);
  $ok = mysqli_stmt_execute($stmt) === true;

  if($ok && $userId && $account==='WALLET'){
    // Sync users.saldo (best effort, ignore error)
    @mysqli_query($conn, 'UPDATE users u SET saldo = (SELECT COALESCE(SUM(debit-credit),0) FROM ledger_entries le WHERE le.user_id='.(int)$userId.' AND le.account="WALLET") WHERE u.id='.(int)$userId.' LIMIT 1');
  }
  return $ok;
  }
}

if(!function_exists('invoice_create')){
  function invoice_create(mysqli $conn, int $userId, string $type, ?string $period, float $amount, ?string $dueDate, string $notes=''): ?int {
    $now = date('Y-m-d H:i:s');
    // Prevent duplicate for same user/type/period
    if($period !== null){
      $chk = mysqli_prepare($conn,'SELECT id FROM invoice WHERE user_id=? AND type=? AND period=? LIMIT 1');
      if($chk){
        mysqli_stmt_bind_param($chk,'iss',$userId,$type,$period);
        mysqli_stmt_execute($chk);
        $res = mysqli_stmt_get_result($chk);
        if($res && mysqli_fetch_row($res)) return null;
      }
    }
    // Feature detect meta_json column once
    static $invoiceMetaChecked = null; static $invoiceHasMeta = false;
    if($invoiceMetaChecked === null){
      $invoiceMetaChecked = true;
      $rsCol = @mysqli_query($conn,"SHOW COLUMNS FROM invoice LIKE 'meta_json'");
      $invoiceHasMeta = $rsCol && mysqli_num_rows($rsCol) > 0;
    }
    $meta = null; $source = 'manual'; // default source manual
    if($invoiceHasMeta){
      $metaArr = ['source'=>$source,'period'=>$period,'created_via'=>'invoice_create'];
      $meta = json_encode($metaArr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      $stmt = mysqli_prepare($conn,'INSERT INTO invoice (user_id,type,period,amount,due_date,status,notes,created_at,meta_json,source) VALUES (?,?,?,?,?,"pending",?,?,?,?)');
      if(!$stmt) { error_log('MySQL prepare error: '.mysqli_error($conn)); return null; }
      mysqli_stmt_bind_param($stmt,'issdsssss',$userId,$type,$period,$amount,$dueDate,$notes,$now,$meta,$source);
    } else {
      $stmt = mysqli_prepare($conn,'INSERT INTO invoice (user_id,type,period,amount,due_date,status,notes,created_at) VALUES (?,?,?,?,?,"pending",?,?)');
      if(!$stmt) { error_log('MySQL prepare error: '.mysqli_error($conn)); return null; }
      mysqli_stmt_bind_param($stmt,'issdsss',$userId,$type,$period,$amount,$dueDate,$notes,$now);
    }
    if(!mysqli_stmt_execute($stmt)) {
        error_log('MySQL execute error: ' . mysqli_error($conn));
        return null;
    }
    $iid = mysqli_insert_id($conn) ?: null;
    if($iid && function_exists('add_notification')){
      $msg = 'Tagihan baru: '.strtoupper($type).($period?(' '.$period):'').' Rp '.number_format($amount,0,',','.');
  @add_notification($conn,(int)$userId,'invoice_created',$msg,(array)array('invoice_id'=>$iid,'amount'=>$amount,'type'=>$type,'period'=>$period));
    }
    return $iid;
  }
}

if(!function_exists('invoice_generate_spp_bulk')){
  /**
   * Generate SPP invoices for all wali_santri that don't yet have this period.
   * @return array [created => int, skipped => int]
   */
  // Helper: detect and fetch per-user SPP discount settings (scholarship)
  function _user_spp_discount(mysqli $conn, int $userId): ?array {
    static $discChecked=null, $hasCols=false, $hasYear=false;
    if($discChecked===null){
      $discChecked=true;
      $c1=@mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'spp_discount_type'");
      $c2=@mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'spp_discount_value'");
      $c3=@mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'spp_discount_year'");
      // until column optional
      $hasCols = ($c1 && mysqli_num_rows($c1)>0) && ($c2 && mysqli_num_rows($c2)>0);
      $hasYear = ($c3 && mysqli_num_rows($c3)>0);
    }
    if(!$hasCols) return null;
    if($hasYear){
      $stmt = mysqli_prepare($conn,'SELECT spp_discount_type, spp_discount_value, IFNULL(spp_discount_until, ""), spp_discount_year FROM users WHERE id=? LIMIT 1');
    } else {
      $stmt = mysqli_prepare($conn,'SELECT spp_discount_type, spp_discount_value, IFNULL(spp_discount_until, "") FROM users WHERE id=? LIMIT 1');
    }
    if(!$stmt) return null;
  mysqli_stmt_bind_param($stmt,'i',$userId);
  mysqli_stmt_execute($stmt);
  $rs=mysqli_stmt_get_result($stmt);
  $row=$rs?mysqli_fetch_row($rs):null; if(!$row) return null;
    $type = $row[0] ? strtolower((string)$row[0]) : '';
    $val = (float)$row[1];
    $until = $row[2]!==null ? trim((string)$row[2]) : '';
    $year = isset($row[3]) && $row[3]!==null ? (int)$row[3] : null;
    if(!$type || $val<=0) return null;
    return ['type'=>$type,'value'=>$val,'until'=>$until?:null,'year'=>$year];
  }

  function _apply_spp_discount_for_period(mysqli $conn, int $userId, string $period, float $baseAmount, ?string &$note): float {
    $note = '';
    $disc = _user_spp_discount($conn,$userId);
    if(!$disc) return $baseAmount;
    // Check validity by period (YYYYMM)
    if(!preg_match('/^[0-9]{6}$/',$period)) return $baseAmount;
    // If year-scope configured, only apply for that year
    if(isset($disc['year']) && $disc['year']){
      $pYear = (int)substr($period,0,4);
      if($pYear !== (int)$disc['year']){ return $baseAmount; }
    } else {
      // Else fallback to 'until' behavior
      if(!empty($disc['until']) && preg_match('/^[0-9]{6}$/',$disc['until'])){
        if(strcmp($period,$disc['until'])>0){ return $baseAmount; }
      }
    }
    $new = $baseAmount;
    if($disc['type']==='percent'){
      $p = max(0.0, min(100.0, (float)$disc['value']));
      $new = round($baseAmount * (1.0 - $p/100.0));
      $yrNote = isset($disc['year']) && $disc['year'] ? ' '.$disc['year'] : '';
      $note = '(beasiswa '.$p.'%'.$yrNote.')';
    } else {
      $cut = max(0.0,(float)$disc['value']);
      $new = max(0.0, $baseAmount - $cut);
      $yrNote = isset($disc['year']) && $disc['year'] ? ' '.$disc['year'] : '';
      $note = '(beasiswa Rp '.number_format($cut,0,',','.').$yrNote.')';
    }
    return $new;
  }

  function invoice_generate_spp_bulk(mysqli $conn, string $period, float $amount, ?string $dueDate=null): array {
    $created=0; $skipped=0; $dueDate = $dueDate ?: date('Y-m-d', strtotime(substr($period,0,4).'-'.substr($period,4,2).'-10'));
    $res = mysqli_query($conn, "SELECT id FROM users WHERE role='wali_santri'");
    while($res && ($u = mysqli_fetch_assoc($res))){
      $id = (int)$u['id'];
      $chk = mysqli_prepare($conn,'SELECT id FROM invoice WHERE user_id=? AND type="spp" AND period=? LIMIT 1');
      if($chk){ mysqli_stmt_bind_param($chk,'is',$id,$period); mysqli_stmt_execute($chk); $r=mysqli_stmt_get_result($chk); if($r && mysqli_fetch_row($r)){ $skipped++; continue; } }
      $noteExtra=''; $finalAmt = _apply_spp_discount_for_period($conn,$id,$period,$amount,$noteExtra);
      $notes = 'Tagihan SPP '.$period.($noteExtra?(' '.$noteExtra):'');
      $iid = invoice_create($conn,$id,'spp',$period,$finalAmt,$dueDate,$notes);
      if($iid){ invoice_set_meta_fields($conn,$iid,['base_amount'=>$amount,'disc_note'=>$noteExtra]); }
      if($iid){ $created++; }
      else { $skipped++; }
    }
    return ['created'=>$created,'skipped'=>$skipped];
  }
}

if(!function_exists('invoice_generate_daftar_ulang_bulk')){
  /**
   * Generate DAFTAR ULANG invoices (periode kini konsisten YYYYMM seperti SPP, default bulan 07)
   * @param string $period YYYYMM
   */
  function invoice_generate_daftar_ulang_bulk(mysqli $conn, string $period, float $amount, ?string $dueDate=null): array {
    if(!preg_match('/^[0-9]{6}$/',$period)) return ['created'=>0,'skipped'=>0];
    $year = substr($period,0,4); $month = substr($period,4,2);
    $created=0; $skipped=0; $dueDate = $dueDate ?: ($year.'-'.$month.'-15');
    $res = mysqli_query($conn, "SELECT id FROM users WHERE role='wali_santri'");
    while($res && ($u=mysqli_fetch_assoc($res))){
      $id=(int)$u['id'];
      $chk = mysqli_prepare($conn,'SELECT id FROM invoice WHERE user_id=? AND type="daftar_ulang" AND period=? LIMIT 1');
      if($chk){ mysqli_stmt_bind_param($chk,'is',$id,$period); mysqli_stmt_execute($chk); $r=mysqli_stmt_get_result($chk); if($r && mysqli_fetch_row($r)){ $skipped++; continue; } }
      $iid = invoice_create($conn,$id,'daftar_ulang',$period,$amount,$dueDate,'Tagihan Daftar Ulang '.$period);
      if($iid){ $created++; } else { $skipped++; }
    }
    return ['created'=>$created,'skipped'=>$skipped];
  }
}

// === Single-user invoice generation helpers ===
if(!function_exists('invoice_generate_spp_single')){
  /**
   * Create one SPP invoice for a specific user if not already exists for the period.
   * @return array{created:int,skipped:int,invoice_id:?int}
   */
  function invoice_generate_spp_single(mysqli $conn, int $userId, string $period, float $amount, ?string $dueDate=null): array {
    if(!preg_match('/^[0-9]{6}$/',$period)) return ['created'=>0,'skipped'=>0,'invoice_id'=>null];
    $due = $dueDate ?: date('Y-m-d', strtotime(substr($period,0,4).'-'.substr($period,4,2).'-10'));
    // Skip if already exists
    $chk = mysqli_prepare($conn,'SELECT id FROM invoice WHERE user_id=? AND type="spp" AND period=? LIMIT 1');
    if($chk){ mysqli_stmt_bind_param($chk,'is',$userId,$period); mysqli_stmt_execute($chk); $r=mysqli_stmt_get_result($chk); if($r && mysqli_fetch_row($r)) return ['created'=>0,'skipped'=>1,'invoice_id'=>null]; }
  $noteExtra=''; $finalAmt=_apply_spp_discount_for_period($conn,$userId,$period,$amount,$noteExtra);
  $notes='Tagihan SPP '.$period.($noteExtra?(' '.$noteExtra):'');
  $iid = invoice_create($conn,$userId,'spp',$period,$finalAmt,$due,$notes);
  if($iid){ invoice_set_meta_fields($conn,$iid,['base_amount'=>$amount,'disc_note'=>$noteExtra]); }
    return ['created'=>$iid?1:0,'skipped'=>$iid?0:1,'invoice_id'=>$iid];
  }
}

if(!function_exists('invoice_generate_daftar_ulang_single')){
  /**
   * Create one Daftar Ulang invoice for a specific user if not already exists for the period.
   * @return array{created:int,skipped:int,invoice_id:?int}
   */
  function invoice_generate_daftar_ulang_single(mysqli $conn, int $userId, string $period, float $amount, ?string $dueDate=null): array {
    if(!preg_match('/^[0-9]{6}$/',$period)) return ['created'=>0,'skipped'=>0,'invoice_id'=>null];
    $year = substr($period,0,4); $month = substr($period,4,2);
    $due = $dueDate ?: ($year.'-'.$month.'-15');
    $chk = mysqli_prepare($conn,'SELECT id FROM invoice WHERE user_id=? AND type="daftar_ulang" AND period=? LIMIT 1');
    if($chk){ mysqli_stmt_bind_param($chk,'is',$userId,$period); mysqli_stmt_execute($chk); $r=mysqli_stmt_get_result($chk); if($r && mysqli_fetch_row($r)) return ['created'=>0,'skipped'=>1,'invoice_id'=>null]; }
    $iid = invoice_create($conn,$userId,'daftar_ulang',$period,$amount,$due,'Tagihan Daftar Ulang '.$period);
    return ['created'=>$iid?1:0,'skipped'=>$iid?0:1,'invoice_id'=>$iid];
  }
}

// Single-user: Beasiswa
if(!function_exists('invoice_generate_beasiswa_single')){
  /**
   * Create one Beasiswa invoice for a specific user if not already exists for the period (YYYYMM).
   * @return array{created:int,skipped:int,invoice_id:?int}
   */
  function invoice_generate_beasiswa_single(mysqli $conn, int $userId, string $period, float $amount, ?string $dueDate=null): array {
    if(!preg_match('/^[0-9]{6}$/',$period)) return ['created'=>0,'skipped'=>0,'invoice_id'=>null];
    $year = substr($period,0,4); $month = substr($period,4,2);
    $due = $dueDate ?: date('Y-m-d', strtotime($year.'-'.$month.'-10'));
    $chk = mysqli_prepare($conn,'SELECT id FROM invoice WHERE user_id=? AND type="beasiswa" AND period=? LIMIT 1');
    if($chk){ mysqli_stmt_bind_param($chk,'is',$userId,$period); mysqli_stmt_execute($chk); $r=mysqli_stmt_get_result($chk); if($r && mysqli_fetch_row($r)) return ['created'=>0,'skipped'=>1,'invoice_id'=>null]; }
    $iid = invoice_create($conn,$userId,'beasiswa',$period,$amount,$due,'Beasiswa '.$period);
    return ['created'=>$iid?1:0,'skipped'=>$iid?0:1,'invoice_id'=>$iid];
  }
}

if(!function_exists('payment_initiate')){
  function payment_initiate(mysqli $conn, int $userId, ?int $invoiceId, string $method, float $amount, string $idempotencyKey='', string $note=''): ?int {
  // Normalize amount to 2 decimals (avoid floating residue); reject negative/zero
  $amount = round($amount, 2);
  if($amount <= 0){ return null; }
    if($idempotencyKey){
      $chk = mysqli_prepare($conn,'SELECT id FROM payment WHERE idempotency_key=? LIMIT 1');
      if($chk){ mysqli_stmt_bind_param($chk,'s',$idempotencyKey); mysqli_stmt_execute($chk); $res = mysqli_stmt_get_result($chk); if($res && ($row=mysqli_fetch_row($res))) return (int)$row[0]; }
    } else {
        $idempotencyKey = uniqid('pay_', true);
    }
    // Detect meta_json in payment table
    static $payMetaChecked = null; static $payHasMeta = false;
    if($payMetaChecked === null){
      $payMetaChecked = true;
      $rsPayCol = @mysqli_query($conn,"SHOW COLUMNS FROM payment LIKE 'meta_json'");
      $payHasMeta = $rsPayCol && mysqli_num_rows($rsPayCol) > 0;
    }
    if($payHasMeta){
      $metaArr = [ 'source'=>'manual', 'flow'=>'payment_initiate', 'invoice_id'=>$invoiceId, 'method'=>$method ];
      $meta = json_encode($metaArr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      $stmt = mysqli_prepare($conn,'INSERT INTO payment (invoice_id,user_id,method,amount,status,idempotency_key,note,meta_json,source) VALUES (?,?,?,?,"initiated",?,?,?,?)');
      if(!$stmt) return null;
      $source='manual';
      mysqli_stmt_bind_param($stmt,'iisdssss',$invoiceId,$userId,$method,$amount,$idempotencyKey,$note,$meta,$source);
    } else {
      $stmt = mysqli_prepare($conn,'INSERT INTO payment (invoice_id,user_id,method,amount,status,idempotency_key,note) VALUES (?,?,?,?,"initiated",?,?)');
      if(!$stmt) return null;
      mysqli_stmt_bind_param($stmt,'iisdss',$invoiceId,$userId,$method,$amount,$idempotencyKey,$note);
    }
    if(!mysqli_stmt_execute($stmt)) {
        die('MySQL error: ' . mysqli_error($conn));
    }
    $pid = mysqli_insert_id($conn);
    payment_history_add($conn,$pid,null,'initiated',null,'init');
    return $pid ?: null;
  }
}

if(!function_exists('payment_update_status')){
  function payment_update_status(mysqli $conn, int $paymentId, string $toStatus, ?int $actorId=null, string $note=''): bool {
  mysqli_begin_transaction($conn);
  $stmtSel = mysqli_prepare($conn,'SELECT status, invoice_id, user_id, amount, method FROM payment WHERE id=? FOR UPDATE');
  if(!$stmtSel){ mysqli_rollback($conn); return false; }
  mysqli_stmt_bind_param($stmtSel,'i',$paymentId); mysqli_stmt_execute($stmtSel); $rs = mysqli_stmt_get_result($stmtSel); $row = $rs?mysqli_fetch_assoc($rs):null; if(!$row){ mysqli_rollback($conn); return false; }
    $from = $row['status']; if($from === $toStatus) return true; // idempotent
    $allowed = [
      'initiated'=>['awaiting_proof','awaiting_gateway','awaiting_confirmation','failed'],
      'awaiting_proof'=>['awaiting_confirmation','failed'],
      'awaiting_confirmation'=>['settled','failed'],
      'awaiting_gateway'=>['settled','failed'],
      'settled'=>['reversed'],
      'failed'=>[], 'reversed'=>[]
    ];
    if(!isset($allowed[$from]) || !in_array($toStatus,$allowed[$from],true)) return false;
    $now = date('Y-m-d H:i:s');
  // Build update statement with correct placeholder count
  if($toStatus==='settled') {
    $stmtUp = mysqli_prepare($conn,'UPDATE payment SET status=?, updated_at=?, settled_at=? WHERE id=?');
    if(!$stmtUp) return false;
    mysqli_stmt_bind_param($stmtUp,'sssi',$toStatus,$now,$now,$paymentId);
  } else {
    $stmtUp = mysqli_prepare($conn,'UPDATE payment SET status=?, updated_at=? WHERE id=?');
    if(!$stmtUp) return false;
    mysqli_stmt_bind_param($stmtUp,'ssi',$toStatus,$now,$paymentId);
  }
    $ok = mysqli_stmt_execute($stmtUp);
    if($ok){
      payment_history_add($conn,$paymentId,$from,$toStatus,$actorId,$note);
      // Side effects: ledger + invoice update when settled
      if($toStatus==='settled'){
        $invoiceId = (int)$row['invoice_id']; $amount = (float)$row['amount']; $uid=(int)$row['user_id'];
        if($invoiceId){
          if(!invoice_apply_payment($conn,$invoiceId,$amount,$actorId,'auto-settle')){ mysqli_rollback($conn); return false; }
          if(function_exists('add_notification')){ @add_notification($conn,(int)$uid,'payment_settled','Pembayaran tagihan #'.$invoiceId.' berhasil (Rp '.number_format($amount,0,',','.').')',(array)array('invoice_id'=>$invoiceId,'payment_id'=>$paymentId,'amount'=>$amount)); }
          // Ledger double-entry (non-wallet): assume CASH_IN (kas masuk) & AR_SPP (piutang) decrease
          if($row['method'] !== 'wallet'){
            // Debit CASH_IN, Credit AR_SPP
            if(!ledger_post($conn,$uid,'CASH_IN',$amount,0,'payment',$paymentId,'Manual settle') || !ledger_post($conn,$uid,'AR_SPP',0,$amount,'payment',$paymentId,'Reduce AR')){
              mysqli_rollback($conn); return false;
            }
          }
        } else {
          // Top-up wallet: credit WALLET
          if(!ledger_post($conn,$uid,'WALLET',$amount,0,'payment',$paymentId,'Top-up settled')){ mysqli_rollback($conn); return false; }
          if(function_exists('add_notification')){ @add_notification($conn,(int)$uid,'wallet_topup_settled','Top-up wallet berhasil Rp '.number_format($amount,0,',','.'),(array)array('payment_id'=>$paymentId,'amount'=>$amount)); }
        }
      }
    }
    if($ok){ mysqli_commit($conn); } else { mysqli_rollback($conn); }
    return $ok;
  }
}

if(!function_exists('invoice_apply_payment')){
  function invoice_apply_payment(mysqli $conn, int $invoiceId, float $amount, ?int $actorId=null, string $note=''): bool {
  $amount = round($amount,2);
    // optimistic update
  $stmt = mysqli_prepare($conn,'SELECT amount, paid_amount, status, user_id FROM invoice WHERE id=? FOR UPDATE');
  if(!$stmt) return false; mysqli_stmt_bind_param($stmt,'i',$invoiceId); mysqli_stmt_execute($stmt); $rs=mysqli_stmt_get_result($stmt); $inv=$rs?mysqli_fetch_assoc($rs):null; if(!$inv) return false;
  $newPaid = (float)$inv['paid_amount'] + $amount;
  if($newPaid > (float)$inv['amount']) { $newPaid = (float)$inv['amount']; }
  $status = derive_invoice_status($inv['status'], (float)$inv['amount'], $newPaid, $inv['due_date'] ?? null);
    $upd = mysqli_prepare($conn,'UPDATE invoice SET paid_amount=?, status=?, updated_at=NOW() WHERE id=?');
    if(!$upd) return false; mysqli_stmt_bind_param($upd,'dsi',$newPaid,$status,$invoiceId); $ok = mysqli_stmt_execute($upd);
    if($ok){ invoice_history_add($conn,$invoiceId,$inv['status'],$status,$actorId,$note); }
    return $ok;
  }
}

if(!function_exists('invoice_history_add')){
  function invoice_history_add(mysqli $conn,int $invoiceId,?string $from,string $to,?int $actorId,string $note=''):void {
    $stmt = mysqli_prepare($conn,'INSERT INTO invoice_history (invoice_id,from_status,to_status,actor_id,note) VALUES (?,?,?,?,?)');
    if($stmt){ mysqli_stmt_bind_param($stmt,'issis',$invoiceId,$from,$to,$actorId,$note); mysqli_stmt_execute($stmt);} }
}
if(!function_exists('payment_history_add')){
  function payment_history_add(mysqli $conn,int $paymentId,?string $from,string $to,?int $actorId,string $note=''):void {
    $stmt = mysqli_prepare($conn,'INSERT INTO payment_history (payment_id,from_status,to_status,actor_id,note) VALUES (?,?,?,?,?)');
    if($stmt){ mysqli_stmt_bind_param($stmt,'issis',$paymentId,$from,$to,$actorId,$note); mysqli_stmt_execute($stmt);} }
}

// === Invoice meta helpers ===
if(!function_exists('invoice_set_meta_fields')){
  function invoice_set_meta_fields(mysqli $conn,int $invoiceId,array $fields): bool {
    if(!$fields) return true;
    // Check meta_json column exists
    static $hasMeta=null; if($hasMeta===null){ $r=@mysqli_query($conn,"SHOW COLUMNS FROM invoice LIKE 'meta_json'"); $hasMeta = $r && mysqli_num_rows($r)>0; }
    if(!$hasMeta) return false;
    // Load existing meta
    $stmtSel = mysqli_prepare($conn,'SELECT meta_json FROM invoice WHERE id=? LIMIT 1'); if(!$stmtSel) return false;
    mysqli_stmt_bind_param($stmtSel,'i',$invoiceId); mysqli_stmt_execute($stmtSel); $rs=mysqli_stmt_get_result($stmtSel); $row=$rs?mysqli_fetch_row($rs):null; $metaArr=[];
    if($row && $row[0]){ $decoded=json_decode($row[0],true); if(is_array($decoded)) $metaArr=$decoded; }
    foreach($fields as $k=>$v){ $metaArr[$k]=$v; }
    $json = json_encode($metaArr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $upd = mysqli_prepare($conn,'UPDATE invoice SET meta_json=?, updated_at=NOW() WHERE id=?'); if(!$upd) return false;
    mysqli_stmt_bind_param($upd,'si',$json,$invoiceId); return mysqli_stmt_execute($upd)===true;
  }
}

// Recalculate SPP invoice amounts for a user in a given year based on current beasiswa settings
if(!function_exists('spp_recalc_user_for_year')){
  function spp_recalc_user_for_year(mysqli $conn,int $userId,int $year): array {
    $updated=0; $skipped=0; $affected=[];
    $y = (int)$year; if($y<2000 || $y>3000) return ['updated'=>0,'skipped'=>0];
    $yPrefix = (string)$y;
    $q = mysqli_prepare($conn, "SELECT id, period, amount, paid_amount, status, meta_json FROM invoice WHERE user_id=? AND type='spp' AND period LIKE CONCAT(?, '%')");
    if(!$q) return ['updated'=>0,'skipped'=>0];
    mysqli_stmt_bind_param($q,'is',$userId,$yPrefix); mysqli_stmt_execute($q); $rs=mysqli_stmt_get_result($q);
    while($rs && ($row=mysqli_fetch_assoc($rs))){
      $id=(int)$row['id']; $status=$row['status']; if(!in_array($status,['pending','partial'],true)){ $skipped++; continue; }
      $period=trim((string)$row['period']); $meta=json_decode($row['meta_json']??'',true);
      $base = (isset($meta['base_amount']) && is_numeric($meta['base_amount'])) ? (float)$meta['base_amount'] : (float)$row['amount'];
      $noteExtra=''; $newAmt=_apply_spp_discount_for_period($conn,$userId,$period,$base,$noteExtra);
      $curAmt=(float)$row['amount']; if(abs($newAmt-$curAmt) < 0.01){ $skipped++; continue; }
      $upd=mysqli_prepare($conn,'UPDATE invoice SET amount=?, updated_at=NOW() WHERE id=? LIMIT 1'); if(!$upd){ $skipped++; continue; }
      mysqli_stmt_bind_param($upd,'di',$newAmt,$id); if(mysqli_stmt_execute($upd)){ $updated++; $affected[]=$id; invoice_set_meta_fields($conn,$id,['disc_note'=>$noteExtra,'base_amount'=>$base]); }
    }
    return ['updated'=>$updated,'skipped'=>$skipped,'ids'=>$affected];
  }
}

// Utility: compute wallet balance from ledger (fallback if view not available)
if(!function_exists('wallet_balance')){
  function wallet_balance(mysqli $conn,int $userId): float {
  // Prefer cached saldo field if fairly recent? For simplicity recompute each call (small scale)
  $stmt = mysqli_prepare($conn,'SELECT COALESCE(SUM(debit-credit),0) s FROM ledger_entries WHERE user_id=? AND account="WALLET"');
  if(!$stmt) return 0.0; mysqli_stmt_bind_param($stmt,'i',$userId); mysqli_stmt_execute($stmt); $rs=mysqli_stmt_get_result($stmt); $row=$rs?mysqli_fetch_row($rs):[0]; return (float)$row[0];
  }
}

// Pay invoice using wallet balance (atomic best-effort)
if(!function_exists('wallet_pay_invoice')){
  /**
   * Attempt to pay (full or partial) invoice using wallet balance.
   * @param float|null $amount If null, auto use remaining (min(wallet, remaining)).
   * @return array [ok=>bool, msg=>string]
   */
  function wallet_pay_invoice(mysqli $conn,int $invoiceId,int $userId,?float $amount=null): array {
    mysqli_begin_transaction($conn);
    $stmt = mysqli_prepare($conn,'SELECT id, user_id, amount, paid_amount, status FROM invoice WHERE id=? AND user_id=? FOR UPDATE');
    if(!$stmt){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'DB error']; }
    mysqli_stmt_bind_param($stmt,'ii',$invoiceId,$userId); mysqli_stmt_execute($stmt); $rs = mysqli_stmt_get_result($stmt); $inv = $rs?mysqli_fetch_assoc($rs):null;
    if(!$inv){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Invoice tidak ditemukan']; }
    if(in_array($inv['status'],['paid','canceled'])){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Invoice sudah tidak bisa dibayar']; }
    $remaining = (float)$inv['amount'] - (float)$inv['paid_amount']; if($remaining <= 0){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Tidak ada sisa']; }
    $balance = wallet_balance($conn,$userId);
    if($amount === null){ $amount = min($balance,$remaining); }
  $amount = round((float)$amount,2);
    if($amount <= 0){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Nominal tidak valid']; }
    if($amount > $remaining + 0.0001){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Lebih dari sisa tagihan']; }
    if($amount > $balance + 0.0001){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Saldo wallet tidak cukup']; }
    // Create payment settled instantly
    $now = date('Y-m-d H:i:s');
  // Insert payment settled instantly
  $pstmt = mysqli_prepare($conn,'INSERT INTO payment (invoice_id,user_id,method,amount,status,settled_at,updated_at,note) VALUES (?,?,"wallet",?,"settled",?,?,"wallet auto")');
  if(!$pstmt){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Gagal create payment']; }
  mysqli_stmt_bind_param($pstmt,'iidss',$invoiceId,$userId,$amount,$now,$now);
    if(!mysqli_stmt_execute($pstmt)){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Insert payment gagal']; }
    $pid = mysqli_insert_id($conn);
    payment_history_add($conn,$pid,null,'settled',$userId,'wallet pay');
    // Ledger entries: credit wallet (reduce), debit AR_SPP (reduce asset) - simplistic
    ledger_post($conn,$userId,'WALLET',0,$amount,'payment',$pid,'Pay invoice via wallet');
    ledger_post($conn,$userId,'AR_SPP',$amount,0,'payment',$pid,'Settle AR via wallet');
    // Apply to invoice
    if(!invoice_apply_payment($conn,$invoiceId,$amount,$userId,'wallet pay')){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Update invoice gagal']; }
    mysqli_commit($conn);
  if(function_exists('add_notification')){ @add_notification($conn,(int)$userId,'wallet_invoice_payment','Pembayaran wallet untuk invoice #'.$invoiceId.' Rp '.number_format($amount,0,',','.'),(array)array('invoice_id'=>$invoiceId,'payment_id'=>$pid,'amount'=>$amount)); }
  return ['ok'=>true,'msg'=>'Pembayaran wallet berhasil (#'.$pid.')'];
  }
}

if(!function_exists('payments_random_name')){
  function payments_random_name(string $prefix, string $ext): string {
    return $prefix . '_' . bin2hex(random_bytes(8)) . '_' . time() . '.' . strtolower(preg_replace('/[^a-z0-9]+/i','',$ext));
  }
}

// Mark overdue invoices (due_date < today) transitioning pending/partial -> overdue
if(!function_exists('invoice_mark_overdue_bulk')){
  /**
   * @return array [updated=>int]
   */
  function invoice_mark_overdue_bulk(mysqli $conn): array {
    $today = date('Y-m-d');
    $rows=[]; $res = mysqli_query($conn, "SELECT id, user_id, status FROM invoice WHERE due_date IS NOT NULL AND due_date < '".mysqli_real_escape_string($conn,$today)."' AND status IN ('pending','partial') LIMIT 2000");
    while($res && $row=mysqli_fetch_assoc($res)) $rows[]=$row;
    if(!$rows) return ['updated'=>0];
    $ids = array_map(fn($r)=>(int)$r['id'],$rows);
    $idList = implode(',',$ids);
    $ok = mysqli_query($conn, "UPDATE invoice SET status='overdue', updated_at=NOW() WHERE id IN ($idList)");
    if($ok){
      foreach($rows as $r){
        invoice_history_add($conn,(int)$r['id'],$r['status'],'overdue',null,'auto overdue');
  if(function_exists('add_notification')){ @add_notification($conn,(int)$r['user_id'],'invoice_overdue','Invoice #'.$r['id'].' melewati jatuh tempo',(array)array('invoice_id'=>(int)$r['id'])); }
      }
      return ['updated'=>count($ids)];
    }
    return ['updated'=>0];
  }
}

// Revert (reverse) a settled payment: adjust invoice and ledger
if(!function_exists('invoice_revert_payment')){
  function invoice_revert_payment(mysqli $conn,int $invoiceId,float $amount,?int $actorId=null,string $note='reversal'): bool {
    $stmt = mysqli_prepare($conn,'SELECT amount, paid_amount, status, due_date FROM invoice WHERE id=? LIMIT 1');
    if(!$stmt) return false; mysqli_stmt_bind_param($stmt,'i',$invoiceId); mysqli_stmt_execute($stmt); $rs=mysqli_stmt_get_result($stmt); $inv=$rs?mysqli_fetch_assoc($rs):null; if(!$inv) return false;
  $newPaid = (float)$inv['paid_amount'] - $amount; if($newPaid < 0) $newPaid = 0.0;
  if($newPaid > (float)$inv['amount']) $newPaid = (float)$inv['amount'];
  $newStatus = derive_invoice_status($inv['status'], (float)$inv['amount'], $newPaid, $inv['due_date'] ?? null);
    $upd = mysqli_prepare($conn,'UPDATE invoice SET paid_amount=?, status=?, updated_at=NOW() WHERE id=?');
    if(!$upd) return false; mysqli_stmt_bind_param($upd,'dsi',$newPaid,$newStatus,$invoiceId); $ok=mysqli_stmt_execute($upd);
    if($ok) invoice_history_add($conn,$invoiceId,$inv['status'],$newStatus,$actorId,$note);
    return $ok;
  }
}

if(!function_exists('payment_reversal')){
  /**
   * Reverse a settled payment (idempotent if already reversed)
   * @return array [ok=>bool,msg=>string]
   */
  function payment_reversal(mysqli $conn,int $paymentId,?int $actorId=null,string $reason='reversal'): array {
    mysqli_begin_transaction($conn);
    $stmt = mysqli_prepare($conn,'SELECT * FROM payment WHERE id=? FOR UPDATE');
    if(!$stmt){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'DB error']; }
    mysqli_stmt_bind_param($stmt,'i',$paymentId); mysqli_stmt_execute($stmt); $rs=mysqli_stmt_get_result($stmt); $p=$rs?mysqli_fetch_assoc($rs):null; if(!$p){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Payment tidak ditemukan']; }
    if($p['status']==='reversed'){ mysqli_commit($conn); return ['ok'=>true,'msg'=>'Sudah reversed']; }
    if($p['status']!=='settled'){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Hanya payment settled bisa direverse']; }
    $uid=(int)$p['user_id']; $amount=(float)$p['amount']; $invoiceId = $p['invoice_id'] ? (int)$p['invoice_id'] : null; $method=$p['method'];
    // Lock invoice row if present
    if($invoiceId){
        $lockInv = mysqli_prepare($conn,'SELECT id FROM invoice WHERE id=? FOR UPDATE');
        if($lockInv){ mysqli_stmt_bind_param($lockInv,'i',$invoiceId); mysqli_stmt_execute($lockInv); mysqli_stmt_get_result($lockInv); }
    }
    // Adjust invoice if linked
    if($invoiceId){ if(!invoice_revert_payment($conn,$invoiceId,$amount,$actorId,'payment reversal')) { mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Gagal adjust invoice']; } }
    // Ledger reversal based on original side effects (check success)
    $ledgerOk = true;
    if(!$invoiceId){
      $ledgerOk = ledger_post($conn,$uid,'WALLET',0,$amount,'payment',$paymentId,'Top-up reversal');
    } elseif($method==='wallet') {
      $ledgerOk = ledger_post($conn,$uid,'WALLET',$amount,0,'payment',$paymentId,'Wallet pay reversal')
        && ledger_post($conn,$uid,'AR_SPP',0,$amount,'payment',$paymentId,'AR reversal');
    } else {
      $ledgerOk = ledger_post($conn,$uid,'CASH_IN',0,$amount,'payment',$paymentId,'Manual settle reversal')
        && ledger_post($conn,$uid,'AR_SPP',$amount,0,'payment',$paymentId,'AR restore');
    }
    if(!$ledgerOk){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Gagal ledger']; }
    // Update payment status
    $up = mysqli_prepare($conn,'UPDATE payment SET status="reversed", updated_at=NOW() WHERE id=?');
    if(!$up){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Gagal update payment']; }
    mysqli_stmt_bind_param($up,'i',$paymentId); if(!mysqli_stmt_execute($up)){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Gagal simpan']; }
    payment_history_add($conn,$paymentId,'settled','reversed',$actorId,$reason);
    mysqli_commit($conn);
  if(function_exists('add_notification')){ @add_notification($conn,(int)$uid,'payment_reversed','Payment #'.$paymentId.' dibatalkan'.($invoiceId?' (invoice #'.$invoiceId.')':''),(array)array('payment_id'=>$paymentId,'invoice_id'=>$invoiceId,'amount'=>$amount)); }
  return ['ok'=>true,'msg'=>'Payment #'.$paymentId.' berhasil direverse'];
  }
}

if(!function_exists('payment_confirm')){
  /**
   * Konfirmasi pembayaran (transition awaiting_confirmation -> settled)
   * Terapkan seluruh side-effect (invoice paid_amount, history, ledger, notifikasi)
   * Aman dipanggil ulang: jika sudah settled akan return ok=false dengan pesan.
   */
  function payment_confirm(mysqli $conn, int $paymentId, int $adminId, string $note='admin confirm'): array {
    if($paymentId<=0 || $adminId<=0) return ['ok'=>false,'msg'=>'Data tidak valid'];
    mysqli_begin_transaction($conn);
    // Lock payment row
    $sel = mysqli_prepare($conn,'SELECT id, status, invoice_id, user_id, amount, method FROM payment WHERE id=? FOR UPDATE');
    if(!$sel){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'DB error']; }
    mysqli_stmt_bind_param($sel,'i',$paymentId); mysqli_stmt_execute($sel); $rs=mysqli_stmt_get_result($sel); $p=$rs?mysqli_fetch_assoc($rs):null;
    if(!$p){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Payment tidak ditemukan']; }
    if($p['status']==='settled'){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Sudah settled']; }
    if($p['status']!=='awaiting_confirmation'){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Status tidak bisa dikonfirmasi']; }
    $now=date('Y-m-d H:i:s');
    // Update status payment -> settled
    $up = mysqli_prepare($conn,'UPDATE payment SET status="settled", updated_at=?, settled_at=?, note=? WHERE id=? AND status="awaiting_confirmation"');
    if(!$up){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Gagal update payment']; }
    mysqli_stmt_bind_param($up,'sssi',$now,$now,$note,$paymentId);
    if(!mysqli_stmt_execute($up) || mysqli_stmt_affected_rows($up)===0){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Payment tidak ditemukan / sudah berubah']; }
    payment_history_add($conn,$paymentId,'awaiting_confirmation','settled',$adminId,$note);
    $invoiceId = (int)$p['invoice_id']; $amount=(float)$p['amount']; $uid=(int)$p['user_id'];
    // Side effects: invoice & ledger
    if($invoiceId){
      // Lock invoice row for update
      $lock = mysqli_prepare($conn,'SELECT id FROM invoice WHERE id=? FOR UPDATE'); if($lock){ mysqli_stmt_bind_param($lock,'i',$invoiceId); mysqli_stmt_execute($lock); mysqli_stmt_get_result($lock); }
      if(!invoice_apply_payment($conn,$invoiceId,$amount,$adminId,'confirm')){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Gagal apply ke invoice']; }
      // Ledger (non-wallet): debit CASH_IN, credit AR_SPP (accounting sederhana)
      if($p['method']!=='wallet'){
        if(!ledger_post($conn,$uid,'CASH_IN',$amount,0,'payment',$paymentId,'Confirm settle') || !ledger_post($conn,$uid,'AR_SPP',0,$amount,'payment',$paymentId,'Reduce AR')){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Ledger gagal']; }
      } else {
        // Jika metode wallet seharusnya jalur konfirmasi tidak dipakai (wallet auto-settle), tapi handle noop.
      }
    } else {
      // Top-up wallet: tambah saldo (ledger WALLET debit)
      if(!ledger_post($conn,$uid,'WALLET',$amount,0,'payment',$paymentId,'Wallet topup confirm')){ mysqli_rollback($conn); return ['ok'=>false,'msg'=>'Ledger gagal']; }
    }
    mysqli_commit($conn);
    if(function_exists('add_notification')){
      @add_notification($conn,$uid,'payment_settled','Pembayaran dikonfirmasi (#'.$paymentId.') Rp '.number_format($amount,0,',','.'),(array)array('payment_id'=>$paymentId,'invoice_id'=>$invoiceId,'amount'=>$amount));
    }
    return ['ok'=>true,'msg'=>'Payment dikonfirmasi'];
  }
}
