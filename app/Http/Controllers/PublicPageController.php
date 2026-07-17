<?php

namespace App\Http\Controllers;

use App\Services\Platform\PlatformSettings;
use Inertia\Inertia;
use Inertia\Response;

class PublicPageController extends Controller
{
    public function privacy(PlatformSettings $settings): Response
    {
        return $this->legal('privacy', $settings);
    }

    public function imprint(PlatformSettings $settings): Response
    {
        return $this->legal('imprint', $settings);
    }

    public function terms(PlatformSettings $settings): Response
    {
        return $this->legal('terms', $settings);
    }

    public function contact(PlatformSettings $settings): Response
    {
        $configured = $settings->getPublic('public.contact', []);
        $contact = is_array($configured) ? $configured : [];
        $locale = app()->getLocale() === 'en' ? 'en' : 'de';
        $email = $this->validEmail($contact['email'] ?? null);
        $phone = $this->plainText($contact['phone'] ?? null);
        $address = $this->plainText($contact["address_{$locale}"] ?? null);

        return Inertia::render('Contact', [
            'contact' => [
                'available' => $email !== null || $phone !== null || $address !== null,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
            ],
        ]);
    }

    private function legal(string $document, PlatformSettings $settings): Response
    {
        $configured = $settings->getPublic("legal.{$document}", []);
        $legal = is_array($configured) ? $configured : [];
        $locale = app()->getLocale() === 'en' ? 'en' : 'de';
        $content = $this->plainText($legal["content_{$locale}"] ?? null);
        $published = ($legal['published'] ?? false) === true && $content !== null;

        return Inertia::render('Legal', [
            'document' => $document,
            'published' => $published,
            'content' => $published ? $content : null,
        ]);
    }

    private function plainText(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function validEmail(mixed $value): ?string
    {
        $email = $this->plainText($value);

        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? $email
            : null;
    }
}
