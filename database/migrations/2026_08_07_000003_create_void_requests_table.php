<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flujo de aprobacion de anulaciones: funcionalidad NUEVA que no existe en el sistema
 * de Caja legado (SISGESH_BD solo guarda el resultado final en
 * Cabecera_documento_MH.estado_doc / cod_motiv_anu, sin rastro de quien pidio anular
 * ni quien aprobo). Por eso vive en la base propia del aplicativo (HSJ_Caja).
 *
 * Las referencias a documentos (SISGESH_BD) y usuarios (HSJ_Identity) son logicas:
 * son bases distintas, no se pueden declarar claves foraneas entre ellas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('void_requests', function (Blueprint $table) {
            $table->id();

            // Documento en SISGESH_BD.Cabecera_documento_MH (referencia logica).
            $table->string('document_id', 20)->index();
            $table->string('document_number', 30);
            $table->decimal('document_total', 12, 4);
            $table->string('cash_session_code', 10)->nullable();

            // Motivo elegido del catalogo legado Motivo_Anulacion_MH.
            $table->string('void_reason_code', 5);
            $table->string('void_reason_label', 100);
            $table->text('request_notes')->nullable();

            // Solicitante (usuario central de HSJ_Identity, referencia logica).
            $table->unsignedBigInteger('requested_by_user_id')->index();
            $table->string('requested_by_name', 180);
            $table->timestamp('requested_at')->useCurrent();

            $table->string('status', 20)->default('pending')->index();

            // Revisor: jefe de economia o cajero central.
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable()->index();
            $table->string('reviewed_by_name', 180)->nullable();
            $table->string('reviewed_by_role', 60)->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('void_requests');
    }
};
