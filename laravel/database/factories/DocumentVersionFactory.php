<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\DocumentSchema;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentVersion> */
class DocumentVersionFactory extends Factory
{
    public function definition(): array
    {
        return ['document_id' => Document::factory(), 'user_id' => 'test-doc-owner', 'revision' => 1, 'snapshot_json' => ['title' => 'เอกสารทดสอบ', 'content' => DocumentSchema::blankContent(), 'pageSettings' => DocumentSchema::defaultPageSettings(), 'objects' => [], 'schemaVersion' => 1]];
    }
}
