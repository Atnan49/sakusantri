<?php
// Simple audit logging utilities
// Table expected: audit_log(id INT AI, user_id INT NULL, action VARCHAR(64), entity_type VARCHAR(32), entity_id INT NULL, detail TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)

function audit_log(mysqli $conn, ?int $userId, string $action, string $entityType, ?int $entityId, array $extra = []): void {
    $detail = json_encode(['extra'=>$extra,'ip'=>($_SERVER['REMOTE_ADDR'] ?? null),'ua'=>($_SERVER['HTTP_USER_AGENT'] ?? null)]);
    $stmt = mysqli_prepare($conn, 'INSERT INTO audit_log(user_id,action,entity_type,entity_id,detail) VALUES (?,?,?,?,?)');
    if($stmt){ mysqli_stmt_bind_param($stmt,'issis',$userId,$action,$entityType,$entityId,$detail); mysqli_stmt_execute($stmt); }
}
