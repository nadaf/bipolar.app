<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'bipolar:vapid';

    protected $description = 'Gera um par de chaves VAPID e mostra as linhas para colar no .env (nao grava nada).';

    public function handle(): int
    {
        $k = VAPID::createVapidKeys();
        $this->line('VAPID_PUBLIC_KEY='.$k['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$k['privateKey']);
        $this->warn('Cole no .env do servidor. Nunca versione a chave privada.');

        return self::SUCCESS;
    }
}
