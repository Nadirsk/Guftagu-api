<?php

namespace App\Models;

// docs/02 §5.2 — the earn currency ledger, withdrawable.
class DiamondTransaction extends LedgerTransaction
{
    protected $table = 'diamond_transactions';
}
