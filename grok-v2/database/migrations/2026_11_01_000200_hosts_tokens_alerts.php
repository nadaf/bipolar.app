<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - tenants.slug: subdominio opcional (<slug>.bipolar.app.br) para clinica, profissional solo ou pessoa.
 * - personal_access_tokens.tenant_id: token amarrado ao tenant em que foi emitido.
 * - alert_settings: limiares de alerta escolhidos pelo proprio paciente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('slug', 30)->nullable()->unique()->after('name');
        });
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('tokenable_id');
            $table->index('tenant_id');
        });
        Schema::create('alert_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('patient_id');
            $table->tinyInteger('mood_low')->default(-2);
            $table->tinyInteger('mood_high')->default(2);
            $table->tinyInteger('energy_low')->default(-2);
            $table->tinyInteger('energy_high')->default(2);
            $table->timestamps();
            $table->unique(['tenant_id', 'patient_id'], 'uq_alert_patient');
            $table->foreign(['tenant_id', 'patient_id'], 'fk_as_membership')
                ->references(['tenant_id', 'user_id'])->on('memberships')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_settings');
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
