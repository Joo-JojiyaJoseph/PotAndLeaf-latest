<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Stores an uploaded image on the public disk and returns its URL.
 *  Used by product galleries and supplier/customer/company photos. */
class UploadController extends Controller
{
    use ApiResponse;

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $path = $request->file('file')->store('uploads', 'public');

        return $this->created([
            'path' => $path,
            'url'  => url(Storage::disk('public')->url($path)),
        ], 'File uploaded.');
    }
}
