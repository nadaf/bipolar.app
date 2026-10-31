<?php

namespace App\Domain\Push;

use App\Models\PushSubscription;

interface PushSender
{
    /** Texto fixo e neutro de todo lembrete: NUNCA dado de saude. */
    public const PAYLOAD = ['title' => 'Bipolar', 'body' => 'Hora do seu registro', 'url' => '/registrar', 'tag' => 'lembrete'];

    public function enabled(): bool;

    /**
     * @param  list<PushSubscription>  $subs
     * @return list<int> ids das inscricoes expiradas (404/410), para remover
     */
    public function send(array $subs): array;
}
