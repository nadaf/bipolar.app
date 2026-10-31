<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.0 front-end:
 * - day_blocks: blocos do Registrar (sono, estresse, alimentacao, medicacao, substancias, pensamentos,
 *   religiosidade, sexualidade, pensamentos de morte, anotacao, meu dia). Conteudo CRIPTOGRAFADO
 *   (cast encrypted:array). period = manha|tarde|noite ou 'dia' (bloco diario).
 * - users: tema, modulos opcionais ligados, PIN de desbloqueio, troca de e-mail em duas fases.
 * - alert_settings.reminders: grade semanal de lembretes (texto JSON, sem default: MySQL 5.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('patient_id');
            $table->date('entry_date');
            $table->string('period', 5); // manha | tarde | noite | dia
            $table->string('block', 20);
            $table->text('data'); // criptografado
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'patient_id', 'entry_date', 'period', 'block'], 'uq_db_tenant_patient_day_block');
            $table->index(['tenant_id', 'patient_id', 'entry_date'], 'ix_db_tenant_patient_day');
            $table->foreign('tenant_id', 'fk_db_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'patient_id'], 'fk_db_membership')
                ->references(['tenant_id', 'user_id'])->on('memberships')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 10)->default('sistema')->after('timezone'); // sistema | claro | escuro
            $table->string('modules', 200)->nullable()->after('theme'); // modulos opcionais ligados (csv)
            $table->string('pin_hash')->nullable()->after('modules');
            $table->string('pending_email', 190)->nullable()->after('pin_hash');
            $table->char('email_change_hash', 64)->nullable()->after('pending_email');
            $table->timestamp('email_change_expires_at')->nullable()->after('email_change_hash');
        });

        Schema::table('alert_settings', function (Blueprint $table) {
            $table->text('reminders')->nullable()->after('energy_high');
        });

        // Cada cartao (humor, energia, ansiedade) salva sozinho: humor deixa de ser obrigatorio.
        Schema::table('mood_entries', function (Blueprint $table) {
            $table->tinyInteger('mood')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mood_entries', function (Blueprint $table) {
            $table->tinyInteger('mood')->nullable(false)->change();
        });
        Schema::table('alert_settings', function (Blueprint $table) {
            $table->dropColumn('reminders');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['theme', 'modules', 'pin_hash', 'pending_email', 'email_change_hash', 'email_change_expires_at']);
        });
        Schema::dropIfExists('day_blocks');
    }
};
