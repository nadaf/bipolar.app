<?php

namespace App\Console\Commands;

use App\Domain\Push\ReminderDispatcher;
use Illuminate\Console\Command;

class SendReminders extends Command
{
    protected $signature = 'bipolar:send-reminders {--window=10 : minutos de tolerancia apos o horario}';

    protected $description = 'Envia os lembretes de registro (Web Push, texto neutro) vencidos agora. Roda pelo agendador a cada minuto.';

    public function handle(ReminderDispatcher $d): int
    {
        $r = $d->run(null, max(1, (int) $this->option('window')));
        $this->line("lembretes: devidos {$r['due']}, enviados {$r['sent']}, expirados {$r['expired']}");

        return self::SUCCESS;
    }
}
