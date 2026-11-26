<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\MediaFile;
use App\Models\Subcategory;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F02, the option the owner chose: "Use one canonical folder tree and
 * breadcrumbs for navigation, with category retained only as an optional
 * metadata filter. Avoid two independently maintained navigation structures."
 *
 * The sidebar's category tree was the second structure. It is gone; category
 * is now a filter beside the other metadata filters, and the portal opens on
 * the folder workspace. Filtering by category still has to work exactly as it
 * did - that is what most of this file checks.
 */
class OneNavigationModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::put('metadata.settings', ['required_fields' => []]);
    }

    private function department(string $name = 'Multimedia'): Department
    {
        return Department::firstOrCreate(['name' => $name], ['is_active' => true, 'is_system' => false]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'department-admin', 'department_id' => $this->department()->id, 'status' => 'active',
        ]);
    }


    /**
     * `files` is a deferred Inertia prop, so a plain visit does not carry it.
     * This asks for it the way the screen does, as a partial reload.
     */
    private function browse(User $actor, string $query)
    {
        // The asset version has to match or Inertia answers 409, so it is read
        // from a normal visit first rather than guessed.
        $first = $this->actingAs($actor)->get('/');
        $version = (string) data_get($first->viewData('page'), 'version');

        return $this->actingAs($actor)->get('/?'.$query, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'files',
        ]);
    }

    private function file(string $name, ?Category $category = null, ?Subcategory $sub = null): MediaFile
    {
        return MediaFile::create([
            'name' => $name, 'type' => 'image', 'size' => 10,
            'department_id' => $this->department()->id,
            'category_id' => $category?->id, 'subcategory_id' => $sub?->id,
            'access_policy' => 'public', 'download_allowed' => true, 'tags' => [],
            'file_path' => 'uploads/images/'.$name, 'asset_status' => 'active',
        ]);
    }

    // ------------------------------------------- category still filters

    public function test_filtering_by_category_still_works_from_the_query(): void
    {
        $utsav = Category::firstOrCreate(['name' => 'Utsav']);
        $satsang = Category::firstOrCreate(['name' => 'Satsang']);
        $this->file('annakut.png', $utsav);
        $this->file('sabha.png', $satsang);

        $this->browse($this->admin(), 'category_id='.$utsav->id)
            ->assertSuccessful()
            ->assertJsonCount(1, 'props.files.data')
            ->assertJsonPath('props.files.data.0.name', 'annakut.png');
    }

    public function test_filtering_by_sub_category_still_works(): void
    {
        $utsav = Category::firstOrCreate(['name' => 'Utsav']);
        $photos = Subcategory::firstOrCreate(['category_id' => $utsav->id, 'name' => 'Photos']);
        $this->file('annakut.png', $utsav, $photos);
        $this->file('loose.png', $utsav);

        $this->browse($this->admin(), 'category_id='.$utsav->id.'&subcategory_id='.$photos->id)
            ->assertSuccessful()
            ->assertJsonCount(1, 'props.files.data')
            ->assertJsonPath('props.files.data.0.name', 'annakut.png');
    }

    public function test_the_categories_the_filter_offers_are_still_shared(): void
    {
        Category::firstOrCreate(['name' => 'Utsav']);

        $this->actingAs($this->admin())->get('/')
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->has('categories'));
    }

    // ------------------------------------------- one navigation structure

    /**
     * The sidebar used to carry a category tree with its own search, its own
     * expand state and its own add/edit buttons - a second navigation to keep
     * in step with the folder tree. It is gone.
     */
    public function test_the_sidebar_no_longer_carries_a_second_tree(): void
    {
        $layout = (string) file_get_contents(base_path('resources/js/Layouts/AppLayout.tsx'));

        $this->assertStringNotContainsString('category-tree', $layout);
        $this->assertStringNotContainsString('sidebar-category-header', $layout);
        $this->assertStringNotContainsString('category-search-input', $layout);
        $this->assertStringNotContainsString("onCategorySelect", $layout);
    }

    public function test_browse_is_no_longer_a_place_in_the_navigation(): void
    {
        $layout = (string) file_get_contents(base_path('resources/js/Layouts/AppLayout.tsx'));

        $this->assertStringNotContainsString("activeTab==='browse-panel'?' active'", $layout,
            'Browse is where a search lands, not an entry you click.');
        $this->assertStringContainsString("activeTab==='folders-panel'?' active'", $layout,
            'The folder workspace is still in the navigation, and is now first.');
    }

    public function test_the_portal_opens_on_the_folder_workspace(): void
    {
        $dashboard = (string) file_get_contents(base_path('resources/js/Pages/Dashboard.tsx'));

        $this->assertStringContainsString("?panel:'folders') as PanelSlug]", $dashboard);
        $this->assertStringContainsString("?'folders-panel':requestedTab", $dashboard,
            'A panel out of reach falls back to the folder workspace, not to Browse.');
    }

    public function test_a_search_lands_on_the_results_page(): void
    {
        $dashboard = (string) file_get_contents(base_path('resources/js/Pages/Dashboard.tsx'));

        $this->assertStringContainsString("panel:q?'browse':'folders'", $dashboard);
    }

    public function test_category_is_offered_as_a_filter_instead(): void
    {
        $browse = (string) file_get_contents(base_path('resources/js/Components/panels/BrowsePanel.tsx'));

        $this->assertStringContainsString("t('fields.category')", $browse);
        $this->assertStringContainsString("t('fields.subcategory')", $browse);
        $this->assertStringContainsString('category_id:e.target.value?Number(e.target.value):undefined', $browse);
    }

    /**
     * Category administration did not move into the filter: it stays in
     * Settings, which is where it already was.
     */
    public function test_categories_are_still_administered_from_settings(): void
    {
        $settings = (string) file_get_contents(base_path('resources/js/Components/panels/SettingsPanel.tsx'));

        $this->assertStringContainsString("onCategoryModal({mode:'add-subcategory'", $settings);
    }

    public function test_an_old_panel_link_still_opens_something(): void
    {
        $this->actingAs($this->admin())->get('/?panel=upload')->assertSuccessful();
        $this->actingAs($this->admin())->get('/?panel=browse')->assertSuccessful();
        $this->actingAs($this->admin())->get('/?panel=folders')->assertSuccessful();
    }
}
