<?php
// Simple notification helper
function add_notification(mysqli $conn, ?int $userId, string $type, string $message, ?array $data=null): void {
    $hasData = false;
    // Feature detection: check column existence once per request (cache in static)
    static $colChecked = null; static $colHas = false;
    if($colChecked === null){
        $colChecked = true;
        $rs = @mysqli_query($conn, "SHOW COLUMNS FROM notifications LIKE 'data_json'");
        $colHas = $rs && mysqli_num_rows($rs) > 0;
    }
    if($colHas && $data !== null){ $hasData = true; }
    if($hasData){
        $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id,type,message,data_json) VALUES (?,?,?,?)");
        if($stmt){ mysqli_stmt_bind_param($stmt,'isss',$userId,$type,$message,$json); mysqli_stmt_execute($stmt); }
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id,type,message) VALUES (?,?,?)");
        if($stmt){ mysqli_stmt_bind_param($stmt,'iss',$userId,$type,$message); mysqli_stmt_execute($stmt); }
    }
}
function fetch_notifications(mysqli $conn, ?int $userId=null, int $limit=30): array {
    $rows=[]; $sql = "SELECT * FROM notifications ".($userId?"WHERE (user_id IS NULL OR user_id=$userId)":"")." ORDER BY id DESC LIMIT $limit"; $rs=mysqli_query($conn,$sql); while($rs && $r=mysqli_fetch_assoc($rs)){$rows[]=$r;} return $rows;
}
function mark_notification_read(mysqli $conn, int $id): void { @mysqli_query($conn, "UPDATE notifications SET read_at=NOW() WHERE id=".(int)$id." AND read_at IS NULL"); }

// Optional auto-clean: remove notifications older than retention window.
// Call occasionally (e.g., from init or a cron-triggered endpoint) with desired days.
function cleanup_notifications(mysqli $conn, int $readRetentionDays = 90, int $maxAgeDays = 180): void {
    $readRetentionDays = max(7, $readRetentionDays); // minimum safeguards
    $maxAgeDays = max($readRetentionDays, $maxAgeDays);
    // Delete read notifications older than readRetentionDays OR anything older than maxAgeDays
    $sql = "DELETE FROM notifications WHERE (read_at IS NOT NULL AND read_at < (NOW() - INTERVAL ? DAY)) OR created_at < (NOW() - INTERVAL ? DAY)";
    if($stmt = mysqli_prepare($conn,$sql)){
        mysqli_stmt_bind_param($stmt,'ii',$readRetentionDays,$maxAgeDays);
        mysqli_stmt_execute($stmt);
    }
}
