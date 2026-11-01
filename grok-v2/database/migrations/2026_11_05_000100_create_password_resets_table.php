<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recuperacao de senha: so o HASH (sha256) do token; expira em 60 min; uso unico;
 * amarrado ao host/tenant onde foi pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_resets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('host_tenant_id')->nullable(); // tenant do HOST do pedido (nao e dono da linha; lido antes do tenant)
            $table->string('host', 190);
            $table->char('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->unique('token_hash', 'uq_pr_token');
            $table->index(['user_id', 'host'], 'ix_pr_user_host');
            $table->foreign('user_id', 'fk_pr_user')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};
