<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UploadedImage;
use Illuminate\Http\Response;

class ImageController extends Controller
{
    public function show(UploadedImage $image): Response
    {
        $data = is_resource($image->data) ? stream_get_contents($image->data) : $image->data;

        return response($data, 200, [
            'Content-Type' => $image->mime_type,
            'Content-Length' => (string) $image->size,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
