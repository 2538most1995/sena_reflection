<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DocumentRuntimeUnavailableException extends RuntimeException
{
    public const PUBLIC_MESSAGE = 'ระบบสร้างเอกสารยังไม่พร้อมใช้งานชั่วคราว ข้อมูลบันทึกยังอยู่ กรุณาลองใหม่ภายหลังหรือติดต่อผู้ดูแลระบบ';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(self::PUBLIC_MESSAGE, 0, $previous);
    }

    public function render(Request $request): Response
    {
        $headers = ['Cache-Control' => 'private, no-store', 'Retry-After' => '60', 'X-Content-Type-Options' => 'nosniff'];
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'code' => 'document_renderer_unavailable',
                'message' => self::PUBLIC_MESSAGE,
            ], 503, $headers);
        }

        return response()->view('errors.document-runtime-unavailable', ['message' => self::PUBLIC_MESSAGE], 503, $headers);
    }
}
