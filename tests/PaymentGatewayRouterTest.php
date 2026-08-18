<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\CapturePaymentRequest;
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\FixedGatewaySelectionPolicy;
use Rasuvaeff\Payments\GatewayRegistry;
use Rasuvaeff\Payments\GatewaySelectionContext;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\PaymentGatewayRouter;
use Rasuvaeff\Payments\PaymentOperationRequest;
use Rasuvaeff\Payments\PaymentProvider;
use Rasuvaeff\Payments\PaymentReference;
use Rasuvaeff\Payments\RefundReference;
use Rasuvaeff\Payments\RetrievePaymentRequest;
use Rasuvaeff\Payments\RetrieveRefundRequest;
use Rasuvaeff\Payments\Tests\Support\BaseFakeGateway;
use Rasuvaeff\Payments\Tests\Support\FakeGateway;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PaymentGatewayRouter::class)]
#[Covers(FixedGatewaySelectionPolicy::class)]
final class PaymentGatewayRouterTest
{
    public function selectsGatewayForNewPayment(): void
    {
        [$router, $gateway, $provider] = $this->fixture();
        $context = new GatewaySelectionContext(request: PaymentFixtures::createRequest(), tenantId: 'tenant_1');
        $attempt = $router->createPayment(context: $context);

        Assert::same($router->gatewayFor(context: $context), $gateway);
        Assert::same($gateway->lastRequest, $context->request);
        Assert::same($attempt->provider, $provider);
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
            registry: new GatewayRegistry(gateways: [new BaseFakeGateway(provider: new PaymentProvider(value: 'paypal'))]),
            selectionPolicy: new FixedGatewaySelectionPolicy(provider: new PaymentProvider(value: 'stripe')),
        );

        Expect::exception(\OutOfBoundsException::class)->withMessage('Fixed payment provider "stripe" is not available');
        $router->gatewayFor(context: new GatewaySelectionContext(request: PaymentFixtures::createRequest()));
    }

    public function routesExistingPaymentOperationsByReferenceProvider(): void
    {
        [$router, $gateway, $provider] = $this->fixture();
        $payment = new PaymentReference(provider: $provider, id: 'pay_1');

        $retrieve = new RetrievePaymentRequest(operationId: new OperationId(value: 'retrieve'), payment: $payment);
        $router->retrievePayment(request: $retrieve);
        Assert::same($gateway->lastRequest, $retrieve);

        $capture = new CapturePaymentRequest(operationId: new OperationId(value: 'capture'), payment: $payment);
        $router->capturePayment(request: $capture);
        Assert::same($gateway->lastRequest, $capture);

        $confirm = new PaymentOperationRequest(operationId: new OperationId(value: 'confirm'), payment: $payment);
        $router->confirmPayment(request: $confirm);
        Assert::same($gateway->lastRequest, $confirm);

        $cancel = new PaymentOperationRequest(operationId: new OperationId(value: 'cancel'), payment: $payment);
        $router->cancelPayment(request: $cancel);
        Assert::same($gateway->lastRequest, $cancel);
    }

    public function routesRefundOperationsByReferenceProvider(): void
    {
        [$router, $gateway, $provider] = $this->fixture();
        $payment = new PaymentReference(provider: $provider, id: 'pay_1');
        $create = new CreateRefundRequest(operationId: new OperationId(value: 'refund'), payment: $payment);
        $router->createRefund(request: $create);
        Assert::same($gateway->lastRequest, $create);

        $retrieve = new RetrieveRefundRequest(
            operationId: new OperationId(value: 'retrieve_refund'),
            refund: new RefundReference(provider: $provider, id: 'ref_1'),
        );
        $router->retrieveRefund(request: $retrieve);
        Assert::same($gateway->lastRequest, $retrieve);
    }

    public function rejectsUnsupportedOptionalOperation(): void
    {
        $provider = new PaymentProvider(value: 'basic');
        $router = new PaymentGatewayRouter(
            registry: new GatewayRegistry(gateways: [new BaseFakeGateway(provider: $provider)]),
            selectionPolicy: new FixedGatewaySelectionPolicy(provider: $provider),
        );

        Expect::exception(\LogicException::class)->withMessage('Payment gateway "basic" does not support capture');
        $router->capturePayment(request: new CapturePaymentRequest(
            operationId: new OperationId(value: 'capture'),
            payment: new PaymentReference(provider: $provider, id: 'pay_1'),
        ));
    }

    /** @return array{PaymentGatewayRouter, FakeGateway, PaymentProvider} */
    private function fixture(): array
    {
        $provider = new PaymentProvider(value: 'stripe');
        $gateway = new FakeGateway(paymentProvider: $provider);

        return [
            new PaymentGatewayRouter(
                registry: new GatewayRegistry(gateways: [$gateway]),
                selectionPolicy: new FixedGatewaySelectionPolicy(provider: $provider),
            ),
            $gateway,
            $provider,
        ];
    }
}
