<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put the portal's opening language back to English.
 *
 * A fresh install already defaults to English; this instance reads Gujarati
 * because branding.default_language was set to it while the translation was
 * being tested, and that stored value outranks the code default. The user
 * asked for English to be the default again, so this resets it once.
 *
 * It is not a lock. Settings -> Branding & Language still sets the portal's
 * opening language, and anyone who prefers Gujarati can choose it there; a
 * visitor can also switch on the sign-in screen itself, and a signed-in
 * account's own preference outranks both.
 */
return new class extends Migration
{
    private const KEY = 'branding.settings';

    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $row = DB::table('system_settings')->where('key', self::KEY)->first();
        if (! $row) {
            // Nothing stored, so the code default already applies.
            return;
        }

        $value = json_decode((string) $row->value, true);
        if (! is_array($value) || ($value['default_language'] ?? 'en') === 'en') {
            return;
        }

        $value['default_language'] = 'en';

        DB::table('system_settings')
            ->where('key', self::KEY)
            ->update(['value' => json_encode($value), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Deliberately empty: the previous language is not recorded anywhere,
        // and guessing one would be worse than leaving the setting as it is.
    }
};
