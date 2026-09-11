<?php

use App\Contracts\AiProvider;
use App\Data\AiRequest;
use App\Data\AiResponse;
use App\Enums\UserRole;
use App\Events\ConversationRead;
use App\Events\MessageSent;
use App\Models\AiRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageTranslation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('keeps Reverb timing and limits strongly typed for live subscriptions', function () {
    expect(config('reverb.apps.apps.0.options.port'))->toBeInt()
        ->and(config('reverb.apps.apps.0.ping_interval'))->toBeInt()
        ->and(config('reverb.apps.apps.0.activity_timeout'))->toBeInt()
        ->and(config('reverb.apps.apps.0.max_message_size'))->toBeInt()
        ->and(config('reverb.apps.apps.0.rate_limiting.max_attempts'))->toBeInt()
        ->and(config('reverb.apps.apps.0.rate_limiting.decay_seconds'))->toBeInt();
});

function erinRealtimeConversation(): array
{
    $sender = User::factory()->create(['role' => UserRole::Candidate]);
    $recipient = User::factory()->create(['role' => UserRole::Candidate]);
    $conversation = Conversation::query()->create(['type' => 'direct', 'title' => 'Realtime']);
    $conversation->participants()->attach([
        $sender->getKey() => ['last_read_at' => now()],
        $recipient->getKey() => ['last_read_at' => null],
    ]);

    return compact('sender', 'recipient', 'conversation');
}

it('deduplicates optimistic voice retries and broadcasts read state', function () {
    Storage::fake('private');
    Queue::fake();
    Event::fake([MessageSent::class, ConversationRead::class]);
    ['sender' => $sender, 'recipient' => $recipient, 'conversation' => $conversation] = erinRealtimeConversation();
    $clientId = 'ed879ae8-ef02-44a2-8f96-7a37f57fdf47';
    $payload = [
        'client_id' => $clientId,
        'type' => 'voice',
        'duration_seconds' => 7,
        'waveform' => [0.2, 0.8, 0.5],
        'attachments' => [UploadedFile::fake()->image('voice.png')],
    ];

    $this->actingAs($sender)->post(route('messages.send', $conversation), $payload)->assertRedirect();
    $this->actingAs($sender)->post(route('messages.send', $conversation), $payload)->assertRedirect();

    expect(Message::query()->where('client_id', $clientId)->count())->toBe(1)
        ->and(MessageAttachment::query()->count())->toBe(1)
        ->and(MessageAttachment::query()->firstOrFail()->duration_seconds)->toBe(7)
        ->and(MessageAttachment::query()->firstOrFail()->waveform)->toBe([0.2, 0.8, 0.5]);
    Event::assertDispatchedTimes(MessageSent::class, 1);

    $this->actingAs($recipient)->post(route('messages.read', $conversation))->assertRedirect();
    Event::assertDispatched(ConversationRead::class, fn (ConversationRead $event): bool => $event->userId === $recipient->getKey());
});

it('translates a message once with explicit consent and preserves the original', function () {
    ['sender' => $sender, 'recipient' => $recipient, 'conversation' => $conversation] = erinRealtimeConversation();
    $message = Message::query()->create([
        'conversation_id' => $conversation->getKey(),
        'sender_id' => $sender->getKey(),
        'type' => 'text',
        'body' => 'Original message',
    ]);
    $provider = new class implements AiProvider
    {
        public int $calls = 0;

        public function respond(AiRequest $request): AiResponse
        {
            $this->calls++;

            return new AiResponse('translation-1', 'eu-model', ['content' => 'Übersetzte Nachricht'], 12, 5);
        }

        public function supportsSensitiveDocuments(): bool
        {
            return true;
        }
    };
    app()->instance(AiProvider::class, $provider);

    $payload = ['target_locale' => 'de', 'explicit_consent' => true];
    $this->actingAs($recipient)->postJson(route('messages.translate', $message), $payload)
        ->assertOk()->assertJsonPath('body', 'Übersetzte Nachricht');
    $this->actingAs($recipient)->postJson(route('messages.translate', $message), $payload)
        ->assertOk()->assertJsonPath('body', 'Übersetzte Nachricht');

    expect($provider->calls)->toBe(1)
        ->and(MessageTranslation::query()->count())->toBe(1)
        ->and(AiRun::query()->count())->toBe(1)
        ->and($message->fresh()->body)->toBe('Original message');
});

it('fails message translation closed without consent or an EU provider', function () {
    ['sender' => $sender, 'recipient' => $recipient, 'conversation' => $conversation] = erinRealtimeConversation();
    $message = Message::query()->create([
        'conversation_id' => $conversation->getKey(),
        'sender_id' => $sender->getKey(),
        'type' => 'text',
        'body' => 'Private original',
    ]);

    $this->actingAs($recipient)->postJson(route('messages.translate', $message), [
        'target_locale' => 'de',
        'explicit_consent' => false,
    ])->assertUnprocessable();

    $this->actingAs($recipient)->postJson(route('messages.translate', $message), [
        'target_locale' => 'de',
        'explicit_consent' => true,
    ])->assertUnprocessable();

    expect(MessageTranslation::query()->count())->toBe(0)
        ->and($message->fresh()->body)->toBe('Private original');
});
