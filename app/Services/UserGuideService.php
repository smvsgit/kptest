<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use RuntimeException;

class UserGuideService
{
    public const TOTAL_PAGES = 22;

    public function settings(): array
    {
        $defaults = [
            'enabled' => true,
            'role_pages' => [
                'department-admin' => 14,
                'department-operator' => 10,
                'viewer' => 5,
            ],
            'department_pages' => [],
            'user_pages' => [],
        ];

        return array_replace_recursive($defaults, SystemSetting::valueFor('user_guide.access', []));
    }

    public function normalizeSettings(array $input): array
    {
        $settings = [
            'enabled' => (bool) ($input['enabled'] ?? true),
            'role_pages' => [],
            'department_pages' => [],
            'user_pages' => [],
        ];

        foreach (['department-admin', 'department-operator', 'viewer'] as $role) {
            $settings['role_pages'][$role] = $this->clampPages(Arr::get($input, "role_pages.{$role}", 0));
        }

        foreach ((array) ($input['department_pages'] ?? []) as $id => $pages) {
            if (! ctype_digit((string) $id) || (int) $id <= 0) {
                continue;
            }
            $settings['department_pages'][(string) ((int) $id)] = $this->clampPages($pages);
        }

        foreach ((array) ($input['user_pages'] ?? []) as $id => $pages) {
            if (! ctype_digit((string) $id) || (int) $id <= 0) {
                continue;
            }
            $settings['user_pages'][(string) ((int) $id)] = $this->clampPages($pages);
        }

        ksort($settings['department_pages'], SORT_NATURAL);
        ksort($settings['user_pages'], SORT_NATURAL);

        return $settings;
    }

    public function allowedPageCount(User $user): int
    {
        // Super Admin always retains the full manual so guide governance cannot self-lockout.
        if ($user->role === 'super-admin') {
            return self::TOTAL_PAGES;
        }

        $settings = $this->settings();
        if (! ($settings['enabled'] ?? true)) {
            return 0;
        }

        $userKey = (string) $user->id;
        if (array_key_exists($userKey, (array) ($settings['user_pages'] ?? []))) {
            return $this->clampPages($settings['user_pages'][$userKey]);
        }

        if ($user->department_id) {
            $departmentKey = (string) $user->department_id;
            if (array_key_exists($departmentKey, (array) ($settings['department_pages'] ?? []))) {
                return $this->clampPages($settings['department_pages'][$departmentKey]);
            }
        }

        return $this->clampPages(Arr::get($settings, 'role_pages.'.$user->role, 0));
    }

    public function accessSummary(User $user): array
    {
        $allowed = $this->allowedPageCount($user);

        return [
            'can_view' => $allowed > 0,
            'allowed_pages' => $allowed,
            'total_pages' => self::TOTAL_PAGES,
            'document_url' => $allowed === self::TOTAL_PAGES ? route('user-guide.document') : null,
            'html_url' => $allowed > 0 ? route('user-guide.html') : null,
        ];
    }

    public function pagesFor(User $user): array
    {
        $allowed = $this->allowedPageCount($user);
        if ($allowed <= 0) {
            return [];
        }

        return array_slice($this->allPages(), 0, $allowed);
    }

    public function allPages(): array
    {
        $html = $this->sourceHtml();
        $pattern = '/<section\s+class="guide-page"\s+data-page="(\d+)"\s+data-title="([^"]*)">(.*?)<\/section>/su';
        if (! preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            throw new RuntimeException('User Guide source does not contain any parseable pages.');
        }

        $pages = [];
        foreach ($matches as $match) {
            $pages[] = [
                'number' => (int) $match[1],
                'title' => html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'html' => trim($match[3]),
            ];
        }

        usort($pages, fn (array $a, array $b) => $a['number'] <=> $b['number']);
        if (count($pages) !== self::TOTAL_PAGES) {
            throw new RuntimeException('User Guide page count mismatch. Expected '.self::TOTAL_PAGES.', found '.count($pages).'.');
        }

        return $pages;
    }

    public function printableHtml(User $user): string
    {
        $pages = $this->pagesFor($user);
        if ($pages === []) {
            throw new RuntimeException('User Guide access denied.');
        }

        $source = $this->sourceHtml();
        preg_match('/<style>(.*?)<\/style>/su', $source, $styleMatch);
        $style = $styleMatch[1] ?? '';
        $body = implode("\n", array_map(
            fn (array $page) => '<section class="guide-page" data-page="'.$page['number'].'" data-title="'.htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8').'">'.$page['html'].'</section>',
            $pages
        ));

        return '<!doctype html><html lang="gu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>કાર્યાલય પોર્ટલ - User Guide Manual</title><style>'.$style.'</style></head><body><div class="guide-document">'
            .'<div class="guide-cover"><h1>કાર્યાલય પોર્ટલ - User Guide Manual</h1><p><strong>Application Release:</strong> v'.e(config('version.current')).'</p>'
            .'<p><strong>Authorized view:</strong> '.count($pages).' of '.self::TOTAL_PAGES.' logical pages.</p></div>'
            .$body.'</div></body></html>';
    }

    public function sourcePath(): string
    {
        return resource_path('user-guide/Karyalay_Portal_User_Guide_v16.00.html');
    }

    public function documentPath(): string
    {
        return base_path('docs/Karyalay_Portal_User_Guide_v16.00.docx');
    }

    private function sourceHtml(): string
    {
        $path = $this->sourcePath();
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('User Guide HTML source is missing or unreadable.');
        }
        $html = file_get_contents($path);
        if ($html === false || $html === '') {
            throw new RuntimeException('User Guide HTML source is empty.');
        }
        return $html;
    }

    private function clampPages(mixed $value): int
    {
        return max(0, min(self::TOTAL_PAGES, (int) $value));
    }
}
