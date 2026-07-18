<?php

namespace App\Http\Controllers;

use App\Exceptions\SupportAttachmentIntegrityException;
use App\Models\SupportTicketAttachment;
use App\Services\Audit\AuditLogger;
use App\Services\Ticketing\SupportAttachmentIntegrityVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportAttachmentController extends Controller
{
    public function __invoke(
        Request $request,
        SupportTicketAttachment $attachment,
        AuditLogger $audit,
        SupportAttachmentIntegrityVerifier $integrity,
    ): StreamedResponse {
        abort_if($request->user() === null, 401);
        $attachment->loadMissing('message.supportTicket');
        Gate::authorize('view', $attachment->message->supportTicket);
        abort_unless(
            $attachment->scan_result === 'clean',
            423,
            __('Der Anhang ist noch nicht sicherheitsgeprüft.'),
        );
        abort_unless(filled($attachment->disk) && filled($attachment->path), 404);
        try {
            $contents = $integrity->verifiedContents($attachment);
        } catch (SupportAttachmentIntegrityException) {
            abort(409, __('Der Anhang stimmt nicht mehr mit der geprüften Datei überein.'));
        }

        $audit->record(
            'support.attachment_downloaded',
            $attachment,
            metadata: [
                'ticket_id' => $attachment->message->support_ticket_id,
                'message_id' => $attachment->support_ticket_message_id,
            ],
            companyId: $attachment->message->supportTicket->company_id,
        );

        return response()->streamDownload(
            static function () use ($contents): void {
                echo $contents;
            },
            $attachment->original_name,
            [
                'Content-Type' => filled($attachment->mime_type)
                    ? (string) $attachment->mime_type
                    : 'application/octet-stream',
            ],
        );
    }
}
