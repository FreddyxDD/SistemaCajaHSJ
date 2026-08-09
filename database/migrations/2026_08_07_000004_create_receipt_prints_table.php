<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitacora de impresiones de boleta. El esquema legado no registra si una boleta ya
 * se imprimio, por lo que no habria forma de distinguir el original de una copia.
 * Vive en la base propia del aplicativo (HSJ_Caja); la referencia al documento
 * (SISGESH_BD) y al usuario (HSJ_Identity) es logica, sin FK entre bases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_prints', function (Blueprint $table) {
            $table->id();

            $table->string('document_id', 20)->index();
            $table->string('document_number', 30);

            // false = original (primera impresion), true = reimpresion/copia.
            $table->boolean('is_reprint')->default(false);

            $table->unsignedBigInteger('printed_by_user_id')->nullable()->index();
            $table->string('printed_by_name', 180)->nullable();
            $table->timestamp('printed_at')->useCurrent();

            $table->timestamps();

            $table->index(['document_id', 'printed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_prints');
    }
};
