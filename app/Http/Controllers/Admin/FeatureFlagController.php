<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\StoreFeatureFlagRequest;
use App\Http\Requests\Admin\UpdateFeatureFlagRequest;
use App\Models\FeatureFlag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FeatureFlagController extends AdminController
{
    public function store(StoreFeatureFlagRequest $request): RedirectResponse
    {
        $flag = FeatureFlag::query()->create([
            ...$request->validated(),
            'updated_by' => $request->user()?->getKey(),
        ]);

        $this->audit(
            $request,
            'admin.feature_flag.created',
            $flag,
            after: $flag->only([
                'key',
                'name',
                'description',
                'enabled',
                'rollout_percentage',
                'conditions',
            ]),
        );

        return back()->with('success', __('Das Feature Flag wurde angelegt.'));
    }

    public function update(
        UpdateFeatureFlagRequest $request,
        FeatureFlag $featureFlag,
    ): RedirectResponse {
        $fields = [
            'name',
            'description',
            'enabled',
            'rollout_percentage',
            'conditions',
        ];
        $before = $featureFlag->only($fields);

        $featureFlag->update([
            ...$request->validated(),
            'updated_by' => $request->user()?->getKey(),
        ]);

        $this->audit(
            $request,
            'admin.feature_flag.updated',
            $featureFlag,
            $before,
            $featureFlag->only($fields),
        );

        return back()->with('success', __('Das Feature Flag wurde aktualisiert.'));
    }

    public function destroy(
        Request $request,
        FeatureFlag $featureFlag,
    ): RedirectResponse {
        $before = $featureFlag->only([
            'key',
            'name',
            'description',
            'enabled',
            'rollout_percentage',
            'conditions',
        ]);

        $this->audit(
            $request,
            'admin.feature_flag.deleted',
            $featureFlag,
            $before,
        );
        $featureFlag->delete();

        return back()->with('success', __('Das Feature Flag wurde gelöscht.'));
    }
}
