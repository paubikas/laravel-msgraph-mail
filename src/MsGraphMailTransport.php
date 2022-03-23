<?php


namespace LaravelMsGraphMail;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Cache;
use LaravelMsGraphMail\Exceptions\CouldNotGetToken;
use LaravelMsGraphMail\Exceptions\CouldNotReachService;
use LaravelMsGraphMail\Exceptions\CouldNotSendMail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

class MsGraphMailTransport extends AbstractTransport
{

    /**
     * @var string
     */
    protected string $tokenEndpoint = 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token';

    /**
     * @var string
     */
    protected string $apiEndpoint = 'https://graph.microsoft.com/v1.0/users/{from}/sendMail';

    /**
     * @var array
     */
    protected array $config;

    /**
     * @var Client|ClientInterface
     */
    protected ClientInterface $http;

    /**
     * MsGraphMailTransport constructor
     * @param array $config
     * @param ClientInterface|null $client
     */
    public function __construct(array $config, ClientInterface $client = null)
    {
        $this->config = $config;
        $this->http = $client ?? new Client();
    }

    /**
     * Send given email message
     * @param SentMessage $message
     * @param null $failedRecipients
     * @return int
     * @throws CouldNotSendMail
     * @throws CouldNotReachService
     */
    public function doSend(SentMessage $message): void
    {

        $payload = $this->getPayload($message);
        $url = str_replace('{from}', urlencode($payload['from']['emailAddress']['address']), $this->apiEndpoint);

        try {
            $response = $this->http->post($url, [
                'headers' => $this->getHeaders(),
                'json' => [
                    'message' => $payload,
                ],
            ]);
        } catch (BadResponseException $e) {
            // The API responded with 4XX or 5XX error
            if ($e->hasResponse()) $response = json_decode((string)$e->getResponse()->getBody());
            throw CouldNotSendMail::serviceRespondedWithError($response->error->code ?? 'Unknown', $response->error->message ?? 'Unknown error');
        } catch (ConnectException $e) {
            // A connection error (DNS, timeout, ...) occurred
            throw CouldNotReachService::networkError();
        } catch (Throwable $e) {
            throw CouldNotReachService::unknownError();
        }
    }
    public function __toString(): string
    {
        return 'microsoft-graph';
    }
    /**
     * Transforms given Symfony message instance into
     * Microsoft Graph message object
     * @param SentMessage $message
     * @return array
     */
    protected function getPayload(SentMessage $message): array
    {
        $message = MessageConverter::toEmail($message->getOriginalMessage());

        $from = $message->getFrom();
        $priority = $message->getPriority();
        $attachments = $message->getAttachments();
        return array_filter([
            'subject' => $message->getSubject(),
            'sender' => $this->toRecipientCollection($from)[0],
            'from' => $this->toRecipientCollection($from)[0],
            'replyTo' => $this->toRecipientCollection($message->getReplyTo()),
            'toRecipients' => $this->toRecipientCollection($message->getTo()),
            'ccRecipients' => $this->toRecipientCollection($message->getCc()),
            'bccRecipients' => $this->toRecipientCollection($message->getBcc()),
            'importance' => $priority === 3 ? 'Normal' : ($priority < 3 ? 'Low' : 'High'),
            'body' => [
                'contentType' => ($message->getHtmlBody()) ? 'html' : 'text',
                'content' => ($message->getHtmlBody()) ? $message->getHtmlBody() : $message->getTextBody(),
            ],
            'attachments' => $this->toAttachmentCollection($attachments),
        ]);
    }

    /**
     * Transforms given SimpleMessage recipients into
     * Microsoft Graph recipients collection
     * @param array|string $recipients
     * @return array
     */
    protected function toRecipientCollection($recipients): array
    {
        $collection = [];

        // If the provided list is empty
        // return an empty collection
        if (!$recipients) {
            return $collection;
        }

        // Some fields yield single e-mail
        // addresses instead of arrays
        if (is_string($recipients)) {
            $collection[] = [
                'emailAddress' => [
                    'name' => null,
                    'address' => $recipients,
                ],
            ];

            return $collection;
        }

        foreach ($recipients as $index => $recipient) {

            $collection[] = [
                'emailAddress' => [
                    'name' => $recipient->getName(),
                    'address' => $recipient->getAddress(),
                ],
            ];
        }
        return $collection;
    }

    /**
     * Transforms given SymfonyMailer children into
     * Microsoft Graph attachment collection
     * @param $attachments
     * @return array
     */
    protected function toAttachmentCollection($attachments): array
    {
        $collection = [];

        foreach ($attachments as $attachment) {
            if (!$attachment instanceof DataPart) {
                continue;
            }
            $collection[] = [
                'name' => $attachment->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename'),
                'contentType' => $attachment->getPreparedHeaders()->getHeaderParameter('Content-Type', 'value'),
                'contentBytes' => base64_encode($attachment->getBody()),
                'size' => strlen($attachment->getBody()),
                '@odata.type' => '#microsoft.graph.fileAttachment',
            ];
        }

        return $collection;
    }

    /**
     * Returns header collection for API request
     * @return string[]
     * @throws CouldNotGetToken
     * @throws CouldNotReachService
     */
    protected function getHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];
    }

    /**
     * Returns API access token
     * @return string
     * @throws CouldNotReachService
     * @throws CouldNotGetToken
     */
    protected function getAccessToken(): string
    {
        try {
            return Cache::remember('mail-msgraph-accesstoken', 45, function () {
                $url = str_replace('{tenant}', $this->config['tenant'] ?? 'common', $this->tokenEndpoint);
                $response = $this->http->post($url, [
                    'form_params' => [
                        'client_id' => $this->config['client'],
                        'client_secret' => $this->config['secret'],
                        'scope' => 'https://graph.microsoft.com/.default',
                        'grant_type' => 'client_credentials',
                    ],
                ]);

                $response = json_decode((string)$response->getBody());
                return $response->access_token;
            });
        } catch (BadResponseException $e) {
            // The endpoint responded with 4XX or 5XX error
            $response = json_decode((string)$e->getResponse()->getBody());
            throw CouldNotGetToken::serviceRespondedWithError($response->error, $response->error_description);
        } catch (ConnectException $e) {
            // A connection error (DNS, timeout, ...) occurred
            throw CouldNotReachService::networkError();
        } catch (Throwable $e) {
            // An unknown error occurred
            throw CouldNotReachService::unknownError();
        }
    }
}
