<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentAsset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DocumentAsset> */
class DocumentAssetFactory extends Factory
{
    public function definition(): array
    {
        return ['document_id' => Document::factory(), 'name' => 'test-image.png', 'storage_path' => 'documents/test/'.Str::uuid().'.png', 'mime' => 'image/png', 'size' => 100, 'width' => 120, 'height' => 80];
    }
}
