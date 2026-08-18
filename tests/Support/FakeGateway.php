<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests\Support;

use Rasuvaeff\Payments\CancelGatewayInterface;
use Rasuvaeff\Payments\CapabilitySet;
use Rasuvaeff\Payments\CaptureGatewayInterface;
use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\ConfirmGatewayInterface;
use Rasuvaeff\Payments\CreatePaymentRequest;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\Payments\ProviderRequestInfo;
use Rasuvaeff\Payments\RefundAttempt;
use Rasuvaeff\Payments\RefundGatewayInterface;
use Rasuvaeff\Payments\RefundReference;
use Rasuvaeff\Payments\RefundState;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\Payments\RetrieveRefundRequest;
use Rasuvaeff\Payments\WebhookCapability;

final class FakeGateway implements PaymentGatewayInterface, CaptureGatewayInterface, ConfirmGatewayInterface, CancelGatewayInterface, RefundGatewayInterface
{
    public ?object $lastRequest = null;

    public function __construct(private readonly PaymentProvider $paymentProvider) {}

    #[\Override]
    public function provider(): PaymentProvider
    {
        return $this->paymentProvider;
    }

    #[\Override]
    public function capabilities(): CapabilitySet
    {
        return CapabilitySet::of(new WebhookCapability());
    }

    #[\Override]
    public function createPayment(CreatePaymentRequest $request): PaymentAttempt
    {
        $this->lastRequest = $request;

        return $this->paymentAttempt(operationId: $request->operationId, amount: $request->amount);
    }

    #[\Override]
    public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt
    {
        $this->lastRequest = $request;

        return $this->paymentAttempt(operationId: $request->operationId);
    }

    #[\Override]
    public function capturePayment(CapturePaymentRequest $request): PaymentAttempt
    {
        $this->lastRequest = $request;

        return $this->paymentAttempt(operationId: $request->operationId, amount: $request->amount);
    }

    #[\Override]
    public function confirmPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        $this->lastRequest = $request;

        return $this->paymentAttempt(operationId: $request->operationId);
    }

    #[\Override]
    public function cancelPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        $this->lastRequest = $request;

        return $this->paymentAttempt(operationId: $request->operationId);
    }

    #[\Override]
    public function createRefund(CreateRefundRequest $request): RefundAttempt
    {
        $this->lastRequest = $request;

        return $this->refundAttempt(operationId: $request->operationId, payment: $request->payment);
    }

    #[\Override]
    public function retrieveRefund(RetrieveRefundRequest $request): RefundAttempt
    {
        $this->lastRequest = $request;

        return $this->refundAttempt(operationId: $request->operationId);
    }

    private function paymentAttempt(OperationId $operationId, ?Money $amount = null): PaymentAttempt
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new PaymentAttempt(
            operationId: $operationId,
            provider: $this->paymentProvider,
            payment: new PaymentReference(provider: $this->paymentProvider, id: 'pay_1'),
            amount: $amount ?? new Money(minorUnits: 100, currency: 'USD'),
            state: PaymentState::Succeeded,
            rawStatus: 'succeeded',
            createdAt: $now,
            updatedAt: $now,
            requestInfo: new ProviderRequestInfo(receivedAt: $now),
        );
    }

    private function refundAttempt(OperationId $operationId, ?PaymentReference $payment = null): RefundAttempt
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $payment ??= new PaymentReference(provider: $this->paymentProvider, id: 'pay_1');

        return new RefundAttempt(
            operationId: $operationId,
            provider: $this->paymentProvider,
            refund: new RefundReference(provider: $this->paymentProvider, id: 'ref_1'),
            payment: $payment,
            requestedAmount: new Money(minorUnits: 100, currency: 'USD'),
            actualAmount: new Money(minorUnits: 100, currency: 'USD'),
            state: RefundState::Succeeded,
            rawStatus: 'succeeded',
            createdAt: $now,
            updatedAt: $now,
            requestInfo: new ProviderRequestInfo(receivedAt: $now),
        );
    }
}
