<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UpsertAccessListEntryRequest;
use App\Models\AccessListEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccessListEntryController extends AdminController
{
    public function store(UpsertAccessListEntryRequest $request): RedirectResponse
    {
        $entry = AccessListEntry::query()->create([
            ...$request->validated(),
            'created_by' => $request->user()?->getKey(),
        ]);

        $this->audit(
            $request,
            'admin.access_list_entry.created',
            $entry,
            after: $this->snapshot($entry),
        );

        return back()->with('success', __('Der Zugriffslisteneintrag wurde angelegt.'));
    }

    public function update(
        UpsertAccessListEntryRequest $request,
        AccessListEntry $accessListEntry,
    ): RedirectResponse {
        $before = $this->snapshot($accessListEntry);
        $accessListEntry->update($request->validated());

        $this->audit(
            $request,
            'admin.access_list_entry.updated',
            $accessListEntry,
            $before,
            $this->snapshot($accessListEntry),
        );

        return back()->with('success', __('Der Zugriffslisteneintrag wurde aktualisiert.'));
    }

    public function destroy(
        Request $request,
        AccessListEntry $accessListEntry,
    ): RedirectResponse {
        $before = $this->snapshot($accessListEntry);

        $this->audit(
            $request,
            'admin.access_list_entry.deleted',
            $accessListEntry,
            $before,
        );
        $accessListEntry->delete();

        return back()->with('success', __('Der Zugriffslisteneintrag wurde gelöscht.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AccessListEntry $entry): array
    {
        return [
            'list_type' => $entry->list_type,
            'subject_type' => $entry->subject_type,
            'value' => $entry->value,
            'reason' => $entry->reason,
            'created_by' => $entry->created_by,
            'expires_at' => $this->auditDate($entry->expires_at),
        ];
    }
}
