<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mood_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedInteger('version')->default(1);
            $table->date('entry_date');
            $table->string('period', 5); // manha | tarde | noite
            $table->tinyInteger('mood'); // -3..+3 (depressao..mania)
            $table->tinyInteger('energy')->nullable(); // -3..+3 (muito baixa..muito alta), 0 = normal
            $table->tinyInteger('energy_legacy')->nullable(); // valor original 0..3 da v0.5 (auditoria da conversao)
            $table->tinyInteger('anxiety')->nullable(); // 0..3
            $table->tinyInteger('irritability')->nullable(); // 0..3
            $table->decimal('sleep_hours', 4, 1)->nullable();
            $table->boolean('medication_taken')->nullable();
            $table->text('note')->nullable(); // criptografado (cast encrypted)
            $table->char('client_uuid', 36)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'patient_id', 'entry_date', 'period'], 'uq_me_tenant_patient_day_period');
            $table->unique(['tenant_id', 'patient_id', 'client_uuid'], 'uq_me_client_uuid');
            $table->index(['tenant_id', 'id'], 'ix_me_tenant_id');
            $table->foreign('tenant_id', 'fk_me_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            // FK composta: o paciente precisa ser membro DESTE tenant.
            $table->foreign(['tenant_id', 'patient_id'], 'fk_me_membership')
                ->references(['tenant_id', 'user_id'])->on('memberships')->cascadeOnDelete();
        });

        // Concessoes do paciente para a rede de apoio (somente leitura, expira, revogavel).
        Schema::create('care_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('grantee_user_id')->nullable();
            $table->string('grantee_kind', 15); // psicologo | psiquiatra | familiar | outro
            $table->unsignedInteger('scope_mask')->default(7);
            $table->char('invite_token_hash', 64)->nullable()->unique();
            $table->timestamp('invite_expires_at')->nullable();
            $table->date('data_from')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'patient_id']);
            $table->index(['grantee_user_id', 'revoked_at']);
            $table->foreign(['tenant_id', 'patient_id'], 'fk_cg_membership')
                ->references(['tenant_id', 'user_id'])->on('memberships')->cascadeOnDelete();
            $table->foreign('grantee_user_id', 'fk_cg_grantee')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('action', 40);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedInteger('scope_mask')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'patient_id', 'created_at']);
            $table->foreign('tenant_id', 'fk_al_tenant')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('care_grants');
        Schema::dropIfExists('mood_entries');
    }
};
