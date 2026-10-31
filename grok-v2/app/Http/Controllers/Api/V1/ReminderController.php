<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reminders\ReminderService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    public function show(Request $request, ReminderService $svc)
    {
        return $svc->get($request->user());
    }

    public function update(Request $request, ReminderService $svc)
    {
        $v = $request->validate(ReminderService::rules());

        return $svc->put($request->user(), $v['days']);
    }
}
