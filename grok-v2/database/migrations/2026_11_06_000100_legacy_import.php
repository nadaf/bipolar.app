<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importador de dados do Bipolar 0.5 (somente leitura no banco antigo).
 * - legacy_links: cada linha da 0.5 ja copiada (tipo + id antigo -> id novo). Garante que repetir nao duplica.
 * - legacy_import_progress: onde cada etapa parou (ultimo id antigo lido) e contagens. Permite continuar.
 * Nenhum conteudo clinico fica aqui: so ids e numeros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_links', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->unsignedBigInteger('legacy_id');
            $table->unsignedBigInteger('new_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['kind', 'legacy_id'], 'uq_ll_kind_legacy');
        });
        Schema::create('legacy_import_progress', function (Blueprint $table) {
            $table->string('step', 20)->primary();
            $table->unsignedBigInteger('last_id')->default(0);
            $table->boolean('done')->default(false);
            $table->unsignedInteger('copied')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->text('notes')->nullable(); // JSON {motivo: quantidade}
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_progress');
        Schema::dropIfExists('legacy_links');
    }
};
