<?php

namespace App\Domain\Push;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** Envio real por Web Push (VAPID). Chaves so no .env (VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT). */
final class WebPushSender implements PushSender
{
    public function enabled(): bool
    {
        return (string) config('bipolar.vapid.public') !== '' && (string) config('bipolar.vapid.private') !== '';
    }

    public function send(array $subs): array
    {
        if (! $this->enabled() || $subs === []) {
            return [];
        }
        $wp = new WebPush(['VAPID' => [
            'subject' => (string) config('bipolar.vapid.subject'),
            'publicKey' => (string) config('bipolar.vapid.public'),
            'privateKey' => (string) config('bipolar.vapid.private'),
        ]], ['TTL' => 3600, 'urgency' => 'normal', 'topic' => 'lembrete']);
        $byEndpoint = [];
        foreach ($subs as $s) {
            $byEndpoint[$s->endpoint] = $s->id;
            $wp->queueNotification(Subscription::create([
                'endpoint' => $s->endpoint, 'publicKey' => $s->p256dh, 'authToken' => $s->auth, 'contentEncoding' => 'aes128gcm',
            ]), (string) json_encode(self::PAYLOAD));
        }
        $expired = [];
        foreach ($wp->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                $expired[] = $byEndpoint[$report->getEndpoint()] ?? 0;
            }
        }

        return array_values(array_filter($expired));
    }
}
