<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\CancelGatewayInterface;
use Rasuvaeff\Payments\CaptureGatewayInterface;
use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\ConfirmGatewayInterface;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\FixedGatewaySelectionPolicy;
use Rasuvaeff\Payments\GatewayRegistry;
use Rasuvaeff\Payments\GatewaySelectionContext;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentAttempt;
use Rasuvaeff\Payments\PaymentGatewayInterface;
use Rasuvaeff\Payments\PaymentGatewayRouter;
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
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(PaymentGatewayRouter::class)]
#[Covers(FixedGatewaySelectionPolicy::class)]
final class PaymentGatewayRouterTest
{
    private PaymentGatewayRouter $router;

    private PaymentGatewayInterface $gateway;

    private PaymentProvider $provider;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->provider = new PaymentProvider(value: 'stripe');
        $this->gateway = Understudy::for(
            PaymentGatewayInterface::class,
            CaptureGatewayInterface::class,
            ConfirmGatewayInterface::class,
            CancelGatewayInterface::class,
            RefundGatewayInterface::class,
        );
        $this->stubOperations();
        $this->router = new PaymentGatewayRouter(
            registry: new GatewayRegistry(gateways: [$this->gateway]),
            selectionPolicy: new FixedGatewaySelectionPolicy(provider: $this->provider),
        );
    }

    public function selectsGatewayForNewPayment(): void
    {
        $context = new GatewaySelectionContext(request: PaymentFixtures::createRequest(), tenantId: 'tenant_1');
        $attempt = $this->router->createPayment(context: $context);

        Assert::same($this->router->gatewayFor(context: $context), $this->gateway);
        verify(fn() => $this->gateway->createPayment($context->request), times: 1);
        Assert::same($attempt->provider, $this->provider);
    }

    public function fixedPolicyRejectsUnavailableProvider(): void
    {
        $router = new PaymentGatewayRouter(
            registry: new GatewayRegistry(),
            selectionPolicy: new FixedGatewaySelectionPolicy(provider: new PaymentProvider(value: 'stripe')),
        );

        Expect::exception(\OutOfBoundsException::class)->withMessage('Fixed payment provider "stripe" is not available');
        $router->gatewayFor(context: new GatewaySelectionContext(request: PaymentFixtures::createRequest()));
    }

    public function fixedPolicyRejectsProviderMissingFromNonEmptyRegistry(): void
    {
        $router = new PaymentGatewayRouter(
            registry: new GatewayRegistry(gateways: [$this->basicGateway(provider: new PaymentProvider(value: 'paypal'))]),
            selectionPolicy: new FixedGatewaySelectionPolicy(provider: new PaymentProvider(value: 'stripe')),
        );

        Expect::exception(\OutOfBoundsException::class)->withMessage('Fixed payment provider "stripe" is not available');
        $router->gatewayFor(context: new GatewaySelectionContext(request: PaymentFixtures::createRequest()));
    }

    public function routesExistingPaymentOperationsByReferenceProvider(): void
    {
        $payment = new PaymentReference(provider: $this->provider, id: 'pay_1');

        $retrieve = new RetrievePaymentRequest(operationId: new OperationId(value: 'retrieve'), payment: $payment);
        $this->router->retrievePayment(request: $retrieve);
        verify(fn() => $this->gateway->retrievePayment($retrieve), times: 1);

        $capture = new CapturePaymentRequest(operationId: new OperationId(value: 'capture'), payment: $payment);
        $this->router->capturePayment(request: $capture);
        verify(fn() => $this->gateway->capturePayment($capture), times: 1);

        $confirm = new PaymentOperationRequest(operationId: new OperationId(value: 'confirm'), payment: $payment);
        $this->router->confirmPayment(request: $confirm);
        verify(fn() => $this->gateway->confirmPayment($confirm), times: 1);

        $cancel = new PaymentOperationRequest(operationId: new OperationId(value: 'cancel'), payment: $payment);
        $this->router->cancelPayment(request: $cancel);
        verify(fn() => $this->gateway->cancelPayment($cancel), times: 1);
    }

    public function routesRefundOperationsByReferenceProvider(): void
    {
        $payment = new PaymentReference(provider: $this->provider, id: 'pay_1');
        $create = new CreateRefundRequest(operationId: new OperationId(value: 'refund'), payment: $payment);
        $this->router->createRefund(request: $create);
        verify(fn() => $this->gateway->createRefund($create), times: 1);

        $retrieve = new RetrieveRefundRequest(
            operationId: new OperationId(value: 'retrieve_refund'),
            refund: new RefundReference(provider: $this->provider, id: 'ref_1'),
        );
        $this->router->retrieveRefund(request: $retrieve);
        verify(fn() => $this->gateway->retrieveRefund($retrieve), times: 1);
    }

    public function rejectsUnsupportedOptionalOperation(): void
    {
        $provider = new PaymentProvider(value: 'basic');
        $router = new PaymentGatewayRouter(
            registry: new GatewayRegistry(gateways: [$this->basicGateway(provider: $provider)]),
            selectionPolicy: new FixedGatewaySelectionPolicy(provider: $provider),
        );

        Expect::exception(\LogicException::class)->withMessage('Payment gateway "basic" does not support capture');
        $router->capturePayment(request: new CapturePaymentRequest(
            operationId: new OperationId(value: 'capture'),
            payment: new PaymentReference(provider: $provider, id: 'pay_1'),
        ));
    }

    /**
     * The router dispatches by interface, so the fixture gateway doubles all
     * five ISP contracts at once and answers every operation with a canned
     * attempt.
     */
    private function stubOperations(): void
    {
        when(fn() => $this->gateway->provider())->returns($this->provider);
        when(fn() => $this->gateway->createPayment(Arg::any()))->returns($this->attempt(new OperationId(value: 'create')));
        when(fn() => $this->gateway->retrievePayment(Arg::any()))->returns($this->attempt(new OperationId(value: 'retrieve')));
        when(fn() => $this->gateway->capturePayment(Arg::any()))->returns($this->attempt(new OperationId(value: 'capture')));
        when(fn() => $this->gateway->confirmPayment(Arg::any()))->returns($this->attempt(new OperationId(value: 'confirm')));
        when(fn() => $this->gateway->cancelPayment(Arg::any()))->returns($this->attempt(new OperationId(value: 'cancel')));
        when(fn() => $this->gateway->createRefund(Arg::any()))->returns($this->refundAttempt(new OperationId(value: 'refund')));
        when(fn() => $this->gateway->retrieveRefund(Arg::any()))->returns($this->refundAttempt(new OperationId(value: 'retrieve_refund')));
    }

    /**
     * A gateway without optional ISP contracts: the double implements the
     * base interface only, so the router's instanceof checks reject it.
     */
    private function basicGateway(PaymentProvider $provider): PaymentGatewayInterface
    {
        $gateway = Understudy::for(PaymentGatewayInterface::class);
        when(fn() => $gateway->provider())->returns($provider);

        return $gateway;
    }

    private function attempt(OperationId $operationId): PaymentAttempt
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new PaymentAttempt(
            operationId: $operationId,
            provider: $this->provider,
            payment: new PaymentReference(provider: $this->provider, id: 'pay_1'),
            amount: new Money(minorUnits: 100, currency: 'USD'),
            state: PaymentState::Succeeded,
            rawStatus: 'succeeded',
            createdAt: $now,
            updatedAt: $now,
            requestInfo: new ProviderRequestInfo(receivedAt: $now),
        );
    }

    private function refundAttempt(OperationId $operationId): RefundAttempt
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return new RefundAttempt(
            operationId: $operationId,
            provider: $this->provider,
            refund: new RefundReference(provider: $this->provider, id: 'ref_1'),
            payment: new PaymentReference(provider: $this->provider, id: 'pay_1'),
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
