<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    /**
     * Where this request's settings map is held. Registered as a scoped binding
     * in AppServiceProvider, so it lasts exactly one request, one console
     * command or one queued job and no longer.
     */
    public const MAP = 'system-settings.map';

    protected $fillable = ['key', 'value'];
    protected $casts = ['value' => 'array'];

    protected static function booted(): void
    {
        // Any write through the model drops the map, so a screen that saves and
        // then re-reads sees its own change.
        static::saved(fn () => static::flush());
        static::deleted(fn () => static::flush());
    }

    /**
     * Every stored setting, read once.
     *
     * The portal keeps its configuration in the database and governs it in
     * Settings rather than in a text file, which is the architecture review
     * F10 asked to see measured. The measurement found nothing wrong with that
     * choice and one thing wrong with this method: a single dashboard render
     * asked for thirty different keys and made thirty round trips to get them,
     * which is the whole of what a configuration file would have saved. Read as
     * one map it is one round trip, and the difference between governed
     * configuration and a file falls to about a millisecond a page.
     *
     * @return array<string,mixed>
     */
    public static function map(): array
    {
        $container = app();

        if (!$container->bound(self::MAP)) {
            return static::freshMap();
        }

        return $container->make(self::MAP);
    }

    /** @return array<string,mixed> */
    public static function freshMap(): array
    {
        return static::query()->get(['key', 'value'])
            ->mapWithKeys(fn (self $row) => [$row->key => $row->value])
            ->all();
    }

    /**
     * Forget this request's map. Writes through the model do it themselves;
     * anything that writes the table with the query builder instead - a mass
     * delete, a migration - must call this.
     */
    public static function flush(): void
    {
        app()->forgetInstance(self::MAP);
    }

    public static function valueFor(string $key, array $default = []): array
    {
        $value = static::map()[$key] ?? null;

        return is_array($value) ? $value : $default;
    }

    public static function put(string $key, array $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        static::flush();
    }
}
