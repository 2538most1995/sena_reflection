<?php

namespace Database\Factories;

use App\Models\Document;
use App\Services\DocumentSchema;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => 'test-doc-owner', 'title' => fake()->sentence(3), 'content_json' => DocumentSchema::blankContent(), 'page_settings_json' => DocumentSchema::defaultPageSettings(), 'objects_json' => [], 'schema_version' => 1, 'revision' => 1];
    }
}
