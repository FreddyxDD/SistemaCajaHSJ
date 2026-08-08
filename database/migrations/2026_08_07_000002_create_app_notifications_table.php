<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 80);
            $table->string('title', 180);
            $table->text('message')->nullable();
            $table->string('action_url', 500)->nullable();
            // Referencia logica a HSJ_Identity.users.id (sin FK entre bases distintas).
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['read_at', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
