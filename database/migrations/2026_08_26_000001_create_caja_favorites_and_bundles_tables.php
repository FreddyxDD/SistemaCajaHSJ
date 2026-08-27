<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atajos del cajero sobre el catalogo: favoritos y combos de servicios.
 *
 * Funcionalidad NUEVA que no existe en el sistema legado: SISGESH_BD guarda el
 * catalogo y sus precios, pero nada sobre las preferencias de cada cajero. Por eso
 * vive en la base propia del aplicativo (HSJ_Caja).
 *
 * Se guarda `cod_nomen_caja` (el servicio), NO `cod_precio`: el precio depende de la
 * forma de pago y cambia cuando Costos lo ajusta. Guardar el precio congelaria una
 * tarifa vieja; guardando el servicio, el precio se resuelve al usarlo, con la forma
 * de pago elegida en ese momento.
 *
 * La referencia al catalogo (SISGESH_BD) y al usuario (HSJ_Identity) es logica: son
 * bases distintas y no admiten claves foraneas entre ellas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caja_favorite_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->index();
            $table->string('cod_nomen_caja', 20);
            $table->timestamp('created_at')->useCurrent();

            // Un servicio no puede estar dos veces en los favoritos del mismo cajero.
            $table->unique(['user_id', 'cod_nomen_caja']);
        });

        Schema::create('caja_service_bundles', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 80);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('caja_service_bundle_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bundle_id')->constrained('caja_service_bundles')->cascadeOnDelete();
            $table->string('cod_nomen_caja', 20);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['bundle_id', 'cod_nomen_caja']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_service_bundle_items');
        Schema::dropIfExists('caja_service_bundles');
        Schema::dropIfExists('caja_favorite_items');
    }
};
