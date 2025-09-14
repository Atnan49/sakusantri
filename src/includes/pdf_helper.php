<?php
// PDF rendering helper: prefer Dompdf (composer) else fallback to minimal FPDF placeholder
function pdf_render_and_output(string $html, string $filename = 'laporan.pdf') : void {
    // Try Dompdf via Composer autoload
    $dompdfOk = false;
    $autoload1 = dirname(__DIR__,2).'/vendor/autoload.php';
    if(file_exists($autoload1)){
        require_once $autoload1;
        if(class_exists('Dompdf\\Dompdf')){ $dompdfOk = true; }
    }
    if($dompdfOk){
        $dompdfClass = '\\Dompdf\\Dompdf';
        $dompdf = new $dompdfClass([ 'isRemoteEnabled' => false ]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream($filename, ['Attachment'=>true]);
        return;
    }
    // Fallback: FPDF placeholder (outputs plain text)
    require_once dirname(__DIR__).'/lib/fpdf/fpdf.php';
    $text = strip_tags($html);
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial','',12);
    foreach(explode("\n", $text) as $line){ $line = trim($line); if($line==='') continue; $pdf->Cell(0,6,$line,0,1); }
    $pdf->Output('D',$filename);
}
