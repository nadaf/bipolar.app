<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inscricoes de Web Push (lembretes de registro). Guardam so o endpoint do navegador e as chaves
 * publicas da inscricao; nenhum dado de saude. last_slot evita mandar o mesmo lembrete duas vezes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->text('endpoint');
            $table->char('endpoint_hash', 64);
            $table->string('p256dh', 200);
            $table->string('auth', 100);
            $table->string('last_slot', 30)->nullable(); // ex.: 2026-10-31|noite
            $table->timestamps();
            $table->unique(['tenant_id', 'endpoint_hash'], 'uq_ps_tenant_endpoint');
            $table->index(['tenant_id', 'user_id'], 'ix_ps_tenant_user');
            $table->foreign('tenant_id', 'fk_ps_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'user_id'], 'fk_ps_membership')
                ->references(['tenant_id', 'user_id'])->on('memberships')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
