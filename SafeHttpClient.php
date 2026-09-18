<?php

namespace dokuwiki\plugin\admidioplugins;

use dokuwiki\HTTP\DokuHTTPClient;

/**
 * An HTTP client for addresses somebody typed into the wiki.
 *
 * Fetching a URL an author supplied, from the wiki server, is server-side request forgery unless
 * the target is checked: every hop, including every redirect, must resolve to public addresses
 * only. HTTPClient follows redirects by calling sendRequest() again, so checking here covers them.
 */
class SafeHttpClient extends DokuHTTPClient
{
    private bool $allowPrivate;

    public function __construct(bool $allowPrivate = false)
    {
        parent::__construct();
        $this->allowPrivate = $allowPrivate;
    }

    /** @inheritdoc */
    public function sendRequest($url, $data = '', $method = 'GET')
    {
        if (!$this->allowPrivate) {
            $reason = $this->rejectReason((string)$url);
            if ($reason !== null) {
                $this->error = $reason;
                $this->status = -100;
                return false;
            }
        }
        return parent::sendRequest($url, $data, $method);
    }

    /**
     * Why an address must not be fetched, or null if it may.
     */
    private function rejectReason(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'Only http and https addresses can be fetched.';
        }

        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === []) {
            return "The host $host could not be resolved.";
        }

        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return "The host $host resolves to a private or reserved address.";
            }
        }

        return null;
    }
}
