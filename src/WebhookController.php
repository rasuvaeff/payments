<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * PSR-7 HTTP adapter. It never includes provider payloads or validation reasons in responses.
 *
 * Mount this route **before** any body-parsing middleware. Signature checks
 * cover the exact received bytes, and a stream another middleware already
 * consumed reads back as an empty string — which would fail every signature
 * check with a correct secret and a correct signature. An empty body is
 * reported as its own `empty_body` outcome rather than as a signature failure,
 * so that misconfiguration is diagnosable from the response alone.
 *
 * @api
 */
final readonly class WebhookController
{
    public function __construct(
        private WebhookProcessorRegistry $registry,
        private ResponseFactoryInterface $responseFactory,
        private ?LoggerInterface $logger = null,
    ) {}

    public function handle(ServerRequestInterface $request, string $provider): ResponseInterface
    {
        try {
            $paymentProvider = new PaymentProvider(value: $provider);
        } catch (\InvalidArgumentException) {
            return $this->response(status: 404, outcome: 'provider_not_found');
        }

        if (!$this->registry->has(provider: $paymentProvider)) {
            return $this->response(status: 404, outcome: 'provider_not_found');
        }

        $rawBody = (string) $request->getBody();

        if ($rawBody === '') {
            return $this->response(status: 400, outcome: 'empty_body');
        }

        try {
            $result = $this->registry->get(provider: $paymentProvider)->process(
                input: new WebhookInput(
                    rawBody: $rawBody,
                    provider: $paymentProvider,
                    headers: $request->getHeaders(),
                    requestMetadata: [
                        'method' => $request->getMethod(),
                        'path' => $request->getUri()->getPath(),
                    ],
                ),
            );
        } catch (\Throwable $exception) {
            // The response stays opaque on purpose; without this hook a durable
            // processing failure would leave no trace at all, and detection
            // would rest entirely on someone watching the 503 rate.
            $this->logger?->error('Webhook processing failed', [
                'provider' => $paymentProvider->value,
                'exception' => $exception,
            ]);

            return $this->response(status: 503, outcome: 'processing_failed');
        }

        return $this->resultResponse(result: $result);
    }

    private function resultResponse(WebhookProcessingResult $result): ResponseInterface
    {
        return match (true) {
            $result instanceof ProcessedWebhook => $this->response(status: 204, outcome: 'processed'),
            $result instanceof ReplayedWebhookEvent => $this->response(status: 204, outcome: 'replayed'),
            $result instanceof UnknownWebhookEvent => $this->response(status: 204, outcome: 'ignored_unknown'),
            $result instanceof UnsupportedWebhookEvent => $this->response(status: 204, outcome: 'ignored_unsupported'),
            $result instanceof RejectedWebhookEvent => $this->response(status: 204, outcome: 'rejected_payload'),
            $result instanceof WebhookValidationFailed => $this->response(status: 400, outcome: 'validation_failed'),
            default => $this->response(status: 500, outcome: 'processing_failed'),
        };
    }

    /** @param non-empty-string $outcome */
    private function response(int $status, string $outcome): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(code: $status)
            ->withHeader('X-Payments-Webhook-Outcome', $outcome);
    }
}
