<?php
// PDF rendering helper: prefer Dompdf (composer) else fallback to minimal FPDF placeholder
function pdf_render_and_output(string $html, string $filename = 'laporan.pdf') : void {
    // Be generous with resources for rendering larger tables
    @ini_set('memory_limit', '512M');
    if (function_exists('set_time_limit')) { @set_time_limit(60); }

    // Try Dompdf via Composer autoload
    $autoload1 = dirname(__DIR__,2).'/vendor/autoload.php';
    if (file_exists($autoload1)) {
        require_once $autoload1;
        if (class_exists('Dompdf\\Dompdf')) {
            try {
                // Instantiate with safe options; avoid remote fetching to prevent SSRF
                $dompdfClass = '\\Dompdf\\Dompdf';
                $dompdf = new $dompdfClass([
                    'isRemoteEnabled' => false,
                ]);
                // On Windows, ensure temp dir is set to a writable location
                if (method_exists($dompdf, 'set_option')) {
                    $tmp = sys_get_temp_dir();
                    if ($tmp) { $dompdf->set_option('tempDir', $tmp); }
                    // Constrain file access within project root for safety
                    $root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__,2);
                    $dompdf->set_option('chroot', $root);
                    $dompdf->set_option('isHtml5ParserEnabled', true);
                }

                $dompdf->loadHtml($html, 'UTF-8');
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();
                $dompdf->stream($filename, ['Attachment' => true]);
                return; // success with Dompdf
            } catch (\Throwable $e) {
                // Log error and continue to fallback
                error_log('Dompdf render failed: ' . $e->getMessage());
            }
        }
    }

    // Fallback: FPDF placeholder (outputs plain text; not a real PDF)
    require_once dirname(__DIR__).'/lib/fpdf/fpdf.php';
    $text = strip_tags($html);
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial','',12);
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line==='') continue;
        $pdf->Cell(0, 6, $line, 0, 1);
    }
    $pdf->Output('D', $filename);
}
