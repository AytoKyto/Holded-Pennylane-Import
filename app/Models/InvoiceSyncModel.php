<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceSyncModel extends Model
{
    use HasFactory;

    protected $table = 'invoice_sync';

    protected $fillable = [
        "id_holded",
        "id_pennylane",
        "id_holded_estimate",
        "id_pennylane_estimate",
        "invoice_status",
        "checksum",
    ];
}
