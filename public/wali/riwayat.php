<?php
require_once __DIR__ . '/../../src/includes/init.php';
require_once __DIR__ . '/../includes/session_check.php';
require_role('wali_santri');
require_once BASE_PATH.'/src/includes/payments.php';
$uid = (int)($_SESSION['user_id'] ?? 0);

// Query parameters
$tab = $_GET['tab'] ?? 'invoices';
if(!in_array($tab,['invoices','topups','wallet_ledger'],true)) $tab='invoices';

// Pagination basics
$perPage = 30; $page = max(1,(int)($_GET['p'] ?? 1)); $offset = ($page-1)*$perPage;

$invoices=[]; $invoiceTotal=0; $topups=[]; $topupTotal=0; $ledger=[]; $ledgerTotal=0; $opening=0.0;

if($tab==='invoices'){
	$rs = mysqli_query($conn,"SELECT SQL_CALC_FOUND_ROWS id, period, amount, paid_amount, status, created_at, due_date FROM invoice WHERE user_id=$uid AND type='spp' ORDER BY id DESC LIMIT $perPage OFFSET $offset");
	while($rs && $r=mysqli_fetch_assoc($rs)) $invoices[]=$r;
	$cnt = mysqli_query($conn,'SELECT FOUND_ROWS()'); $invoiceTotal = $cnt? (int)mysqli_fetch_row($cnt)[0]:0;
}
elseif($tab==='topups'){
	$rs = mysqli_query($conn,"SELECT SQL_CALC_FOUND_ROWS id, amount, status, created_at, proof_file FROM payment WHERE user_id=$uid AND invoice_id IS NULL ORDER BY id DESC LIMIT $perPage OFFSET $offset");
	while($rs && $r=mysqli_fetch_assoc($rs)) $topups[]=$r;
	$cnt = mysqli_query($conn,'SELECT FOUND_ROWS()'); $topupTotal = $cnt? (int)mysqli_fetch_row($cnt)[0]:0;
}
else { // wallet_ledger
	// Ascending for running balance; fetch subset
	$rs = mysqli_query($conn,"SELECT SQL_CALC_FOUND_ROWS id,debit,credit,note,created_at,ref_type,ref_id FROM ledger_entries WHERE user_id=$uid AND account='WALLET' ORDER BY id ASC LIMIT $perPage OFFSET $offset");
	while($rs && $r=mysqli_fetch_assoc($rs)) $ledger[]=$r;
	$cnt = mysqli_query($conn,'SELECT FOUND_ROWS()'); $ledgerTotal = $cnt? (int)mysqli_fetch_row($cnt)[0]:0;
	if($offset>0 && $ledger){
		$firstId = (int)$ledger[0]['id'];
		$rsO = mysqli_query($conn,"SELECT COALESCE(SUM(debit-credit),0) FROM ledger_entries WHERE user_id=$uid AND account='WALLET' AND id < $firstId");
		if($rsO){ $opening=(float)mysqli_fetch_row($rsO)[0]; }
	}
	$run=$opening; foreach($ledger as &$L){ $run += (float)$L['debit'] - (float)$L['credit']; $L['running']=$run; } unset($L);
}

function human_status_invoice($s){
	return match($s){
		'pending'=>'Belum Dibayar','partial'=>'Sebagian','paid'=>'Lunas','overdue'=>'Terlambat','canceled'=>'Dibatalkan', default => ucfirst($s)
	}; }
function human_status_topup($s){
	return match($s){
		'initiated'=>'Inisiasi','awaiting_proof'=>'Upload Bukti','awaiting_confirmation'=>'Menunggu','settled'=>'Berhasil','failed'=>'Gagal','reversed'=>'Reversed', default=>ucfirst($s)
	}; }

$totalPages = 1;
if($tab==='invoices') $totalPages = max(1,(int)ceil($invoiceTotal/$perPage));
elseif($tab==='topups') $totalPages = max(1,(int)ceil($topupTotal/$perPage));
else $totalPages = max(1,(int)ceil($ledgerTotal/$perPage));

require_once BASE_PATH.'/src/includes/header.php';
?>
<div class="history-page" style="padding-bottom:60px">
	<h1 class="wali-page-title" style="margin:4px 0 18px">Riwayat</h1>
	<div class="tab-bar" style="display:flex;gap:10px;margin:0 0 18px;flex-wrap:wrap">
		<?php $mk = function($t,$lbl) use($tab){ $cls = ($t===$tab)?'btn-tab active':'btn-tab'; echo '<a class="'.$cls.'" href="?tab='.$t.'">'.htmlspecialchars($lbl).'</a>'; }; ?>
		<?php $mk('invoices','Invoice SPP'); $mk('topups','Top-Up Wallet'); $mk('wallet_ledger','Ledger Wallet'); ?>
	</div>

	<?php if($tab==='invoices'): ?>
		<div class="panel">
			<h2 style="margin:0 0 14px;font-size:18px">Invoice SPP</h2>
			<div class="table-wrap" style="overflow-x:auto">
				<table class="table" style="min-width:720px">
					<thead><tr><th>ID</th><th>Periode</th><th>Nominal</th><th>Dibayar</th><th>Status</th><th>Jatuh Tempo</th><th>Dibuat</th><th>Aksi</th></tr></thead>
					<tbody>
					<?php if(!$invoices): ?><tr><td colspan="8" style="text-align:center;font-size:12px;color:#777">Tidak ada data.</td></tr><?php else: foreach($invoices as $iv): ?>
						<tr>
							<td>#<?= (int)$iv['id'] ?></td>
							<td><?= e($iv['period']) ?></td>
							<td>Rp <?= number_format($iv['amount'],0,',','.') ?></td>
							<td>Rp <?= number_format($iv['paid_amount'],0,',','.') ?></td>
							<td><span class="status-<?= e($iv['status']) ?>"><?= e(human_status_invoice($iv['status'])) ?></span></td>
							<td><?= e($iv['due_date']) ?></td>
							<td><?= e($iv['created_at']) ?></td>
							<td><?php if(in_array($iv['status'],['pending','partial'])): ?><a href="invoice_detail.php?id=<?= (int)$iv['id'] ?>" class="btn-action small" style="padding:4px 10px;font-size:11px">Bayar</a><?php else: ?><span style="font-size:11px;color:#666">-</span><?php endif; ?></td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php elseif($tab==='topups'): ?>
		<div class="panel">
			<h2 style="margin:0 0 14px;font-size:18px">Top-Up Wallet</h2>
			<div class="table-wrap" style="overflow-x:auto">
				<table class="table" style="min-width:680px">
					<thead><tr><th>ID</th><th>Nominal</th><th>Status</th><th>Bukti</th><th>Dibuat</th></tr></thead>
					<tbody>
					<?php if(!$topups): ?><tr><td colspan="5" style="text-align:center;font-size:12px;color:#777">Tidak ada data.</td></tr><?php else: foreach($topups as $tp): ?>
						<tr>
							<td>#<?= (int)$tp['id'] ?></td>
							<td>Rp <?= number_format($tp['amount'],0,',','.') ?></td>
							<td><span class="status-<?= e($tp['status']) ?>"><?= e(human_status_topup($tp['status'])) ?></span></td>
							<td style="font-size:11px"><?php if($tp['proof_file']): $pf = urlencode($tp['proof_file']); ?><a href="<?= url('bukti/f/'.$pf) ?>" target="_blank" rel="noopener">Lihat</a><?php else: ?>-<?php endif; ?></td>
							<td><?= e($tp['created_at']) ?></td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php else: ?>
		<div class="panel">
			<h2 style="margin:0 0 14px;font-size:18px">Riwayat Wallet </h2>
			<div style="font-size:12px;color:#666;margin:0 0 10px">Halaman <?= $page ?> / <?= $totalPages ?>. Opening balance: Rp <?= number_format($opening,0,',','.') ?>.</div>
			<div class="table-wrap" style="overflow-x:auto">
				<table class="table" style="min-width:760px">
					<thead><tr><th>ID</th><th>Tanggal</th><th>Debit</th><th>Credit</th><th>Saldo</th><th>Ref</th><th>Catatan</th></tr></thead>
					<tbody>
					<?php if(!$ledger): ?><tr><td colspan="7" style="text-align:center;font-size:12px;color:#777">Tidak ada data.</td></tr><?php else: foreach($ledger as $L): ?>
						<tr>
							<td>#<?= (int)$L['id'] ?></td>
							<td><?= e(date('d M Y H:i',strtotime($L['created_at']))) ?></td>
							<td><?= $L['debit']>0?('Rp '.number_format($L['debit'],0,',','.')):'-' ?></td>
							<td><?= $L['credit']>0?('Rp '.number_format($L['credit'],0,',','.')):'-' ?></td>
							<td>Rp <?= number_format($L['running'],0,',','.') ?></td>
							<td style="font-size:11px"><?php if($L['ref_type']==='payment'): ?>Payment #<?= (int)$L['ref_id'] ?><?php elseif($L['ref_type']): ?><?= e($L['ref_type']) ?><?php else: ?>-<?php endif; ?></td>
							<td style="font-size:11px;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= e($L['note'] ?? '') ?>"><?= e($L['note'] ?? '') ?></td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>

	<?php if($totalPages>1): ?>
		<div style="margin:18px 0 0;display:flex;gap:8px;flex-wrap:wrap;font-size:12px">
			<?php if($page>1): ?><a class="btn-action outline" href="?tab=<?= e($tab) ?>&p=<?= $page-1 ?>">&larr; Prev</a><?php endif; ?>
			<?php if($page<$totalPages): ?><a class="btn-action outline" href="?tab=<?= e($tab) ?>&p=<?= $page+1 ?>">Next &rarr;</a><?php endif; ?>
			<span style="padding:6px 8px;color:#555">Halaman <?= $page ?> / <?= $totalPages ?></span>
		</div>
	<?php endif; ?>
</div>
<?php require_once BASE_PATH.'/src/includes/footer.php'; ?>




