<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verificacao em duas etapas (TOTP), opcao de 2FA obrigatoria por clinica e convites de clinica por e-mail.
 * - users.mfa_recovery_codes: JSON com HMACs dos codigos de recuperacao (uso unico; nunca o codigo em claro).
 * - users.mfa_last_step: ultimo passo TOTP aceito (impede reuso do mesmo codigo).
 * - clinic_invites: so o HASH do token; expira; uso unico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_enabled_at');
            $table->unsignedBigInteger('mfa_last_step')->nullable()->after('mfa_recovery_codes');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('require_mfa')->default(false)->after('slug');
        });

        Schema::create('clinic_invites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('email', 190);
            $table->string('role', 20)->default('professional');
            $table->char('token_hash', 64);
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique('token_hash', 'uq_ci_token');
            $table->index(['tenant_id', 'email'], 'ix_ci_tenant_email');
            $table->foreign('tenant_id', 'fk_ci_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('invited_by', 'fk_ci_inviter')->references('id')->on('users')->nullOnDelete();
            $table->foreign('accepted_user_id', 'fk_ci_accepted')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_invites');
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('require_mfa'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['mfa_recovery_codes', 'mfa_last_step']));
    }
};
