<?php

namespace App\Support;

/**
 * The sections of the Settings screen, as one list.
 *
 * Until v19.14 a role either reached the whole admin console or none of it:
 * the routes sat behind a blanket `role:super-admin`, and the screen hid its
 * tabs behind `isSuper`. The November review (F05) asked for the console to be
 * divided, so a Super Admin can give one role metadata and master data without
 * also giving it users, security or backups.
 *
 * Every section carries three possible levels: none, view, edit. The defaults
 * below reproduce exactly what each built-in role could do before the change,
 * so nothing widens until a Super Admin grants it.
 */
final class SettingsSections
{
    public const NONE = 'none';
    public const VIEW = 'view';
    public const EDIT = 'edit';

    public const LEVELS = [self::NONE, self::VIEW, self::EDIT];

    /** Section key => the catalogue key the screen labels it with. */
    public const SECTIONS = [
        'system' => 'settings.tab_system',
        'users' => 'settings.tab_users',
        'lifecycle' => 'settings.tab_lifecycle',
        'fonts' => 'settings.tab_fonts',
        'search' => 'settings.tab_search',
        'metadata' => 'settings.tab_metadata',
        'upload' => 'settings.tab_upload',
        'security' => 'settings.tab_security',
        'notifications' => 'settings.tab_notifications',
        'backup' => 'settings.tab_backup',
        'readiness' => 'settings.tab_readiness',
        'guide' => 'settings.tab_guide',
        'completion' => 'settings.tab_completion',
        'integrations' => 'nav.integrations',
    ];

    /**
     * What a built-in base role may do when no portal role says otherwise.
     * A Super Admin is not listed: it always has edit, so the console cannot
     * lock its own administrator out.
     */
    public const DEFAULTS = [
        'department-admin' => [
            'system' => self::EDIT,
            'users' => self::EDIT,
            'lifecycle' => self::EDIT,
        ],
    ];

    public static function keys(): array
    {
        return array_keys(self::SECTIONS);
    }

    public static function isSection(string $key): bool
    {
        return array_key_exists($key, self::SECTIONS);
    }

    public static function normaliseLevel(mixed $level): string
    {
        $level = is_string($level) ? strtolower(trim($level)) : '';
        return in_array($level, self::LEVELS, true) ? $level : self::NONE;
    }

    /** The default map for a base role, with every section present. */
    public static function defaultsFor(string $baseRole): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = $baseRole === 'super-admin'
                ? self::EDIT
                : (self::DEFAULTS[$baseRole][$key] ?? self::NONE);
        }
        return $out;
    }

    /** Fill in every section, dropping anything that is not one. */
    public static function normaliseMap(array $input, string $baseRole): array
    {
        $out = self::defaultsFor($baseRole);
        foreach (self::keys() as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = self::normaliseLevel($input[$key]);
            }
        }
        // A Super Admin role keeps the whole console, by the same rule that
        // keeps its Settings page switched on.
        if ($baseRole === 'super-admin') {
            foreach (self::keys() as $key) $out[$key] = self::EDIT;
        }
        return $out;
    }
}
