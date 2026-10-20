<?php

namespace App\Exceptions;

use App\Models\TeachingDraft;
use App\Services\DraftService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DraftVersionConflictException extends Exception
{
    public function __construct(
        public readonly TeachingDraft $draft,
        string $message = 'ร่างนี้มีการแก้ไขจากอีกหน้าต่าง',
        public readonly string $conflictCode = 'draft_version_conflict',
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->conflictCode,
            'data' => ['draft' => app(DraftService::class)->resource($this->draft)],
        ], 409);
    }
}
