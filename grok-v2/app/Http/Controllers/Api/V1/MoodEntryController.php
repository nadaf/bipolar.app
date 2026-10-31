<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mood\MoodEntryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\MoodEntryRequest;
use App\Http\Resources\MoodEntryResource;
use App\Models\MoodEntry;
use Illuminate\Http\Request;

class MoodEntryController extends Controller
{
    public function index(Request $request)
    {
        $v = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $q = MoodEntry::query()->ownedBy($request->user())->orderByDesc('entry_date')->orderBy('period');
        if (isset($v['from'])) {
            $q->where('entry_date', '>=', $v['from']);
        }
        if (isset($v['to'])) {
            $q->where('entry_date', '<=', $v['to']);
        }

        return MoodEntryResource::collection($q->limit(500)->get());
    }

    public function show(Request $request, int $id)
    {
        // Tenant (global scope) + dono. Qualquer outro caso => 404, nunca 403 (nao revela existencia).
        return new MoodEntryResource(MoodEntry::query()->ownedBy($request->user())->findOrFail($id));
    }

    public function upsert(MoodEntryRequest $request, MoodEntryService $service)
    {
        $entry = $service->upsert($request->user(), $request->entryData(), $request->integer('version') ?: null);

        return (new MoodEntryResource($entry))->response()->setStatusCode($entry->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, int $id)
    {
        MoodEntry::query()->ownedBy($request->user())->findOrFail($id)->delete();

        return response()->noContent();
    }
}
