<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Design;
use App\Models\DesignImage;
use App\Services\Media\ImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DesignImageController extends Controller
{
    public function __construct(private ImageStore $images) {}

    public function store(Request $request, Design $design): JsonResponse
    {
        $request->validate([
            'images' => ['required', 'array', 'max:10'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $sort = (int) $design->images()->max('sort');
        $created = [];

        foreach ($request->file('images') as $file) {
            $paths = $this->images->store($file, $design->tenant_id, "designs/{$design->id}");
            $created[] = $design->images()->create($paths + ['sort' => ++$sort]);
        }

        return response()->json(['data' => $created], 201);
    }

    public function destroy(Design $design, DesignImage $image): JsonResponse
    {
        abort_unless($image->design_id === $design->id, 404);

        $image->delete();
        $this->images->delete($image->path, $image->thumb_path);

        return response()->json(['ok' => true]);
    }
}
