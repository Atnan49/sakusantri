<?php
// wallet_sync.php - helper to reconcile users.saldo with ledger_entries WALLET movements
if(!function_exists('wallet_recalc_all')){
    /**
     * Recalculate users.saldo from ledger_entries (account=WALLET) for all wali_santri.
     * Only updates rows where mismatch detected to keep it lightweight.
     * @return array{ok:bool,diffs:int,updated:int,ts:string}
     */
    function wallet_recalc_all(mysqli $conn): array {
        $sql = "SELECT u.id,u.saldo AS stored, COALESCE(SUM(le.debit-le.credit),0) AS ledger_bal
                FROM users u
                LEFT JOIN ledger_entries le ON le.user_id=u.id AND le.account='WALLET'
                WHERE u.role='wali_santri'
                GROUP BY u.id";
        $res = mysqli_query($conn,$sql); if(!$res) return ['ok'=>false,'diffs'=>0,'updated'=>0,'ts'=>date('c')];
        $diffs=0;$updated=0;
        while($row=mysqli_fetch_assoc($res)){
            $ledger=(int)$row['ledger_bal']; $stored=(int)$row['stored']; $uid=(int)$row['id'];
            if($ledger!==$stored){
                $diffs++;
                $stmt = mysqli_prepare($conn,'UPDATE users SET saldo=? WHERE id=?');
                if($stmt){ mysqli_stmt_bind_param($stmt,'ii',$ledger,$uid); if(mysqli_stmt_execute($stmt)) $updated++; }
            }
        }
        return ['ok'=>true,'diffs'=>$diffs,'updated'=>$updated,'ts'=>date('c')];
    }
}

if(!function_exists('wallet_recalc_user')){
    /** Recalculate a single user's saldo from ledger WALLET */
    function wallet_recalc_user(mysqli $conn,int $userId): bool {
        $stmt = mysqli_prepare($conn,'SELECT COALESCE(SUM(debit-credit),0) s FROM ledger_entries WHERE user_id=? AND account="WALLET"');
        if(!$stmt) return false; mysqli_stmt_bind_param($stmt,'i',$userId); mysqli_stmt_execute($stmt); $rs=mysqli_stmt_get_result($stmt); $row=$rs?mysqli_fetch_assoc($rs):['s'=>0];
        $saldo = (int)($row['s'] ?? 0);
        $up = mysqli_prepare($conn,'UPDATE users SET saldo=? WHERE id=?'); if(!$up) return false; mysqli_stmt_bind_param($up,'ii',$saldo,$userId); return mysqli_stmt_execute($up)===true;
    }
}
