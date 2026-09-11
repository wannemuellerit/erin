<?php

namespace App\Services\Platform;

use App\Models\EmailTemplate;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class EmailTemplateRenderer
{
    /** @var list<string> */
    public const VARIABLES = ['name', 'title', 'message', 'url', 'action_label'];

    /**
     * @param  array<string, scalar|null>  $variables
     * @return array{subject: string, body_html: string, body_text: string, fallback_used: bool}
     */
    public function render(string $key, string $locale, array $variables): array
    {
        $locale = in_array($locale, config('app.supported_locales', ['de', 'en']), true) ? $locale : 'de';
        $variables = $this->normalizedVariables($variables);

        try {
            $template = $this->template($key, $locale);

            if ($template === null) {
                throw new RuntimeException('Template is unavailable.');
            }

            return $this->renderTemplate($template, $variables, false);
        } catch (Throwable) {
            return $this->renderTemplate($this->fallback($locale), $variables, true);
        }
    }

    public function forget(?string $key = null): void
    {
        if ($key === null) {
            return;
        }

        foreach (config('app.supported_locales', ['de', 'en']) as $locale) {
            Cache::forget($this->cacheKey($key, $locale));
        }
    }

    /**
     * @return array{subject: string, body_html: string, body_text: string}|null
     */
    private function template(string $key, string $locale): ?array
    {
        return Cache::remember($this->cacheKey($key, $locale), now()->addMinutes(10), function () use ($key, $locale): ?array {
            $template = EmailTemplate::query()
                ->where('key', $key)
                ->where('locale', $locale)
                ->where('is_active', true)
                ->first(['subject', 'body_html', 'body_text']);

            if ($template === null) {
                return null;
            }

            return [
                'subject' => $template->subject,
                'body_html' => $template->body_html,
                'body_text' => $template->body_text ?: strip_tags($template->body_html),
            ];
        });
    }

    /**
     * @param  array{subject: string, body_html: string, body_text: string}  $template
     * @param  array<string, string>  $variables
     * @return array{subject: string, body_html: string, body_text: string, fallback_used: bool}
     */
    private function renderTemplate(array $template, array $variables, bool $fallback): array
    {
        foreach ($template as $value) {
            $this->assertAllowedVariables($value);
        }

        $subject = $this->replace($template['subject'], $variables, false);
        $html = $this->replace($template['body_html'], $variables, true);
        $text = $this->replace($template['body_text'], $variables, false);

        return [
            'subject' => trim(str_replace(["\r", "\n"], ' ', strip_tags($subject))),
            'body_html' => $this->sanitizeHtml($html),
            'body_text' => trim(strip_tags($text)),
            'fallback_used' => $fallback,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @return array<string, string>
     */
    private function normalizedVariables(array $variables): array
    {
        $normalized = [];

        foreach (self::VARIABLES as $name) {
            $normalized[$name] = isset($variables[$name])
                ? (string) $variables[$name]
                : '';
        }

        if (! $this->allowedUrl($normalized['url'])) {
            $normalized['url'] = route('dashboard');
        }

        return $normalized;
    }

    private function assertAllowedVariables(string $template): void
    {
        preg_match_all('/{{\s*([a-z_]+)\s*}}/u', $template, $matches);

        foreach ($matches[1] as $variable) {
            if (! in_array($variable, self::VARIABLES, true)) {
                throw new RuntimeException('Unknown template variable.');
            }
        }

        if (preg_match('/{{|}}/', preg_replace('/{{\s*[a-z_]+\s*}}/u', '', $template) ?? '')) {
            throw new RuntimeException('Malformed template placeholder.');
        }
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function replace(string $template, array $variables, bool $escapeHtml): string
    {
        return preg_replace_callback(
            '/{{\s*([a-z_]+)\s*}}/u',
            static function (array $match) use ($variables, $escapeHtml): string {
                $value = $variables[$match[1]] ?? '';

                return $escapeHtml
                    ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    : $value;
            },
            $template,
        ) ?? '';
    }

    private function sanitizeHtml(string $html): string
    {
        $allowed = ['p', 'br', 'strong', 'em', 'a', 'ul', 'ol', 'li'];
        $html = strip_tags($html, '<'.implode('><', $allowed).'>');
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="erin-template-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('erin-template-root');

        if (! $root instanceof DOMElement) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $this->sanitizeNode($root, $allowed);
        $result = '';

        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return $result;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function sanitizeNode(DOMNode $node, array $allowed): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                if (! in_array(strtolower($child->tagName), $allowed, true)) {
                    $child->parentNode?->replaceChild(
                        $child->ownerDocument->createTextNode($child->textContent),
                        $child,
                    );

                    continue;
                }

                foreach (iterator_to_array($child->attributes) as $attribute) {
                    if ($child->tagName !== 'a' || $attribute->name !== 'href') {
                        $child->removeAttribute($attribute->name);
                    }
                }

                if ($child->tagName === 'a' && ! $this->allowedUrl($child->getAttribute('href'))) {
                    $child->removeAttribute('href');
                }
            }

            $this->sanitizeNode($child, $allowed);
        }
    }

    private function allowedUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        $parts = parse_url($url);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($appHost)
            && hash_equals(strtolower($appHost), strtolower((string) ($parts['host'] ?? '')));
    }

    /**
     * @return array{subject: string, body_html: string, body_text: string}
     */
    private function fallback(string $locale): array
    {
        $greeting = __('Hallo {{ name }},', [], $locale);
        $salutation = __('Dein Faden-Team', [], $locale);

        return [
            'subject' => '{{ title }}',
            'body_html' => '<p>'.$greeting.'</p><p>{{ message }}</p><p><a href="{{ url }}">{{ action_label }}</a></p><p>'.$salutation.'</p>',
            'body_text' => $greeting."\n\n{{ message }}\n\n{{ action_label }}: {{ url }}\n\n".$salutation,
        ];
    }

    private function cacheKey(string $key, string $locale): string
    {
        return 'erin.email-template.'.hash('sha256', $key.'|'.$locale);
    }
}
