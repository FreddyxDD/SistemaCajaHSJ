<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            // Referencia logica a HSJ_Identity.users.id (sin FK entre bases distintas).
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('event_type', 80);
            $table->string('module', 80);
            $table->string('action', 80);
            $table->string('auditable_type', 160)->nullable();
            $table->string('auditable_id', 80)->nullable();
            $table->string('route_name', 160)->nullable();
            $table->string('method', 12)->nullable();
            $table->string('url', 800)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['module', 'action', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
            $table->index(['route_name', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
