<?php
// Stub SimplePDF: fitur laporan PDF telah dihapus. Kelas dipertahankan untuk kompatibilitas jika ada require lama.
class SimplePDF {
    public function setMaxCharsPerLine(int $n): void {}
    public function writeLine(string $line): void {}
    public function writeBlank(int $n=1): void {}
    public function output(string $filename = 'laporan.pdf', bool $attachment = true): void {
        http_response_code(410);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Fitur laporan PDF telah dihapus.';
        exit;
    }
}
