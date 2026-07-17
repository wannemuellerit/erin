<?php

use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('never serializes private paths and only lets participants download clean attachments', function () {
    Storage::fake('private');

    $sender = User::factory()->create(['role' => UserRole::Candidate]);
    $recipient = User::factory()->create(['role' => UserRole::Candidate]);
    $stranger = User::factory()->create(['role' => UserRole::Candidate]);
    $conversation = Conversation::query()->create([
        'type' => 'direct',
        'title' => 'Sichere Unterhaltung',
        'last_message_at' => now(),
    ]);
    $conversation->participants()->attach([
        $sender->getKey() => ['last_read_at' => now()],
        $recipient->getKey() => ['last_read_at' => null],
    ]);
    $message = Message::query()->create([
        'conversation_id' => $conversation->getKey(),
        'sender_id' => $sender->getKey(),
        'type' => 'text',
        'body' => 'Im Anhang findest du das Dokument.',
    ]);
    $path = "conversations/{$conversation->getKey()}/private.pdf";
    Storage::disk('private')->put($path, 'private-content');
    $attachment = MessageAttachment::query()->create([
        'message_id' => $message->getKey(),
        'disk' => 'private',
        'path' => $path,
        'original_name' => 'nachweis.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 15,
        'scan_result' => 'clean',
        'scan_completed_at' => now(),
    ]);
    $broadcastPayload = (new MessageSent($message))->broadcastWith();

    expect($broadcastPayload['message']['attachments'][0])
        ->toHaveKeys(['id', 'original_name', 'mime_type', 'size_bytes', 'scan_result', 'download_url'])
        ->not->toHaveKeys(['path', 'disk']);

    $this->actingAs($recipient)
        ->get(route('messages.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('candidate/Messages')
            ->where('conversations.0.messages.0.attachments.0.id', $attachment->getKey())
            ->where('conversations.0.messages.0.attachments.0.original_name', 'nachweis.pdf')
            ->has('conversations.0.messages.0.attachments.0.download_url')
            ->missing('conversations.0.messages.0.attachments.0.path')
            ->missing('conversations.0.messages.0.attachments.0.disk'));

    $signedUrl = URL::temporarySignedRoute(
        'messages.attachments.download',
        now()->addMinutes(5),
        ['attachment' => $attachment],
    );

    $this->actingAs($recipient)
        ->get($signedUrl)
        ->assertOk()
        ->assertDownload('nachweis.pdf');

    $this->actingAs($stranger)->get($signedUrl)->assertForbidden();
    $this->actingAs($recipient)
        ->get(route('messages.attachments.download', $attachment))
        ->assertForbidden();

    $attachment->update(['scan_result' => 'pending']);

    $pendingUrl = URL::temporarySignedRoute(
        'messages.attachments.download',
        now()->addMinutes(5),
        ['attachment' => $attachment],
    );

    $this->actingAs($recipient)->get($pendingUrl)->assertStatus(423);
});
