<?php

namespace App\Http\Controllers\Admin;

use App\Models\MatchScoreVersion;
use App\Services\Matching\MatchScoreCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MatchScoreVersionController extends AdminController
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:match_score_versions,version'],
            'weights' => ['required', 'array:'.implode(',', array_keys(MatchScoreCalculator::WEIGHTS))],
            'weights.*' => ['required', 'integer', 'min:0', 'max:100'],
        ]);
        if (array_sum($data['weights']) !== 100) {
            throw ValidationException::withMessages(['weights' => __('Die Gewichtung muss genau 100 ergeben.')]);
        }

        $version = MatchScoreVersion::query()->create([
            'version' => $data['version'],
            'weights' => $data['weights'],
            'created_by' => $request->user()?->getKey(),
            'status' => 'draft',
        ]);
        $this->audit($request, 'admin.match_score_version.created', $version, after: [
            'version' => $version->version,
            'weights' => $version->weights,
        ]);

        return back()->with('success', __('Die Match-Score-Version wurde als Entwurf gespeichert.'));
    }

    public function activate(Request $request, MatchScoreVersion $matchScoreVersion): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirmation' => ['required', Rule::in(['ACTIVATE'])],
        ]);

        DB::transaction(function () use ($request, $matchScoreVersion, $data): void {
            MatchScoreVersion::query()->where('status', 'active')->lockForUpdate()->get()
                ->each(fn (MatchScoreVersion $version) => $version->update(['status' => 'retired']));
            $target = MatchScoreVersion::query()->whereKey($matchScoreVersion->getKey())->lockForUpdate()->firstOrFail();
            $target->update([
                'status' => 'active',
                'activated_by' => $request->user()?->getKey(),
                'activation_reason' => $data['reason'],
                'activated_at' => now(),
            ]);
            $this->audit($request, 'admin.match_score_version.activated', $target, before: [
                'status' => $matchScoreVersion->status,
            ], after: [
                'status' => 'active',
                'version' => $target->version,
            ], metadata: ['reason' => $data['reason']]);
        }, 3);

        return back()->with('success', __('Die Match-Score-Version ist aktiv.'));
    }
}
