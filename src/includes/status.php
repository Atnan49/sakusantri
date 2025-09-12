<?php
// Central status definitions & validators
const INVOICE_STATUSES = ['pending','partial','paid','overdue','canceled'];
const PAYMENT_STATUSES = ['initiated','awaiting_proof','awaiting_confirmation','awaiting_gateway','settled','failed','reversed'];

function invoice_status_is_valid(string $s): bool { return in_array($s, INVOICE_STATUSES, true); }
function payment_status_is_valid(string $s): bool { return in_array($s, PAYMENT_STATUSES, true); }

// Determine derived invoice status after payment apply, preserving overdue if still not fully paid and due date passed
function derive_invoice_status(string $current, float $amount, float $paid, ?string $dueDate): string {
    $remaining = $amount - $paid;
    if($remaining <= 0.0001) return 'paid';
    $today = date('Y-m-d');
    $isOverdue = $dueDate && $dueDate < $today;
    if($isOverdue && $current === 'overdue') return 'overdue'; // maintain overdue
    if($isOverdue && $current !== 'overdue' && $paid < $amount) return 'overdue'; // transition if now overdue
    return $paid > 0 ? 'partial' : 'pending';
}
?>