<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Selects a provider only for creation; existing references route by provider.
 *
 * @api
 */
final readonly class PaymentGatewayRouter
{
    public function __construct(
        private GatewayRegistry $registry,
        private GatewaySelectionPolicyInterface $selectionPolicy,
    ) {}

    public function gatewayFor(GatewaySelectionContext $context): PaymentGatewayInterface
    {
        $provider = $this->selectionPolicy->select(
            context: $context,
            availableProviders: $this->registry->providers(),
        );

        return $this->registry->get(provider: $provider);
    }

    public function createPayment(GatewaySelectionContext $context): PaymentAttempt
    {
        return $this->gatewayFor(context: $context)->createPayment(request: $context->request);
    }

    public function retrievePayment(RetrievePaymentRequest $request): PaymentAttempt
    {
        return $this->registry->get(provider: $request->payment->provider)->retrievePayment(request: $request);
    }

    public function capturePayment(CapturePaymentRequest $request): PaymentAttempt
    {
        $gateway = $this->registry->get(provider: $request->payment->provider);

        if (!$gateway instanceof CaptureGatewayInterface) {
            throw $this->unsupported(provider: $request->payment->provider, operation: 'capture');
        }

        return $gateway->capturePayment(request: $request);
    }

    public function confirmPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        $gateway = $this->registry->get(provider: $request->payment->provider);

        if (!$gateway instanceof ConfirmGatewayInterface) {
            throw $this->unsupported(provider: $request->payment->provider, operation: 'confirmation');
        }

        return $gateway->confirmPayment(request: $request);
    }

    public function cancelPayment(PaymentOperationRequest $request): PaymentAttempt
    {
        $gateway = $this->registry->get(provider: $request->payment->provider);

        if (!$gateway instanceof CancelGatewayInterface) {
            throw $this->unsupported(provider: $request->payment->provider, operation: 'cancellation');
        }

        return $gateway->cancelPayment(request: $request);
    }

    public function createRefund(CreateRefundRequest $request): RefundAttempt
    {
        return $this->refundGateway(provider: $request->payment->provider)->createRefund(request: $request);
    }

    public function retrieveRefund(RetrieveRefundRequest $request): RefundAttempt
    {
        return $this->refundGateway(provider: $request->refund->provider)->retrieveRefund(request: $request);
    }

    private function refundGateway(PaymentProvider $provider): RefundGatewayInterface
    {
        $gateway = $this->registry->get(provider: $provider);

        if (!$gateway instanceof RefundGatewayInterface) {
            throw $this->unsupported(provider: $provider, operation: 'refund');
        }

        return $gateway;
    }

    private function unsupported(PaymentProvider $provider, string $operation): \LogicException
    {
        return new \LogicException(sprintf('Payment gateway "%s" does not support %s', $provider->value, $operation));
    }
}
