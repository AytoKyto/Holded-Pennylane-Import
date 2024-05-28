<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductSyncModel extends Model
{
    use HasFactory;

    protected $table = 'product_sync';

    protected $fillable = [
        "id_holded",
        "id_pennylane",
        "checksum",
    ];
}
