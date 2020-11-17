<?php

declare(strict_types=1);

namespace Yproximite\WannaSpeakBundle\Api;

use Yproximite\WannaSpeakBundle\HttpClientInterface;

class Statistics implements StatisticsInterface
{
    private $client;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
    }

    public function did(/* TODO: implement parameters */)
    {
        $this->client->request(self::API, 'did', []);
    }
}
