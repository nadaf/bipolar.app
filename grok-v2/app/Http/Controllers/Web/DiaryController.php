<?php

namespace App\Http\Controllers\Web;

use App\Domain\Mood\MoodEntryService;
use App\Domain\Mood\Period;
use App\Domain\Mood\Scales;
use App\Http\Controllers\Controller;
use App\Http\Requests\MoodEntryRequest;
use App\Models\MoodEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DiaryController extends Controller
{
    public function today(Request $request)
    {
        $user = $request->user();
        $now = CarbonImmutable::now($user->timezone);
        $date = $request->query('data', $now->toDateString());
        $period = Period::tryFrom((string) $request->query('periodo')) ?? Period::forHour($now->hour);
        $entry = MoodEntry::query()->ownedBy($user)->where('entry_date', $date)->where('period', $period->value)->first();

        return view('diary.today', ['date' => $date, 'period' => $period, 'entry' => $entry,
            'mood' => Scales::MOOD, 'energy' => Scales::ENERGY, 'intensity' => Scales::INTENSITY, 'periods' => Period::cases()]);
    }

    public function save(MoodEntryRequest $request, MoodEntryService $service)
    {
        $entry = $service->upsert($request->user(), $request->entryData(), $request->integer('version') ?: null);

        return view('diary._saved', ['entry' => $entry]);
    }
}
