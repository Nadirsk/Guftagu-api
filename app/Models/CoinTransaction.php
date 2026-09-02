<?php

namespace App\Models;

// docs/02 §5.2 — the spend currency ledger.
class CoinTransaction extends LedgerTransaction
{
    protected $table = 'coin_transactions';
}
