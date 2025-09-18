<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FileControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test directories
        if (!File::exists(public_path('photos'))) {
            File::makeDirectory(public_path('photos'), 0755, true);
        }
        if (!File::exists(public_path('certificates'))) {
            File::makeDirectory(public_path('certificates'), 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test files
        $testFiles = [
            public_path('photos/test-photo.jpg'),
            public_path('certificates/test-cert.pdf'),
        ];
        
        foreach ($testFiles as $file) {
            if (File::exists($file)) {
                File::delete($file);
            }
        }
        
        parent::tearDown();
    }

    public function test_can_serve_existing_photo()
    {
        // Create a test image file
        $testImagePath = public_path('photos/test-photo.jpg');
        File::put($testImagePath, 'fake image content');

        $response = $this->get('/api/photos/test-photo.jpg');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');
        $response->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_can_serve_existing_certificate()
    {
        // Create a test certificate file
        $testCertPath = public_path('certificates/test-cert.pdf');
        File::put($testCertPath, 'fake pdf content');

        $response = $this->get('/api/certificates/test-cert.pdf');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_returns_404_for_non_existent_photo()
    {
        $response = $this->get('/api/photos/non-existent.jpg');

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'File not found'
        ]);
    }

    public function test_returns_404_for_non_existent_certificate()
    {
        $response = $this->get('/api/certificates/non-existent.pdf');

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'File not found'
        ]);
    }

    public function test_prevents_directory_traversal_attacks()
    {
        $maliciousFilenames = [
            '../../../etc/passwd',
            '..\\..\\..\\windows\\system32\\config\\sam',
            './../../../config/database.php',
            '/etc/passwd',
            'C:\\windows\\system32\\config\\sam'
        ];

        foreach ($maliciousFilenames as $filename) {
            $response = $this->get('/api/photos/' . urlencode($filename));
            $response->assertStatus(400);
            $response->assertJson([
                'success' => false,
                'message' => 'Invalid filename'
            ]);
        }
    }

    public function test_validates_filename_characters()
    {
        $invalidFilenames = [
            'file with spaces.jpg',
            'file|with|pipes.jpg',
            'file<with>brackets.jpg',
            'file"with"quotes.jpg',
            'file*with*asterisk.jpg'
        ];

        foreach ($invalidFilenames as $filename) {
            $response = $this->get('/api/photos/' . urlencode($filename));
            $response->assertStatus(400);
            $response->assertJson([
                'success' => false,
                'message' => 'Invalid filename'
            ]);
        }
    }

    public function test_allows_valid_filename_characters()
    {
        $validFilenames = [
            'photo.jpg',
            'photo-1.jpg',
            'photo_1.jpg',
            'photo.123.jpg',
            'PHOTO.JPG',
            'certificate.pdf',
            'cert-123.pdf'
        ];

        foreach ($validFilenames as $filename) {
            // Since files don't exist, we expect 404, but not 400 (invalid filename)
            $response = $this->get('/api/photos/' . $filename);
            $response->assertStatus(404); // File not found, but filename is valid
        }
    }

    public function test_includes_proper_cors_headers()
    {
        // Create a test image file
        $testImagePath = public_path('photos/cors-test.jpg');
        File::put($testImagePath, 'fake image content for CORS test');

        $response = $this->get('/api/photos/cors-test.jpg');

        $response->assertStatus(200);
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $response->assertHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->assertHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        $response->assertHeader('Cache-Control', 'public, max-age=3600');
    }
}