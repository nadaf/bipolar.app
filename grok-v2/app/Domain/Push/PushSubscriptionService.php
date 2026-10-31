<?php

namespace App\Domain\Push;

use App\Models\PushSubscription;
use App\Models\User;

/** Inscrever/cancelar o aparelho do proprio usuario, sempre no tenant do contexto. */
final class PushSubscriptionService
{
    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:1000', 'url:https', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_\-=]+$/'],
            'keys.auth' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-=]+$/'],
        ];
    }

    public function subscribe(User $u, string $endpoint, string $p256dh, string $auth): PushSubscription
    {
        $hash = hash('sha256', $endpoint);
        $s = PushSubscription::query()->where('endpoint_hash', $hash)->first();
        if ($s && $s->user_id !== $u->id) {
            $s->delete(); // aparelho trocou de dono neste tenant: a inscricao antiga deixa de valer
            $s = null;
        }
        $s ??= new PushSubscription(['user_id' => $u->id, 'endpoint_hash' => $hash]);
        $s->fill(['endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth])->save();

        return $s;
    }

    public function unsubscribe(User $u, string $endpoint): bool
    {
        return (bool) PushSubscription::query()->where('user_id', $u->id)->where('endpoint_hash', hash('sha256', $endpoint))->delete();
    }

    public function count(User $u): int
    {
        return PushSubscription::query()->where('user_id', $u->id)->count();
    }
}
