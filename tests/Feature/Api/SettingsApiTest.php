<?php

namespace Tests\Feature\Api;

use App\Models\CmsPage;
use App\Models\CmsPageVersion;
use PHPUnit\Framework\Attributes\Test;

/** The app's Settings screens: legal pages, and the Account fields they edit. */
class SettingsApiTest extends MobileTestCase
{
    protected string $base = '/api/v1';

    protected function publishedTerms(): CmsPage
    {
        $page = CmsPage::create([
            'slug' => 'terms', 'type' => 'terms', 'title_en' => 'Terms', 'title_hi' => 'नियम',
            'content_en' => 'Published words', 'content_hi' => 'प्रकाशित', 'version' => 1,
            'is_published' => true, 'published_at' => now(),
        ]);
        CmsPageVersion::create([
            'cms_page_id' => $page->id, 'version' => 1, 'title_en' => 'Terms', 'title_hi' => 'नियम',
            'content_en' => 'Published words', 'content_hi' => 'प्रकाशित',
        ]);

        return $page;
    }

    #[Test]
    public function a_legal_page_is_readable_without_signing_in(): void
    {
        $this->publishedTerms();

        $this->getJson("{$this->base}/pages/terms")
            ->assertOk()
            ->assertJsonPath('data.title', 'Terms')
            ->assertJsonPath('data.content', 'Published words')
            ->assertJsonPath('data.version', 1);

        $this->getJson("{$this->base}/pages/terms?lang=hi")->assertJsonPath('data.content', 'प्रकाशित');
    }

    #[Test]
    public function an_unpublished_edit_never_reaches_the_app(): void
    {
        $page = $this->publishedTerms();
        // The admin is mid-edit: the row changes, the published snapshot does not.
        $page->update(['content_en' => 'Draft words']);

        $this->getJson("{$this->base}/pages/terms")->assertJsonPath('data.content', 'Published words');
    }

    #[Test]
    public function missing_unknown_or_unpublished_pages_are_404(): void
    {
        $this->getJson("{$this->base}/pages/about")->assertNotFound();
        $this->getJson("{$this->base}/pages/nonsense")->assertNotFound();

        $this->publishedTerms()->update(['is_published' => false]);
        $this->getJson("{$this->base}/pages/terms")->assertNotFound();
    }

    #[Test]
    public function account_can_change_the_birthdate_but_not_to_under_18(): void
    {
        $this->actingAsUser($this->makeUser('Grown'));

        $this->patchJson("{$this->base}/profile", ['date_of_birth' => '2002-05-10'])
            ->assertOk()
            ->assertJsonPath('data.user.date_of_birth', '2002-05-10');

        $this->patchJson("{$this->base}/profile", ['date_of_birth' => now()->subYears(10)->toDateString()])
            ->assertStatus(422);
    }

    #[Test]
    public function account_can_change_the_gender_to_a_sign_up_choice_only(): void
    {
        $this->actingAsUser($this->makeUser('Someone'));

        $this->patchJson("{$this->base}/profile", ['gender' => 'female'])
            ->assertOk()
            ->assertJsonPath('data.user.gender', 'female');
        $this->patchJson("{$this->base}/profile", ['gender' => 'undisclosed'])->assertOk();
        $this->patchJson("{$this->base}/profile", ['gender' => 'robot'])->assertStatus(422);
    }
}
