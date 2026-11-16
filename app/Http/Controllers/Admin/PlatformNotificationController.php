<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Requests\Admin\SendPlatformNotificationRequest;
use App\Models\User;
use App\Services\Platform\ProductNotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

class PlatformNotificationController extends AdminController
{
    public function store(
        SendPlatformNotificationRequest $request,
        ProductNotificationDispatcher $dispatcher,
    ): RedirectResponse {
        $data = $request->validated();
        $query = User::query()->where('status', 'active');

        if ($data['audience'] === 'candidate') {
            $query->where('role', UserRole::Candidate);
        } elseif ($data['audience'] === 'company') {
            $query->where('role', UserRole::Company);
        }

        $queued = 0;
        try {
            $query->select(['id', 'name', 'email', 'locale'])
                ->orderBy('id')
                ->chunkById(250, function ($users) use ($data, $dispatcher, &$queued): void {
                    foreach ($users as $user) {
                        if ($dispatcher->dispatch(
                            $user,
                            'system.platform_announcement',
                            "platform-announcement:{$data['submission_key']}",
                            [
                                'title' => $data['translations']['de']['title'],
                                'message' => $data['translations']['de']['message'],
                                'translations' => $data['translations'],
                                'url' => $data['url'],
                            ],
                        )) {
                            $queued++;
                        }
                    }
                });
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['url' => $exception->getMessage()]);
        }

        $this->audit($request, 'admin.platform_notification.sent', metadata: [
            'audience' => $data['audience'],
            'queued' => $queued,
            'submission_key_hash' => hash('sha256', $data['submission_key']),
        ]);

        return back()->with('success', __(':count Plattform-Benachrichtigungen wurden eingeplant.', ['count' => $queued]));
    }
}
