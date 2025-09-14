<?php
// Fitur laporan PDF telah dihapus.
if (!function_exists('pdf_render_and_output')) {
    function pdf_render_and_output(string $html, string $filename = 'laporan.pdf') : void {
        http_response_code(410); // Gone
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Fitur laporan PDF telah dihapus.';
        exit;
    }
}
