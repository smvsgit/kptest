<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\MediaFile;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MediaVersionService;
use App\Services\SearchFallbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Functional review, 24 October 2026. One test per reproducible defect, named
 * for the finding it closes.
 */
class ReviewDefectsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * These tests are about upload integrity and search, not about the
     * mandatory-metadata rule, so the seeded required fields are cleared to
     * keep each failure pointing at the defect under test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::put('metadata.settings', ['required_fields' => []]);
    }

    private function department(string $name = 'Audio Video'): Department
    {
        return Department::firstOrCreate(['name' => $name], ['is_active' => true, 'is_system' => false]);
    }

    private function operator(): User
    {
        return User::factory()->create([
            'role' => 'department-operator',
            'department_id' => $this->department()->id,
            'status' => 'active',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'department-admin',
            'department_id' => $this->department()->id,
            'status' => 'active',
        ]);
    }

    private function payload(array $extra = []): array
    {
        return array_replace([
            'file' => UploadedFile::fake()->create('clip.mp4', 20, 'video/mp4'),
            'category_id' => Category::firstOrCreate(['name' => 'Events'])->id,
            'access_policy' => 'public',
        ], $extra);
    }

    /** A real PNG, so the decodable tests are not merely asserting on a fake. */
    private function realPng(string $name, int $w = 24, int $h = 12): UploadedFile
    {
        $image = imagecreatetruecolor($w, $h);
        $path = tempnam(sys_get_temp_dir(), 'png').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    // ------------------------------------------------- B01 unusable sources

    public function test_b01_an_empty_file_is_refused_at_upload(): void
    {
        Storage::fake('media');
        $this->actingAs($this->operator())
            ->postJson('/files', $this->payload(['file' => UploadedFile::fake()->create('empty.png', 0, 'image/png')]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, MediaFile::count());
    }

    public function test_b01_a_file_that_is_not_a_readable_image_is_refused(): void
    {
        Storage::fake('media');
        // Right name and size, bytes that are not an image at all.
        $path = tempnam(sys_get_temp_dir(), 'bad').'.png';
        file_put_contents($path, str_repeat('not an image', 100));
        $broken = new UploadedFile($path, 'broken.png', 'image/png', null, true);

        $this->actingAs($this->operator())
            ->postJson('/files', $this->payload(['file' => $broken]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, MediaFile::count());
    }

    public function test_b01_a_genuine_image_still_uploads(): void
    {
        Storage::fake('media');
        $this->actingAs($this->operator())
            ->postJson('/files', $this->payload(['file' => $this->realPng('good.png')]))
            ->assertSuccessful();

        $this->assertSame(1, MediaFile::count());
    }

    // --------------------------------------- B03 version promotion metadata

    public function test_b03_promoting_a_version_adopts_the_new_filename_and_resolution(): void
    {
        Storage::fake('media');
        $user = $this->admin();
        $this->actingAs($user)
            ->postJson('/files', $this->payload(['file' => $this->realPng('original.png', 10, 10)]))
            ->assertSuccessful();

        $media = MediaFile::firstOrFail();
        $this->assertSame('original.png', $media->name);

        app(MediaVersionService::class)->replaceWithUpload(
            $media,
            $this->realPng('replacement.png', 40, 20),
            $user,
            'Replaced during review.'
        );

        $media->refresh();
        $this->assertSame('replacement.png', $media->name, 'The asset should carry the name of the file it now is.');
        $this->assertSame('40x20', $media->resolution, 'Resolution is measured on promotion, not left blank for the queue.');
        $this->assertSame(2, (int) $media->current_version);
    }

    public function test_b03_the_superseded_name_is_still_recorded_on_its_own_version_row(): void
    {
        Storage::fake('media');
        $user = $this->admin();
        $this->actingAs($user)->postJson('/files', $this->payload(['file' => $this->realPng('first.png')]))->assertSuccessful();
        $media = MediaFile::firstOrFail();

        app(MediaVersionService::class)->replaceWithUpload($media, $this->realPng('second.png'), $user, 'Replacement.');

        $names = $media->versions()->reorder('version_number')->pluck('original_name')->all();
        $this->assertSame(['first.png', 'second.png'], $names);
    }

    // ---------------------------------- B04 / B05 fallback search behaviour

    public function test_b04_the_fallback_tolerates_a_transposed_filename(): void
    {
        $fallback = app(SearchFallbackService::class);
        $this->assertTrue(
            $fallback->isNear('QAReviewImage.png', ['QAReveiwImage']),
            'A two-character transposition is inside the engine own typo budget.'
        );
    }

    public function test_b04_an_unrelated_word_is_still_not_a_match(): void
    {
        $fallback = app(SearchFallbackService::class);
        $this->assertFalse($fallback->isNear('Annakut2024.jpg', ['Gurupurnima']));
    }

    public function test_b04_short_words_get_no_typo_budget(): void
    {
        $fallback = app(SearchFallbackService::class);
        $this->assertSame(0, $fallback->typoBudget('abc'));
        $this->assertSame(1, $fallback->typoBudget('abcde'));
        $this->assertSame(2, $fallback->typoBudget('abcdefghi'));
    }

    public function test_b05_a_configured_alias_expands_into_the_fallback_terms(): void
    {
        SystemSetting::put('search.settings', [
            'aliases' => true,
            'alias_groups' => [['Gurupurnima', 'ગુરુપૂર્ણિમા', 'Guru Purnima']],
        ]);

        $terms = app(SearchFallbackService::class)->expandTerms('ગુરુપૂર્ણિમા');

        $this->assertContains('Gurupurnima', $terms);
        $this->assertContains('Guru Purnima', $terms);
    }

    public function test_b05_aliases_are_ignored_when_the_setting_is_off(): void
    {
        SystemSetting::put('search.settings', [
            'aliases' => false,
            'alias_groups' => [['Gurupurnima', 'ગુરુપૂર્ણિમા']],
        ]);

        $this->assertSame(['ગુરુપૂર્ણિમા'], app(SearchFallbackService::class)->expandTerms('ગુરુપૂર્ણિમા'));
    }

    public function test_b05_an_alias_reaches_an_asset_stored_under_its_english_name(): void
    {
        Storage::fake('media');
        SystemSetting::put('search.settings', [
            'enabled' => true,
            'engine_pipeline_enabled' => false,
            'aliases' => true,
            'alias_groups' => [['Gurupurnima', 'ગુરુપૂર્ણિમા']],
        ]);
        $user = $this->admin();
        $this->actingAs($user)->postJson('/files', $this->payload([
            'file' => UploadedFile::fake()->create('Gurupurnima2024.mp4', 20, 'video/mp4'),
        ]))->assertSuccessful();

        $this->actingAs($user)->get('/?search='.urlencode('ગુરુપૂર્ણિમા'))->assertSuccessful();

        $terms = app(SearchFallbackService::class)->expandTerms('ગુરુપૂર્ણિમા');
        $this->assertTrue(
            MediaFile::query()->where('name', 'like', '%'.$terms[1].'%')->exists(),
            'The alias should reach the asset stored under its English name.'
        );
    }
}
