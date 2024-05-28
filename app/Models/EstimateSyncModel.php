<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EstimateSyncModel extends Model
{
    use HasFactory;

    protected $table = 'estimate_sync';

    protected $fillable = [
        "id_holded",
        "id_pennylane",
        "checksum",
    ];
}
