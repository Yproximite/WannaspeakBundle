<?php

declare(strict_types=1);

namespace Yproximite\WannaSpeakBundle;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Yproximite\WannaSpeakBundle\Exception\Api\UnknownException;
use Yproximite\WannaSpeakBundle\Exception\Api\WannaSpeakApiException;
use Yproximite\WannaSpeakBundle\Exception\Api\WannaSpeakApiExceptionInterface;
use Yproximite\WannaSpeakBundle\Exception\InvalidResponseException;
use Yproximite\WannaSpeakBundle\Exception\TestModeException;

class HttpClient implements HttpClientInterface
{
    private $accountId;
    private $secretKey;
    private $test;
    private $client;
    private $logger;

    public function __construct(
        string $accountId,
        string $secretKey,
        string $baseUri,
        bool $test,
        \Symfony\Contracts\HttpClient\HttpClientInterface $client,
        ?LoggerInterface $logger = null
    ) {
        $this->accountId = $accountId;
        $this->secretKey = $secretKey;
        $this->test      = $test;
        $this->client    = ScopingHttpClient::forBaseUri($client, $baseUri);
        $this->logger    = $logger ?? new NullLogger();
    }

    public function request(string $api, string $method, array $arguments = []): ResponseInterface
    {
        $this->logger->info('[wanna-speak] Requesting WannaSpeak API {api} with method {method}.', [
            'api'       => $api,
            'method'    => $method,
            'arguments' => $arguments,
        ]);

        if ($this->test) {
            throw new TestModeException();
        }

        $response = $this->doRequest($api, $method, $arguments);

        $this->handleResponse($response);

        return $response;
    }

    /**
     * @param array<string,mixed> $additionalArguments Additional WannaSpeak request arguments
     * @throws WannaSpeakApiExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function doRequest(string $api, string $method, array $additionalArguments = []): ResponseInterface
    {
        $fields = array_merge($additionalArguments, [
            'id'     => $this->accountId,
            'key'    => $this->getAuthKey(),
            'api'    => $api,
            'method' => $method,
        ]);

        // Prevent FormDataPart to throw when encountering a non-string value
        $fields = array_reduce(array_keys($fields), function (array $acc, string $fieldKey) use ($fields) {
            $fieldValue = $fields[$fieldKey];

            if (true === $fieldValue) {
                $fieldValue = '1';
            } elseif (false === $fieldValue) {
                $fieldValue = '0';
            } elseif (is_int($fieldValue)) {
                $fieldValue = (string) $fieldValue;
            }

            $acc[$fieldKey] = $fieldValue;

            return $acc;
        }, []);

        $formData = new FormDataPart($fields);

        $options = [
            'headers' => $formData->getPreparedHeaders()->toArray(),
            'body'    => $formData->bodyToIterable(),
        ];

        return $this->client->request('POST', '', $options);
    }

    private function getAuthKey(): string
    {
        $timeStamp = time();

        return $timeStamp.'-'.md5($this->accountId.$timeStamp.$this->secretKey);
    }

    /**
     * @throws WannaSpeakApiExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function handleResponse(ResponseInterface $response): void
    {
        $responseData = $response->toArray();

        if (array_key_exists('error', $responseData)) {
            if (null === $responseData['error']) {
                return;
            }

            if (is_string($responseData['error'])) {
                throw WannaSpeakApiException::create(-1, $responseData['error']);
            }

            if (is_array($responseData['error']) && array_key_exists('nb', $responseData['error'])) {
                $statusCode = $responseData['error']['nb'];
                // Not possible with JSON format, but just in case of...
                if (200 === $statusCode) {
                    return;
                }

                throw WannaSpeakApiException::create($statusCode, $responseData['error']['txt'] ?? 'No message.');
            }

            throw new InvalidResponseException(sprintf('Unable to handle field "error" from the response, value is: "%s".', get_debug_type($responseData['error'])));
        }
    }
}
