<?php

// Migration pour créer la table `product_sync`
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductSyncTable extends Migration
{
    public function up()
    {
        Schema::create('product_sync', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('id_holded');
            $table->string('id_pennylane');
            $table->string('checksum')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_sync');
    }
}
