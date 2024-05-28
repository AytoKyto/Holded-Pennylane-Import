<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoice_sync', function (Blueprint $table) {
            $table->id();
            $table->string('id_holded');
            $table->string('id_pennylane')->nullable();
            $table->string('id_holded_estimate')->nullable();
            $table->string('id_pennylane_estimate')->nullable();
            $table->tinyInteger('invoice_status')->default(0);
            $table->string('checksum')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_sync');
    }
};
