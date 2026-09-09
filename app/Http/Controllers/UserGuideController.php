<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\UserGuideService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserGuideController extends Controller
{
    public function index(Request $request, UserGuideService $guide)
    {
        $allowed = $guide->allowedPageCount($request->user());
        abort_if($allowed <= 0, 403, 'User Guide Manual is not enabled for your account.');

        return response()->json([
            'allowed_pages' => $allowed,
            'total_pages' => UserGuideService::TOTAL_PAGES,
            'pages' => $guide->pagesFor($request->user()),
            'document_url' => $allowed === UserGuideService::TOTAL_PAGES ? route('user-guide.document') : null,
            'html_url' => route('user-guide.html'),
        ]);
    }

    public function html(Request $request, UserGuideService $guide, AuditService $audit)
    {
        $allowed = $guide->allowedPageCount($request->user());
        abort_if($allowed <= 0, 403, 'User Guide Manual is not enabled for your account.');
        $audit->log($request, 'user-guide.html.viewed', null, 'Authorized User Guide HTML view opened.', ['allowed_pages' => $allowed]);

        return response($guide->printableHtml($request->user()), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; base-uri 'none'; frame-ancestors 'self'",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function document(Request $request, UserGuideService $guide, AuditService $audit): BinaryFileResponse
    {
        $allowed = $guide->allowedPageCount($request->user());
        abort_if($allowed <= 0, 403, 'User Guide Manual is not enabled for your account.');

        // The DOCX contains the complete manual and therefore is available only when the
        // current user is entitled to every logical page. Partial-page users use the
        // server-filtered HTML/portal view, preventing rights bypass through a full DOCX.
        abort_if($allowed < UserGuideService::TOTAL_PAGES, 403, 'Full Word manual download requires access to all User Guide pages.');

        $path = $guide->documentPath();
        abort_unless(is_file($path) && is_readable($path), 404, 'User Guide Word document is not available in this release.');
        $audit->log($request, 'user-guide.document.downloaded', null, 'Full User Guide Word document downloaded.', ['allowed_pages' => $allowed]);

        return response()->download($path, 'Karyalay_Portal_User_Guide_v14.00.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
