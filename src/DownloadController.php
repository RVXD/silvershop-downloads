<?php

declare(strict_types=1);

namespace SilverShop\Downloads;

use SilverShop\Model\Order;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPStreamResponse;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Serves downloadable files behind an ownership check. Files live in the protected asset store and are never
 * exposed by a public URL — every download is streamed through here after verifying access, then within the
 * download limit / expiry window.
 *
 * Access is granted either to a logged-in customer who owns a paid order containing the product, or — for guest
 * checkout — through a link carrying the order id and the order's secret token.
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
        $download = Download::get()->byID((int) $request->param('ID'));
        if (!$download || !$download->exists()) {
            return $this->httpError(404);
        }

        $member = Security::getCurrentUser();
        $orderId = (int) $request->getVar('order');
        $token = (string) $request->getVar('token');

        // Guest access via the tokenised link, or a logged-in customer who owns a paid order for it.
        if ($orderId && $token && $download->canDownloadViaToken($orderId, $token)) {
            $order = Order::get()->byID($orderId);
        } elseif ($member && $download->canDownloadFile($member)) {
            $order = $download->grantingOrderFor($member);
        } elseif (!$member) {
            return Security::permissionFailure(
                $this,
                _t(self::class . '.LoginRequired', 'Please log in to access your downloads.')
            );
        } else {
            return $this->httpError(403, _t(self::class . '.Denied', 'You do not have access to this download.'));
        }

        $file = $download->File();
        if (!$file || !$file->exists()) {
            return $this->httpError(404);
        }

        $this->logDownload($download, $member, $order, $request);

        $response = HTTPStreamResponse::create($file->getStream(), (int) $file->getAbsoluteSize());
        $response->addHeader('Content-Type', 'application/octet-stream');
        $response->addHeader(
            'Content-Disposition',
            'attachment; filename="' . addslashes(basename((string) $file->getFilename())) . '"'
        );

        return $response;
    }

    private function logDownload(Download $download, ?Member $member, ?Order $order, HTTPRequest $request): void
    {
        $log = DownloadLog::create();
        $log->DownloadID = $download->ID;
        if ($member) {
            $log->MemberID = $member->ID;
        }
        if ($order) {
            $log->OrderID = $order->ID;
        }
        $log->IPAddress = $request->getIP();
        $log->write();
    }
}
