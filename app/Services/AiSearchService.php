<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Optional AI assistance for search. Off unless an administrator turns it on,
 * and off again on every query unless the person searching asks for it.
 *
 * The review (F07) asked for AI search "as a separately enabled, explicit
 * optional mode without slowing or silently replacing native queries". So this
 * does not replace anything: it widens a query with extra terms, which are then
 * fed to the same Meilisearch pipeline as the words the user typed. Native
 * search runs first and its order is kept; the AI terms only add to the tail.
 *
 * Nothing here chooses a provider. The endpoint, model and key are the
 * administrator's, and any OpenAI-shaped chat-completions service will do.
 * With no endpoint configured the service reports itself unavailable rather
 * than guessing.
 */
class AiSearchService
{
    public const SETTING = 'search.ai';

    public const DEFAULTS = [
        'enabled' => false,
        'endpoint' => '',
        'model' => '',
        'api_key' => '',
        'timeout_seconds' => 6,
        'max_terms' => 6,
        'instructions' => 'You expand search queries for an archive of Hindu satsang media: events, mandirs, people, dates and places. Reply with search terms only, comma separated, no sentences.',
    ];

    public function settings(): array
    {
        return array_replace(self::DEFAULTS, SystemSetting::valueFor(self::SETTING, []) ?: []);
    }

    /** Configured and switched on. The query still has to ask for it. */
    public function available(): bool
    {
        $settings = $this->settings();
        return (bool) ($settings['enabled'] ?? false)
            && trim((string) ($settings['endpoint'] ?? '')) !== ''
            && trim((string) ($settings['model'] ?? '')) !== '';
    }

    /**
     * Extra terms for one query.
     *
     * Returns `used` false and a reason whenever the AI step did not happen —
     * switched off, not configured, refused, timed out, or nothing useful
     * came back. The caller shows that to the person searching, so an AI
     * search that quietly degraded into a normal one is never mistaken for an
     * AI result.
     *
     * @return array{terms: string[], used: bool, note: ?string}
     */
    public function expand(string $query): array
    {
        $query = trim($query);
        if ($query === '') return ['terms' => [], 'used' => false, 'note' => null];
        if (!$this->available()) {
            return ['terms' => [], 'used' => false, 'note' => __('messages.search.ai_unavailable')];
        }

        $settings = $this->settings();
        $timeout = max(1, min(30, (int) ($settings['timeout_seconds'] ?: 6)));

        try {
            $request = Http::acceptJson()->timeout($timeout);
            if ($key = trim((string) ($settings['api_key'] ?? ''))) {
                $request = $request->withToken($key);
            }
            $response = $request->post(rtrim((string) $settings['endpoint'], '/'), [
                'model' => $settings['model'],
                'messages' => [
                    ['role' => 'system', 'content' => (string) ($settings['instructions'] ?: self::DEFAULTS['instructions'])],
                    ['role' => 'user', 'content' => $query],
                ],
            ]);

            if (!$response->successful()) {
                return ['terms' => [], 'used' => false, 'note' => __('messages.search.ai_refused', ['status' => $response->status()])];
            }

            $terms = $this->termsFrom($response->json(), (int) ($settings['max_terms'] ?: 6), $query);
            if (!$terms) {
                return ['terms' => [], 'used' => false, 'note' => __('messages.search.ai_no_terms')];
            }

            return ['terms' => $terms, 'used' => true, 'note' => null];
        } catch (Throwable $e) {
            report($e);
            return ['terms' => [], 'used' => false, 'note' => __('messages.search.ai_failed')];
        }
    }

    /**
     * Pull comma-separated terms out of whatever the provider answered with.
     * Anything that is not a short, plain term is dropped rather than passed
     * on to the search engine.
     */
    private function termsFrom(mixed $payload, int $max, string $query): array
    {
        $text = data_get($payload, 'choices.0.message.content')
            ?? data_get($payload, 'choices.0.text')
            ?? data_get($payload, 'content.0.text')
            ?? data_get($payload, 'terms')
            ?? '';
        if (is_array($text)) $text = implode(',', array_filter($text, 'is_string'));
        if (!is_string($text)) return [];

        $seen = [mb_strtolower($query) => true];
        $out = [];
        foreach (preg_split('/[,\n;]+/u', $text) ?: [] as $piece) {
            $term = trim(preg_replace('/^[\s\-\*\d\.\)]+/u', '', (string) $piece) ?? '');
            if ($term === '' || mb_strlen($term) > 60) continue;
            $key = mb_strtolower($term);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $term;
            if (count($out) >= max(1, $max)) break;
        }
        return $out;
    }
}
