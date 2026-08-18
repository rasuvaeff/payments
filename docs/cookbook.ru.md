# Кукбук

Практические рецепты для стека `rasuvaeff/payments`. Все примеры предполагают
пакеты из [README.ru.md](../README.ru.md); исполняемые скрипты лежат в
[examples/](../examples/) и в пакетах-адаптерах.

## Содержание

1. [Подключить Stripe-шлюз](#подключить-stripe-шлюз)
2. [Подключить PayPal-шлюз](#подключить-paypal-шлюз)
3. [Маршрутизация между несколькими шлюзами](#маршрутизация-между-несколькими-шлюзами)
4. [Возвраты (полные и частичные)](#возвраты-полные-и-частичные)
5. [Приём вебхуков через PSR-7 контроллер](#приём-вебхуков-через-psr-7-контроллер)
6. [Реализация хранилища событий](#реализация-хранилища-событий)
7. [Выбор политики подтверждения](#выбор-политики-подтверждения)
8. [OperationId против idempotency key](#operationid-против-idempotency-key)
9. [Тестирование приложения на фейках](#тестирование-приложения-на-фейках)
10. [Безопасные ретраи](#безопасные-ретраи)

## Подключить Stripe-шлюз

```php
use Rasuvaeff\PaymentsStripe\StripeCredentials;
use Rasuvaeff\PaymentsStripe\StripeGatewayFactory;

$gateway = StripeGatewayFactory::create(
    credentials: new StripeCredentials(secretKey: \getenv('STRIPE_SECRET_KEY')),
    client: $httpClient,                                         // любой PSR-18 клиент
    requestFactory: $psr17Factory,                               // фабрика request+stream
    clock: $psr20Clock,                                          // любые часы PSR-20
);

$attempt = $gateway->createPayment(new \Rasuvaeff\Payments\CreatePaymentRequest(
    operationId: new \Rasuvaeff\Payments\OperationId(value: 'order-42-charge'),
    amount: new \Rasuvaeff\Payments\Money(minorUnits: 2599, currency: 'USD'),
    paymentMethod: new \Rasuvaeff\Payments\PaymentMethodReference(id: 'pm_123'),
    idempotencyKey: 'order-42-charge-attempt-1',
));
```

Попытка несёт нормализованный `PaymentState`, провайдерский `rawStatus` и
санитизированный `ProviderRequestInfo`. Никогда не ветвитесь по `rawStatus` —
исходите из `state`, а `rawStatus` держите для диагностики.

Если интент ещё ждёт покупателя, отловите действие подтверждения на клиенте и
отдайте его секрет фронтенду:

```php
use Rasuvaeff\PaymentsStripe\ConfirmOnClient;

if ($attempt->nextAction instanceof ConfirmOnClient) {
    $response->json(['client_secret' => $attempt->nextAction->clientSecret]);
}
```

## Подключить PayPal-шлюз

```php
use Rasuvaeff\PaymentsPayPal\PayPalCredentials;
use Rasuvaeff\PaymentsPayPal\PayPalGatewayConfig;
use Rasuvaeff\PaymentsPayPal\PayPalGatewayFactory;

$gateway = PayPalGatewayFactory::withCachedOAuth(
    credentials: new PayPalCredentials(clientId: \getenv('PAYPAL_CLIENT_ID'), clientSecret: \getenv('PAYPAL_CLIENT_SECRET')),
    client: $httpClient,
    requestFactory: $psr17Factory,
    clock: $psr20Clock,
    config: new PayPalGatewayConfig(apiBaseUri: 'https://api-m.sandbox.paypal.com', sandbox: true),
);
```

`withCachedOAuth` обменивает креденшалы раз за срок жизни токена
(`PayPalCachedTokenProvider` обновляет его за margin до истечения, отчитанного
PayPal). Заказы PayPal создаются с интентом `CAPTURE`: свежий заказ
отображается в `PaymentState::Pending`; после одобрения покупателем
(`status: APPROVED`) состояние становится `RequiresCapture`, и
`capturePayment()` завершает его.

## Маршрутизация между несколькими шлюзами

```php
use Rasuvaeff\Payments\GatewayRegistry;
use Rasuvaeff\Payments\FixedGatewaySelectionPolicy;
use Rasuvaeff\Payments\PaymentGatewayRouter;

$registry = new GatewayRegistry($stripe, $paypal);
$router = new PaymentGatewayRouter(
    registry: $registry,
    selectionPolicy: new FixedGatewaySelectionPolicy(provider: $stripe->provider()),
);

$attempt = $router->createPayment($request);          // провайдера выбирает политика
$attempt = $router->capturePayment($captureRequest);  // маршрут по провайдеру из ссылки
```

Создание — единственная операция, которую выбирает политика; всё остальное
маршрутизируется по провайдеру внутри `PaymentReference`. Реестр никогда не
выбирает шлюз сам неявно — для маршрутизации по методу/валюте/тенанту
напишите свою реализацию `GatewaySelectionPolicyInterface`.

## Возвраты (полные и частичные)

```php
use Rasuvaeff\Payments\CreateRefundRequest;
use Rasuvaeff\Payments\Money;
use Rasuvaeff\Payments\OperationId;
use Rasuvaeff\Payments\RefundReason;

// Полный возврат капчура
$refund = $refundGateway->createRefund(new CreateRefundRequest(
    operationId: new OperationId(value: 'order-42-refund'),
    payment: $captureAttempt->payment,      // ссылка на капчур
));

// Частичный возврат с причиной
$refund = $refundGateway->createRefund(new CreateRefundRequest(
    operationId: new OperationId(value: 'order-42-refund-partial-1'),
    payment: $captureAttempt->payment,
    amount: new Money(minorUnits: 1000, currency: 'USD'),
    reason: new RefundReason(value: 'damaged item'),
    idempotencyKey: 'order-42-refund-partial-1',
));
```

Два одинаковых частичных возврата требуют двух разных operation id и
idempotency key; идентичность никогда не выводится из «платёж + сумма +
причина».

## Приём вебхуков через PSR-7 контроллер

Процессоры соберите фабриками адаптеров — durable-граница (event store и
подтверждение) остаётся вашей:

```php
use Rasuvaeff\Payments\QueuedWebhookEventAcceptance;
use Rasuvaeff\Payments\WebhookController;
use Rasuvaeff\Payments\WebhookProcessorRegistry;
use Rasuvaeff\Payments\WebhookProcessorRegistration;
use Rasuvaeff\PaymentsStripe\StripeWebhookProcessorFactory;
use Rasuvaeff\PaymentsStripe\StripeWebhookSecret;
use Rasuvaeff\PaymentsPayPal\PayPalWebhookProcessorFactory;

$stripeProcessor = StripeWebhookProcessorFactory::create(
    secret: new StripeWebhookSecret(value: \getenv('STRIPE_WEBHOOK_SECRET')),
    clock: $clock,
    eventStore: $eventStore,
    eventAcceptance: new QueuedWebhookEventAcceptance(queue: $queue),
);
$paypalProcessor = PayPalWebhookProcessorFactory::create(
    webhookId: \getenv('PAYPAL_WEBHOOK_ID'),
    accessTokenProvider: $tokenCache,
    client: $httpClient,
    requestFactory: $psr17Factory,
    clock: $clock,
    eventStore: $eventStore,
    eventAcceptance: new QueuedWebhookEventAcceptance(queue: $queue),
);

$processors = new WebhookProcessorRegistry(
    new WebhookProcessorRegistration(processor: $stripeProcessor, provider: $stripeProvider),
    new WebhookProcessorRegistration(processor: $paypalProcessor, provider: $paypalProvider),
);

$controller = new WebhookController(
    registry: $processors,
    responseFactory: $psr17Factory,
);
```

`WebhookController::handle($request, 'stripe')` берёт ключ провайдера из
вашего маршрута — смонтируйте по эндпоинту на провайдера
(`/webhooks/stripe`, `/webhooks/paypal`) и передайте сегмент в `handle()`;
неизвестный или некорректный ключ отвечает 404, не трогая процессор:

```php
$response = $controller->handle($serverRequest, 'stripe');
```

Если вы потребляете открытый `WebhookProcessorInterface` (декорирующий
процессор может вернуть чужой исход), закройте `match` веткой `default` —
иначе необработанный исход превратится в `UnhandledMatchError` и 500 от
контроллера:

```php
$outcome = match (true) {
    $result instanceof \Rasuvaeff\Payments\ProcessedWebhook => 'processed',
    $result instanceof \Rasuvaeff\Payments\WebhookValidationFailed => 'validation_failed',
    $result instanceof \Rasuvaeff\Payments\ReplayedWebhookEvent => 'replayed',
    $result instanceof \Rasuvaeff\Payments\UnknownWebhookEvent => 'ignored_unknown',
    $result instanceof \Rasuvaeff\Payments\UnsupportedWebhookEvent => 'ignored_unsupported',
    $result instanceof \Rasuvaeff\Payments\RejectedWebhookEvent => 'rejected_payload',
    default => 'processing_failed', // чужой исход от декорирующего процессора
};
```

Монтируйте webhook-маршрут **до** любого body-parsing middleware. Подпись
покрывает точные полученные байты, а поток, уже прочитанный middleware,
читается как пустая строка — и любая проверка провалится при верном секрете и
верной подписи. Контроллер сообщает об этом исходом `empty_body`, а не
`validation_failed`, чтобы случай диагностировался, но чинится он порядком
монтирования.

Фаза 1 (синхронная): проверка подписи → атомарный `claim()` провайдерского
event id → распознавание и маппинг → надёжная постановка в очередь →
`complete()` захвата. Фаза 2 (реконсиляция): повторный запрос авторитетного
состояния провайдера, сверка суммы с заказом, персистенция проекции попытки,
затем события приложения. Пейлоад вебхука — только наблюдение; авторитетное
состояние всегда берётся из повторного запроса.

```php
$attempt = $gateway->retrievePayment(new RetrievePaymentRequest(
    operationId: new OperationId('reconcile-' . $event->providerEventId),
    payment: $event->payment,
));

// Сверять сумму И валюту. Один `minorUnits === $order->total` примет
// 1000 JPY за заказ на 1000 USD.
if ($attempt->state === PaymentState::Succeeded && $attempt->amount->equals($order->total)) {
    $order->markPaid();
}
```

## Реализация хранилища событий

Хранилище владеет защитой от replay, и в его контракте три вызова:

```php
final class DbWebhookEventStore implements WebhookEventStoreInterface
{
    private const int LEASE_SECONDS = 300;

    public function claim(PaymentProvider $provider, string $providerEventId): bool
    {
        // INSERT ... ON CONFLICT DO UPDATE ... WHERE completed_at IS NULL
        //   AND claimed_at < now() - lease, RETURNING id
        // -> true, если строка вставлена или протухший захват перехвачен.
    }

    public function complete(PaymentProvider $provider, string $providerEventId): void
    {
        // UPDATE ... SET completed_at = now()
    }

    public function release(PaymentProvider $provider, string $providerEventId): void
    {
        // DELETE ... WHERE completed_at IS NULL
    }
}
```

`complete()` — не бухгалтерия. Процесс может умереть между `claim()` и концом
обработки, не дойдя до `release()`: SIGKILL, OOM-killer, `max_execution_time`,
фатальная ошибка, рестарт пода. Без сигнала о завершении хранилище не отличает
захват «в работе» от завершённого, и оба выбора неверны: истекающие захваты —
уже обработанное событие выполнится повторно на следующем ретрае провайдера;
неистекающие — прерванное событие получит replay-исход, HTTP 204, провайдер
прекратит ретраи, и событие потеряно без единой ошибки.

Если очередь живёт в той же БД, есть более простой вариант: коммитить строку
захвата и строку очереди **одной транзакцией** — тогда «захват существует» уже
означает «событие durable». Брокер (SQS, Redis) в эту транзакцию не входит, и
таким развёртываниям нужен лиз плюс `complete()`.

## Выбор политики подтверждения

| Политика | Подтверждение после | Когда использовать |
|---|---|---|
| `AfterValidation` | подпись валидна + событие заклеймлено + надёжно поставлено в очередь | очередь; ретраи провайдера дёшевы |
| `AfterPersistence` | фаза 2 сохранила авторитетное состояние | синхронная обработка, строгий at-least-once |

Никогда не подтверждайте сразу после валидации: если заклеймленное событие не
записано надёжно, провайдер не пришлёт его повторно и оно потеряно.

`RejectedWebhookEvent` подтверждается намеренно. Подпись была валидна, то есть
событие настоящее, но его payload не отображается — сумма с неожиданной
точностью, id в неизвестном формате. Этот вердикт принадлежит payload'у, значит
повторная доставка выдаст его снова и снова; ретраи ничего не дают, а
провайдеры отключают эндпоинт, который постоянно ошибается, — и теряются
**последующие**, здоровые события. Ставьте алерт на отказы: они означают, что
адаптер и провайдер разошлись в формате.

## OperationId против idempotency key

| Понятие | Область | Время жизни |
|---|---|---|
| `OperationId` | одна логическая бизнес-команда | неизменен сквозь ретраи, провайдеров и failover |
| `idempotencyKey` | один вызов провайдера | неизменен сквозь транспортные ретраи этого вызова; новое значение при новой попытке по политике |

Адаптер пробрасывает оба; хранение и защита от replay — на стороне вашего
приложения (или будущего Yii3-бриджа).

## Тестирование приложения на фейках

```php
use Rasuvaeff\Payments\PaymentState;
use Rasuvaeff\PaymentsTesting\FakeGatewayConfig;
use Rasuvaeff\PaymentsTesting\FakePaymentGateway;
use Rasuvaeff\PaymentsTesting\PaymentGatewayAssertions;

// Детерминированный исход задаётся FakeGatewayConfig::createState
$succeeding = new FakePaymentGateway();   // конфиг по умолчанию: PaymentState::Succeeded
$failing = new FakePaymentGateway(new FakeGatewayConfig(createState: PaymentState::Failed));

$attempt = $succeeding->createPayment($request);

// Контрактные ассерты проверяют сам контракт шлюза (реплей идемпотентности,
// совпадение провайдера в ссылках, согласованность capability/интерфейсов):
PaymentGatewayAssertions::assertCreatePayment(gateway: $succeeding, request: $request);
PaymentGatewayAssertions::assertCreatePaymentIdempotency(gateway: $succeeding, request: $request);
```

Фейки реализуют те же контракты, что и реальные адаптеры, поэтому роутер и
вебхук-код проверяются без сети. Опциональные интерфейсы (capture/refund)
представлены отдельными опциональными фейками, честно сообщающими об
отсутствии возможности.

## Безопасные ретраи

Ядро намеренно не содержит политики ретраев. Оберните транспорт (или шлюз)
пакетом `rasuvaeff/retry` и ретраьте только явно идемпотентные операции:

- чтения (`retrievePayment`, `retrieveRefund`);
- создания со стабильным idempotency key;
- никогда «слепые» POST без ключа.

Транспортные сбои маппятся в `TransportException`/`ServerException`, и решение
остаётся за политикой вызывающего; не прячьте DECLINE внутри цикла ретраев.
