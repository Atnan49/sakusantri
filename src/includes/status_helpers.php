<?php
// Centralized status & badge helper utilities
// filepath: c:/xampp/htdocs/saku_santri/src/includes/status_helpers.php
if(!function_exists('render_status_badge')){
    /**
     * Render a standardized status badge span.
     * Known statuses: menunggu_pembayaran, menunggu_konfirmasi, lunas, ditolak
     */
    function render_status_badge(string $status): string {
        $normalized = strtolower(trim($status));
        $cls = 'status-'.str_replace('_','-',$normalized);
        $label = ucwords(str_replace('_',' ', $normalized));
        return '<span class="'.$cls.'">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</span>';
    }
}
if(!function_exists('human_status')){
    function human_status(string $status): string {
        return ucwords(str_replace('_',' ', strtolower($status)));
    }
}

// Indonesian label helpers (do not change DB values)
if(!function_exists('t_status_invoice')){
    /**
     * Translate invoice status to Indonesian human label
     * pending | partial | paid | overdue | canceled
     */
    function t_status_invoice(string $status): string {
        $st = strtolower(trim($status));
        switch($st){
            case 'pending': return 'Menunggu';
            case 'partial': return 'Sebagian';
            case 'paid': return 'Lunas';
            case 'overdue': return 'Terlambat';
            case 'canceled': return 'Dibatalkan';
            default: return human_status($status);
        }
    }
}
if(!function_exists('t_status_payment')){
    /**
     * Translate payment status to Indonesian human label
     * initiated | awaiting_proof | awaiting_confirmation | awaiting_gateway | settled | failed | reversed
     */
    function t_status_payment(string $status): string {
        $st = strtolower(trim($status));
        switch($st){
            case 'initiated': return 'Dimulai';
            case 'awaiting_proof': return 'Menunggu Bukti';
            case 'awaiting_confirmation': return 'Menunggu Konfirmasi';
            case 'awaiting_gateway': return 'Menunggu Gateway';
            case 'settled': return 'Berhasil';
            case 'failed': return 'Gagal';
            case 'reversed': return 'Dikembalikan';
            default: return human_status($status);
        }
    }
}
?>
