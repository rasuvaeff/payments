<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\CaptureMethod;
use Rasuvaeff\Payments\ConfirmationMethod;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\PaymentMethodReference;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CreatePaymentRequest::class)]
final class CreatePaymentRequestTest
{
    private const string UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function defaultsToAutomaticProcessing(): void
    {
        $request = new CreatePaymentRequest(
            operationId: Fixtures::operation(),
            amount: Fixtures::money(),
            paymentMethod: new PaymentMethodReference(id: 'pm_123'),
        );

        Assert::same($request->captureMethod, CaptureMethod::Automatic);
        Assert::same($request->confirmationMethod, ConfirmationMethod::Automatic);
    }

    public function keepsManualProcessingMethods(): void
    {
        $request = new CreatePaymentRequest(
            operationId: Fixtures::operation(),
            amount: Fixtures::money(),
            captureMethod: CaptureMethod::Manual,
            confirmationMethod: ConfirmationMethod::Manual,
        );

        Assert::same($request->captureMethod, CaptureMethod::Manual);
        Assert::same($request->confirmationMethod, ConfirmationMethod::Manual);
    }

    public function keepsAGivenIdempotencyKeyVerbatim(): void
    {
        $request = new CreatePaymentRequest(
            operationId: Fixtures::operation(),
            amount: Fixtures::money(),
            idempotencyKey: 'order-42-charge-1',
        );

        Assert::same($request->idempotencyKey, 'order-42-charge-1');
    }

    /**
     * Without a key the request mints a UUID v4 — 36 characters, within
     * PayPal's 108 — so that retrying the same request object is safe.
     */
    public function generatesAUuidV4KeyWhenNoneIsGiven(): void
    {
        $keys = [];

        for ($i = 0; $i < 512; ++$i) {
            $key = $this->request()->idempotencyKey;
            Assert::true(preg_match(self::UUID_V4, $key) === 1);
            $keys[$key] = true;
        }

        Assert::same(\count($keys), 512);
    }

    /**
     * Only the version nibble and the two variant bits are fixed; every other
     * bit stays random. A mask that also pins a neighbouring bit still
     * matches the UUID pattern, so the spread of the characters next to the
     * fixed ones is checked as well.
     */
    public function keepsEveryNonFixedBitOfTheGeneratedKeyRandom(): void
    {
        $seen = [15 => [], 19 => [], 20 => []];

        for ($i = 0; $i < 512; ++$i) {
            $key = $this->request()->idempotencyKey;

            foreach (array_keys($seen) as $position) {
                $seen[$position][$key[$position]] = true;
            }
        }

        Assert::same(\count($seen[15]), 16);
        Assert::same(\count($seen[19]), 4);
        Assert::same(\count($seen[20]), 16);
    }

    public function rejectsEmptyIdempotencyKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new CreatePaymentRequest(
            operationId: Fixtures::operation(),
            amount: Fixtures::money(),
            paymentMethod: new PaymentMethodReference(id: 'pm_123'),
            idempotencyKey: '',
        );
    }

    public function rejectsEmptyDescription(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->request(description: '');
    }

    public function keepsScalarMetadata(): void
    {
        $request = $this->request(metadata: ['order_id' => '42', 'retry' => false, 'note' => null]);

        Assert::same($request->metadata['order_id'], '42');
        Assert::false($request->metadata['retry']);
        Assert::null($request->metadata['note']);
    }

    public function rejectsNonScalarMetadataValue(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->request(metadata: ['customer' => new \stdClass()]);
    }

    public function rejectsNestedMetadataValue(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->request(metadata: ['items' => ['sku' => 1]]);
    }

    public function rejectsNumericMetadataKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->request(metadata: [0 => 'value']);
    }

    public function rejectsEmptyMetadataKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        $this->request(metadata: ['' => 'value']);
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private function request(?string $description = null, array $metadata = []): CreatePaymentRequest
    {
        return new CreatePaymentRequest(
            operationId: Fixtures::operation(),
            amount: Fixtures::money(),
            paymentMethod: new PaymentMethodReference(id: 'pm_123'),
            description: $description,
            metadata: $metadata,
        );
    }

    /**
     * The key is forwarded verbatim into an `Idempotency-Key` header. A value
     * carrying a line break or a control byte is refused here, at the boundary
     * that owns it, instead of surfacing later as a PSR-7 transport error that
     * names neither the field nor the request.
     *
     * @param non-empty-string $key
     */
    #[DataProvider('headerUnsafeKeyProvider')]
    public function rejectsHeaderUnsafeIdempotencyKeys(string $key): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new CreatePaymentRequest(
            operationId: Fixtures::operation(),
            amount: Fixtures::money(),
            paymentMethod: new PaymentMethodReference(id: 'pm_123'),
            idempotencyKey: $key,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function headerUnsafeKeyProvider(): iterable
    {
        yield 'carriage return' => ["key\r\nX-Injected: 1"];
        yield 'newline' => ["key\nvalue"];
        yield 'null byte' => ["key\0value"];
        yield 'space' => ['key value'];
        yield 'delete byte' => ["key\x7Fvalue"];
    }

    /**
     * Omitting the payment method is the deferred flow — the provider creates
     * the payment with nothing attached and the customer picks a method
     * client-side.
     */
    public function acceptsARequestWithoutAPaymentMethod(): void
    {
        $request = new CreatePaymentRequest(
            operationId: Fixtures::operation(),
            amount: Fixtures::money(),
        );

        Assert::null($request->paymentMethod);
    }

    /**
     * A key is kept byte for byte when it is a header token of at most 255
     * bytes and refused otherwise — never trimmed, normalised or replaced
     * by a generated one.
     */
    #[Property(runs: 300)]
    public function keepsAValidKeyVerbatimAndRefusesTheRest(string $key): void
    {
        $valid = $key !== '' && \strlen($key) <= 255 && preg_match('/[\x00-\x20\x7F]/', $key) === 0;
        Classify::cover($valid, 'valid', 20);
        Classify::cover(!$valid, 'refused', 15);

        try {
            $request = new CreatePaymentRequest(operationId: Fixtures::operation(), amount: Fixtures::money(), idempotencyKey: $key);
            Assert::true($valid);
            Assert::same($request->idempotencyKey, $key);
        } catch (\InvalidArgumentException) {
            Assert::false($valid);
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function keepsAValidKeyVerbatimAndRefusesTheRestGenerators(): array
    {
        return ['key' => Gen::frequency([
            [3, Gen::stringMatching('[A-Za-z0-9._~:-]{1,40}')],
            [1, Gen::naughtyString()],
            [1, Gen::stringFrom("k \t\x7F", 1, 10)],
            [1, Gen::stringFrom('k', 250, 260)],
        ])];
    }

    /** @return iterable<string, array{string}> */
    public static function keepsAValidKeyVerbatimAndRefusesTheRestExamples(): iterable
    {
        yield 'maximum' => [str_repeat('k', 255)];
        yield 'one over' => [str_repeat('k', 256)];
        yield 'uuid' => ['550e8400-e29b-41d4-a716-446655440000'];
        yield 'trailing newline' => ["key\n"];
    }
}
