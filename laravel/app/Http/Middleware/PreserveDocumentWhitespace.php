<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreserveDocumentWhitespace extends TrimStrings
{
    /** @param Request $request */
    public function handle($request, Closure $next): Response
    {
        $original = $this->except;
        if ($request->is('api/documents', 'api/documents/*')) {
            $this->except[] = '*';
        }
        if ($request->is('records/*/document', 'records/*/document/*', 'teaching-drafts/*/document/*', 'api/documents', 'api/documents/*')) {
            $this->except[] = 'content.*';
            $this->except[] = 'settings.sig1_pos';
            $this->except[] = 'settings.sig2_pos';
            $this->except[] = 'settings.sig3_pos';
        }

        try {
            return parent::handle($request, $next);
        } finally {
            $this->except = $original;
        }
    }
}
