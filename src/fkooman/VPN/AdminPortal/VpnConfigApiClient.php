<?php

namespace fkooman\VPN\AdminPortal;

use GuzzleHttp\Client;

class VpnConfigApiClient
{
    /** @var \GuzzleHttp\Client */
    private $client;

    /** @var string */
    private $vpnConfigApiUri;

    public function __construct(Client $client, $vpnConfigApiUri)
    {
        $this->client = $client;
        $this->vpnConfigApiUri = $vpnConfigApiUri;
    }

    public function getCertList($userId = null)
    {
        if (is_null($userId)) {
            $requestUri = sprintf('%s/config', $this->vpnConfigApiUri);
        } else {
            $requestUri = sprintf('%s/config?userId=%s', $this->vpnConfigApiUri, $userId);
        }

        return $this->client->get($requestUri)->json();
    }
}
