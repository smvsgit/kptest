<?php

namespace App\Services;

use App\Models\MediaFile;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Tag suggestions for one image, asked for one at a time.
 *
 * The review (F08) was explicit about the shape: "Add an explicit AI tagging
 * button for supported images; process only on request, show editable
 * suggestions, let user accept/reject, preserve existing tags... Do not
 * automatically call AI on every upload."
 *
 * So nothing here is wired to the upload pipeline or to a queue job. It is
 * called from one endpoint, which a person reaches by pressing a button, and
 * it returns words for them to accept or reject. It never writes a tag.
 */
class AiTaggingService
{
    public const SETTING = 'tagging.ai';

    /** What the vision call will accept. Anything else is refused before any cost. */
    public const SUPPORTED = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** A frame bigger than this is not sent; the thumbnail is used instead. */
    public const MAX_BYTES = 4 * 1024 * 1024;

    public const DEFAULTS = [
        'enabled' => false,
        'endpoint' => '',
        'model' => '',
        'api_key' => '',
        'timeout_seconds' => 20,
        'max_tags' => 8,
        'instructions' => 'Suggest short search tags for this photograph from a Hindu satsang archive: what is happening, who or what is shown, the kind of occasion, and the setting. Reply with tags only, comma separated, lower case, no sentences.',
    ];

    public function settings(): array
    {
        return array_replace(self::DEFAULTS, SystemSetting::valueFor(self::SETTING, []) ?: []);
    }

    public function available(): bool
    {
        $settings = $this->settings();
        return (bool) ($settings['enabled'] ?? false)
            && trim((string) ($settings['endpoint'] ?? '')) !== ''
            && trim((string) ($settings['model'] ?? '')) !== '';
    }

    public function supports(MediaFile $media): bool
    {
        if ($media->type !== 'image') return false;
        $ext = strtolower(pathinfo((string) $media->name, PATHINFO_EXTENSION));
        return in_array($ext, self::SUPPORTED, true);
    }

    /**
     * Ask for tags for one asset.
     *
     * @return array{tags: string[], used: bool, note: ?string}
     */
    public function suggest(MediaFile $media): array
    {
        if (!$this->available()) {
            return ['tags' => [], 'used' => false, 'note' => __('messages.tagging.unavailable')];
        }
        if (!$this->supports($media)) {
            return ['tags' => [], 'used' => false, 'note' => __('messages.tagging.unsupported')];
        }

        $image = $this->readableImage($media);
        if (!$image) {
            return ['tags' => [], 'used' => false, 'note' => __('messages.tagging.no_image')];
        }

        $settings = $this->settings();
        $timeout = max(5, min(60, (int) ($settings['timeout_seconds'] ?: 20)));

        try {
            $request = Http::acceptJson()->timeout($timeout);
            if ($key = trim((string) ($settings['api_key'] ?? ''))) {
                $request = $request->withToken($key);
            }
            $response = $request->post(rtrim((string) $settings['endpoint'], '/'), [
                'model' => $settings['model'],
                'messages' => [
                    ['role' => 'system', 'content' => (string) ($settings['instructions'] ?: self::DEFAULTS['instructions'])],
                    ['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => $media->name],
                        ['type' => 'image_url', 'image_url' => ['url' => $image]],
                    ]],
                ],
            ]);

            if (!$response->successful()) {
                return ['tags' => [], 'used' => false, 'note' => __('messages.tagging.refused', ['status' => $response->status()])];
            }

            $tags = $this->tagsFrom($response->json(), (int) ($settings['max_tags'] ?: 8), $media->tags ?? []);
            if (!$tags) {
                return ['tags' => [], 'used' => false, 'note' => __('messages.tagging.nothing_new')];
            }

            return ['tags' => $tags, 'used' => true, 'note' => null];
        } catch (Throwable $e) {
            report($e);
            return ['tags' => [], 'used' => false, 'note' => __('messages.tagging.failed')];
        }
    }

    /** The thumbnail if there is one, else the original while it is small enough. */
    private function readableImage(MediaFile $media): ?string
    {
        $disk = Storage::disk('media');
        foreach (array_filter([$media->thumbnail_path, $media->file_path]) as $path) {
            if (str_starts_with((string) $path, 'http')) continue;
            if (!$disk->exists($path)) continue;
            if ($disk->size($path) > self::MAX_BYTES) continue;
            $mime = $disk->mimeType($path) ?: 'image/jpeg';
            return 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
        }
        return null;
    }

    /**
     * Short, plain words only, and never one the asset already carries — the
     * point is what to add, not a list the person has to weed.
     */
    private function tagsFrom(mixed $payload, int $max, array $existing): array
    {
        $text = data_get($payload, 'choices.0.message.content')
            ?? data_get($payload, 'content.0.text')
            ?? data_get($payload, 'tags')
            ?? '';
        if (is_array($text)) $text = implode(',', array_filter($text, 'is_string'));
        if (!is_string($text)) return [];

        $seen = [];
        foreach ($existing as $tag) $seen[mb_strtolower(trim((string) $tag))] = true;

        $out = [];
        foreach (preg_split('/[,\n;]+/u', $text) ?: [] as $piece) {
            $tag = trim(preg_replace('/^[\s\-\*\d\.\)]+/u', '', (string) $piece) ?? '');
            if ($tag === '' || mb_strlen($tag) > 40) continue;
            $key = mb_strtolower($tag);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $tag;
            if (count($out) >= max(1, $max)) break;
        }
        return $out;
    }
}
