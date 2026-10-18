<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Product image uploads land on the `public` disk with `visibility('public')`.
 * Filament's ->image() shorthand expands to acceptedFileTypes(['image/*']), and
 * Laravel's `mimetypes` rule treats `image/*` as a prefix wildcard
 * (ValidatesAttributes::validateMimetypes), so `image/svg+xml` passes it. An SVG
 * is an XML document that can carry <script>, so a public, same-origin SVG is
 * stored XSS. These tests pin the allowlist to raster formats and prove Laravel
 * actually rejects an SVG server-side, by content sniff rather than by the
 * client's Content-Type header.
 */
class ProductImageUploadTypesTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];

    /**
     * @return array<string, FileUpload>
     */
    private function productFileUploads(): array
    {
        // Mirrors AppPanelTenancyTest: isolate the form schema from the Shield
        // permission layer, and mount under a tenant because /app is Team-scoped.
        Gate::before(fn () => true);
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $team = $user->ownedTeams()->first();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);

        // The gallery starts empty, so add one item for its upload to exist.
        $schema = Livewire::test(CreateProduct::class)
            ->set('data.images', ['item' => ['image' => []]])
            ->instance()
            ->getSchema('form');

        $uploads = [];

        foreach ($schema->getFlatComponents(withActions: false, withHidden: true) as $component) {
            // Image uploads only; the private download file has its own allowlist.
            if ($component instanceof FileUpload && $component->getName() !== 'downloadable_file') {
                $uploads[$component->getName()] = $component;
            }
        }

        return $uploads;
    }

    /**
     * Guards the loops below from passing vacuously: if schema traversal ever
     * stops reaching a component (e.g. the images Repeater's child schema), the
     * foreach tests would silently check less and still go green.
     */
    public function test_both_product_uploads_are_discovered(): void
    {
        $this->assertSame(['featured_image', 'image'], array_keys($this->productFileUploads()));
    }

    public function test_featured_image_accepts_only_raster_web_formats(): void
    {
        $uploads = $this->productFileUploads();

        $this->assertArrayHasKey('featured_image', $uploads);
        $this->assertSame(
            self::EXPECTED_TYPES,
            $uploads['featured_image']->getAcceptedFileTypes(),
        );
    }

    public function test_no_product_upload_allows_svg_or_a_wildcard_image_type(): void
    {
        foreach ($this->productFileUploads() as $name => $upload) {
            $types = $upload->getAcceptedFileTypes() ?? [];

            $this->assertNotEmpty($types, "{$name} has no accepted file type allowlist");

            // `image/*` is what ->image() sets, and it is what lets SVG through.
            $this->assertNotContains('image/*', $types, "{$name} accepts the image/* wildcard, which permits image/svg+xml");
            $this->assertNotContains('image/svg+xml', $types, "{$name} accepts SVG");
        }
    }

    public function test_product_uploads_are_size_capped(): void
    {
        foreach ($this->productFileUploads() as $name => $upload) {
            $this->assertNotNull($upload->getMaxSize(), "{$name} has no maxSize, so uploads are unbounded");
        }
    }

    /**
     * NOTE: deliberately NOT UploadedFile::fake(). Illuminate\Http\Testing\File
     * ::getMimeType() returns MimeType::from($name) -- it reports the mime implied
     * by the *filename* and never looks at the bytes. A fake would therefore prove
     * nothing about content sniffing and would report image/png for an SVG payload
     * simply because it was named .png. A real UploadedFile in test mode goes
     * through Symfony's finfo guesser, which is what production does.
     *
     * @param  string  $clientName  the name the attacker chooses
     */
    private function realUpload(string $clientName, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload-test-');
        file_put_contents($path, $content);
        $this->tempPaths[] = $path;

        return new UploadedFile($path, $clientName, null, null, true);
    }

    /** @var array<int, string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function featuredImageRule(): string
    {
        return 'mimetypes:'.implode(',', $this->productFileUploads()['featured_image']->getAcceptedFileTypes());
    }

    /**
     * The point of the fix: the `mimetypes` rule that acceptedFileTypes()
     * registers must actually reject an SVG on the server. Uses the allowlist
     * taken from the real resource and real SVG bytes.
     */
    public function test_laravel_rejects_an_svg_against_the_resources_allowlist_server_side(): void
    {
        $rule = $this->featuredImageRule();
        $svg = $this->realUpload(
            'payload.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
        );

        $this->assertTrue(
            Validator::make(['file' => $svg], ['file' => $rule])->fails(),
            'An SVG passed the product image allowlist -- stored XSS is reachable.',
        );
    }

    public function test_the_allowlist_still_accepts_a_real_png(): void
    {
        // A real 1x1 PNG, so finfo sniffs image/png without depending on GD.
        $png = $this->realUpload(
            'photo.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='),
        );

        $this->assertSame('image/png', $png->getMimeType());
        $this->assertTrue(
            Validator::make(['file' => $png], ['file' => $this->featuredImageRule()])->passes(),
            'A legitimate PNG was rejected by the product image allowlist.',
        );
    }

    /**
     * Renaming the payload does not help: the rule validates the sniffed content
     * type (Symfony MimeTypes::guessMimeType), not the client-supplied filename
     * or Content-Type header. This is what makes the allowlist a real control.
     */
    public function test_an_svg_disguised_with_a_png_extension_is_still_rejected(): void
    {
        $disguised = $this->realUpload(
            'not-really.png',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->assertNotSame('image/png', $disguised->getMimeType(), 'content sniffing is not happening');
        $this->assertTrue(
            Validator::make(['file' => $disguised], ['file' => $this->featuredImageRule()])->fails(),
            'A renamed SVG passed the allowlist -- validation is trusting the filename or client header.',
        );
    }

    /**
     * Proves the tests above bite: the ->image() shorthand that was here before
     * (acceptedFileTypes(['image/*'])) lets the very same SVG payload through,
     * because ValidatesAttributes::validateMimetypes matches the `image/*`
     * wildcard against the sniffed type's prefix. This is the original bug,
     * pinned so nobody reintroduces ->image() thinking it is safe.
     */
    public function test_the_image_shorthand_would_have_allowed_the_svg(): void
    {
        $svg = $this->realUpload(
            'payload.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
        );

        $this->assertTrue(
            Validator::make(['file' => $svg], ['file' => 'mimetypes:image/*'])->passes(),
            'image/* no longer accepts SVG -- if this fails the vulnerability premise changed.',
        );
    }
}
