<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileController extends BaseController
{
    /**
     * Serve a photo file from the photos directory.
     *
     * @param string $filename
     * @return \Illuminate\Http\Response|BinaryFileResponse
     */
    public function getPhoto($filename)
    {
        return $this->serveFile($filename, 'photos');
    }

    /**
     * Serve a certificate file from the certificates directory.
     *
     * @param string $filename
     * @return \Illuminate\Http\Response|BinaryFileResponse
     */
    public function getCertificate($filename)
    {
        return $this->serveFile($filename, 'certificates');
    }

    /**
     * Serve a file from the specified directory with security validation.
     *
     * @param string $filename
     * @param string $directory
     * @return \Illuminate\Http\Response|BinaryFileResponse
     */
    private function serveFile($filename, $directory)
    {
        try {
            // Security validation: prevent directory traversal attacks
            if ($this->isInvalidFilename($filename)) {
                return $this->sendError('Invalid filename', [], 400);
            }

            // Construct the full file path
            $filePath = public_path($directory . '/' . $filename);

            // Check if file exists and is within the allowed directory
            if (!File::exists($filePath) || !$this->isWithinAllowedDirectory($filePath, $directory)) {
                return $this->sendError('File not found', [], 404);
            }

            // Get file info
            $mimeType = $this->getMimeType($filePath);
            $fileSize = File::size($filePath);

            // Create response with proper headers
            $response = Response::file($filePath, [
                'Content-Type' => $mimeType,
                'Content-Length' => $fileSize,
                'Cache-Control' => 'public, max-age=3600', // Cache for 1 hour
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            ]);

            return $response;

        } catch (\Exception $e) {
            return $this->sendError('Error serving file: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Validate filename to prevent directory traversal attacks.
     *
     * @param string $filename
     * @return bool
     */
    private function isInvalidFilename($filename)
    {
        // Check for null, empty, or dangerous characters
        if (empty($filename) || is_null($filename)) {
            return true;
        }

        // Prevent directory traversal patterns
        $dangerousPatterns = ['../', '..\\', './', '.\\', '//', '\\\\'];
        foreach ($dangerousPatterns as $pattern) {
            if (strpos($filename, $pattern) !== false) {
                return true;
            }
        }

        // Check for absolute paths
        if (substr($filename, 0, 1) === '/' || substr($filename, 1, 1) === ':') {
            return true;
        }

        // Only allow alphanumeric characters, dots, dashes, and underscores
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
            return true;
        }

        return false;
    }

    /**
     * Verify that the file path is within the allowed directory.
     *
     * @param string $filePath
     * @param string $directory
     * @return bool
     */
    private function isWithinAllowedDirectory($filePath, $directory)
    {
        $allowedPath = realpath(public_path($directory));
        $requestedPath = realpath($filePath);

        // If realpath fails, the file doesn't exist or path is invalid
        if ($allowedPath === false || $requestedPath === false) {
            return false;
        }

        // Check if the requested path starts with the allowed path
        return strpos($requestedPath, $allowedPath) === 0;
    }

    /**
     * Get MIME type for the file.
     *
     * @param string $filePath
     * @return string
     */
    private function getMimeType($filePath)
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        return isset($mimeTypes[$extension]) ? $mimeTypes[$extension] : File::mimeType($filePath) ?? 'application/octet-stream';
    }
}