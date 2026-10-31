<?php

use Illuminate\Support\Facades\Schedule;

// cPanel: cron a cada minuto -> php artisan schedule:run
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping(5);
Schedule::command('sanctum:prune-expired --hours=24')->daily();
// Lembretes de registro por Web Push (texto neutro, sem dado de saude).
Schedule::command('bipolar:send-reminders')->everyMinute()->withoutOverlapping(5);
