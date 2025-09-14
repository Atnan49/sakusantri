<?php
/*
 Minimal FPDF 1.86 subset for simple text tables.
 For full features, use official FPDF from http://www.fpdf.org/ (LGPL)
 This subset supports: AddPage, SetFont, Cell, Ln, Output.
*/
class FPDF {
    protected $w = 210; // A4 width
    protected $h = 297; // A4 height
    protected $x = 10;
    protected $y = 10;
    protected $font = 'Arial';
    protected $fontSize = 12;
    protected $pages = [];
    protected $buffer = '';

    public function __construct($orientation='P', $unit='mm', $size='A4'){}
    public function AddPage(){
        $this->buffer = '';
        $this->x = 10; $this->y = 10; $this->pages[] = '';
    }
    public function SetFont($family, $style='', $size=12){ $this->font=$family; $this->fontSize=$size; }
    public function Ln($h=5){ $this->y += $h; $this->x = 10; }
    public function Cell($w, $h=5, $txt='', $border=0, $ln=0){
        // Very naive: append plain text with simple separators
        $line = $txt;
        $this->buffer .= $line."\n";
        $this->y += $h;
        if($ln>0){ $this->Ln($h); }
    }
    public function Output($dest='', $name='doc.pdf'){
        // This is a placeholder: to actually output a real PDF, install full FPDF or mPDF/DOMPDF.
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="'.basename($name).'"');
        echo "PDF output placeholder. Install full FPDF/DOMPDF for true PDF.\n\n";
        echo $this->buffer;
    }
}
