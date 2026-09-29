<?php

declare(strict_types=1);

namespace SilverShop\Downloads;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPStreamResponse;
use SilverStripe\Security\Security;

/**
 * Serves downloadable files behind an ownership check. Files live in the protected asset store and are never
 * exposed by a public URL — every download is streamed through here after verifying the logged-in customer owns
 * a paid order containing the product, and is within the download limit / expiry window.
 */
class DownloadController extends Controller
{
    private static string $url_segment = 'shop-downloads';

    private static array $allowed_actions = [
        'process',
    ];

    private static array $url_handlers = [
        'process/$ID' => 'process',
    ];

    public function Link($action = null): string
    {
        return Controller::join_links(self::config()->get('url_segment'), $action);
    }

    /**
     * Stream a download to the current customer, or fail with 401/403/404.
     */
    public function process(HTTPRequest $request): HTTPResponse
    {
        $member = Security::getCurrentUser();
        if (!$member) {
            return Security::permissionFailure(
                $this,
                _t(self::class . '.LoginRequired', 'Please log in to access your downloads.')
            );
        }

        $download = Download::get()->byID((int) $request->param('ID'));
        if (!$download || !$download->exists()) {
            return $this->httpError(404);
        }

        if (!$download->canDownloadFile($member)) {
            return $this->httpError(403, _t(self::class . '.Denied', 'You do not have access to this download.'));
        }

        $file = $download->File();
        if (!$file || !$file->exists()) {
            return $this->httpError(404);
        }

        $this->logDownload($download, $member, $request);

        $response = HTTPStreamResponse::create($file->getStream(), (int) $file->getAbsoluteSize());
        $response->addHeader('Content-Type', 'application/octet-stream');
        $response->addHeader(
            'Content-Disposition',
            'attachment; filename="' . addslashes($file->getFilename()) . '"'
        );

        return $response;
    }

    private function logDownload(Download $download, $member, HTTPRequest $request): void
    {
        $log = DownloadLog::create();
        $log->DownloadID = $download->ID;
        $log->MemberID = $member->ID;
        if ($order = $download->grantingOrderFor($member)) {
            $log->OrderID = $order->ID;
        }
        $log->IPAddress = $request->getIP();
        $log->write();
    }
}
