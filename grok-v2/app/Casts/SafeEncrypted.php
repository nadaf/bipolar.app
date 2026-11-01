<?php

namespace App\Casts;

use App\Support\DataKeys;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Como o cast "encrypted" / "encrypted:array" do Laravel (mesmo formato no banco), mas tolerante:
 * se o valor nao abre com a APP_KEY atual, devolve null (ou [] com "empty") e registra so um aviso
 * sem conteudo. Nunca derruba a tela com erro 500.
 * Uso: SafeEncrypted::class, SafeEncrypted::class.':array', SafeEncrypted::class.':array,empty'
 *
 * @implements CastsAttributes<mixed, mixed>
 */
class SafeEncrypted implements CastsAttributes
{
    private bool $array;

    private bool $empty;

    public function __construct(string $type = 'string', string $fallback = 'null')
    {
        $this->array = $type === 'array';
        $this->empty = $fallback === 'empty';
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        $plain = DataKeys::current((string) $value);
        if ($plain === null) {
            Log::warning('Valor cifrado ilegivel com a APP_KEY atual (ignorado).', ['model' => $model::class, 'field' => $key, 'id' => $model->getKey()]);

            return $this->array && $this->empty ? [] : null;
        }
        if (! $this->array) {
            return $plain;
        }
        $d = json_decode($plain, true);

        return is_array($d) ? $d : ($this->empty ? [] : null);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString($this->array ? (string) json_encode($value) : (string) $value);
    }
}
