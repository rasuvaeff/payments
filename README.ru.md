# rasuvaeff/payments

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/payments/v)](https://packagist.org/packages/rasuvaeff/payments)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/payments/downloads)](https://packagist.org/packages/rasuvaeff/payments)
[![Build](https://github.com/rasuvaeff/payments/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/payments/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/payments/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/payments/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/payments/actions/workflows/static-analysis.yml)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Нейтральные к провайдеру платёжные контракты, PSR-18 транспорт и webhook-конвейер
для адаптеров платёжных шлюзов: value-объекты (`Money`, ссылки, состояния),
интерфейсы шлюзов с типизированными capability, реестр/роутер шлюзов, построители
запросов, декодирование ответов с типизированными исключениями и валидируемый,
защищённый от повторов webhook-ingress с PSR-7 контроллером. Пакет сознательно не
выбирает HTTP-клиент, повторы, хранилище учётных данных, очереди, persistence и
провайдерскую семантику статусов.

> Используете AI-ассистента? [llms.txt](llms.txt) содержит компактный API-справочник.

## Требования

PHP 8.3 – 8.6. PSR-18 клиент с PSR-17 фабриками для исходящих запросов к провайдеру;
PSR-7/PSR-17 сообщения для webhook-контроллера.

## Установка

```bash
composer require rasuvaeff/payments
```

## Использование

### Money и value-объекты идентичности

`Money` — неотрицательные целые минорные единицы плюс трёхбуквенный ISO-код
валюты в верхнем регистре; никаких float:

```php
$price = Money::minorUnits(1_200, 'EUR');
$total = $price->add(Money::minorUnits(300, 'EUR'));   // 1500 минорных единиц EUR
$rest  = $total->subtract($price);                     // бросает исключение при уходе ниже нуля
$fee   = $total->multiply(3, 100);                     // рациональный множитель, округление half-up
$total->isZero();                                      // false
$total->equals($order->total);                         // сумма И валюта
$total->isGreaterThan($price);                         // требует одинаковой валюты
```

Арифметика требует одинаковых валют и бросает `\OverflowException` вместо
переполнения. `multiply()` принимает целочисленные числитель/знаменатель, так
что процентные расчёты никогда не касаются плавающей точки.

`equals()` — для сверки авторитетной суммы провайдера с суммой заказа при
reconciliation. Он сравнивает и валюту: `$attempt->amount->minorUnits ===
$order->total` примет 1000 JPY за заказ на 1000 USD, и именно про эту половину
забывают, когда сверку пишут руками.

| Value-объект | Смысл |
|---|---|
| `OperationId` | Выданный приложением id одной логической платёжной команды (до 255 байт) |
| `PaymentProvider` | Ключ провайдера в нижнем регистре, `/^[a-z][a-z0-9_-]{0,63}\z/` |
| `PaymentReference` / `RefundReference` | Провайдер + id на стороне провайдера (+ опциональный `kind`) |
| `CustomerReference` | Id клиента приложения + опциональный id клиента у провайдера |
| `PaymentMethodReference` | Id метода оплаты + опциональный `kind` |
| `RefundReason` | Непустая строка причины (до 128 байт) |
| `ProviderEventType` | Провайдер + сырое имя события |
| `ProviderRequestInfo` | Диагностика ответа по allow-list: `receivedAt`, `requestId`, `rateLimitRemaining`, `retryAfterSeconds` |
| `PaymentFailure` | Санитизированная ошибка: `code`, `message`, `retryable`, скалярный `details` |

### Интенты, попытки и состояния

`PaymentIntent` — бизнес-намерение, принадлежащее приложению; `PaymentAttempt` —
одно исполнение у провайдера. Интент агрегирует попытки и отклоняет попытку,
чья валюта отличается от валюты интента. `RefundAttempt` несёт запрошенную и
фактически возвращённую суммы.

```php
$attempt = new PaymentAttempt(
    operationId: new OperationId('op-1'),
    provider: new PaymentProvider('stripe'),
    payment: new PaymentReference(provider: new PaymentProvider('stripe'), id: 'pi_1'),
    amount: Money::minorUnits(1_200, 'EUR'),
    state: PaymentState::RequiresAction,
    rawStatus: 'requires_action',
    createdAt: $now,
    updatedAt: $now,
    requestInfo: new ProviderRequestInfo(receivedAt: $now),
    nextAction: new RedirectToUrl('https://pay.example.test/redirect'),
);
```

`PaymentState` (`Pending`, `RequiresPaymentMethod`, `RequiresAction`,
`RequiresCapture`, `Processing`, `Succeeded`, `Failed`, `Canceled`)
предоставляет `canTransitionTo()` — **рекомендательную** карту переходов. Её
нельзя использовать, чтобы отбросить принятое webhook-наблюдение; авторитетен
провайдер.

`Succeeded`, `Failed` и `Canceled` — терминальные. Поэтому восстановимое
состояние провайдера никогда не сворачивается в одно из них: отказ карты
возвращает Stripe-интент в `requires_payment_method`, покупатель платит другой
картой, и тот же интент доходит до `succeeded` — этот статус маппится в
`RequiresPaymentMethod` (с описанием отказа рядом, в `failure`), а не в
`Failed`. `RefundState` — `Pending`,
`Succeeded`, `Failed`, `Canceled`. `CaptureMethod` и `ConfirmationMethod` —
`Automatic`/`Manual`.

`NextAction` — открытый интерфейс (`type(): string`) с тремя поставляемыми
реализациями: `RedirectToUrl` (только HTTPS URL), `UseSdk` (имя SDK + скалярный
payload) и `DisplayInstructions` (тип инструкции + текст + скалярные метаданные).

`JsonRequestBuilder` реализует и `RawJsonRequestBuilderInterface`, чей
`buildRawJson()` отправляет уже закодированное тело байт в байт. Он нужен всюду,
где подписанный где-то ещё документ пересылается на верификацию: декодирование и
повторное кодирование в PHP не round-trip — не-ASCII экранируется, `{}` даёт
`[]`, `1.50` даёт `1.5`, — и что бы удалённый верификатор потом ни подтвердил,
это уже не тот документ, который пришёл.

### Контракты шлюзов и capability

`PaymentGatewayInterface` — обязательное ядро; опциональные операции вынесены в
отдельные ISP-интерфейсы, чтобы capability не могла заявить неподдерживаемый
метод:

| Интерфейс | Методы |
|---|---|
| `PaymentGatewayInterface` | `provider()`, `capabilities()`, `createPayment(CreatePaymentRequest)`, `retrievePayment(RetrievePaymentRequest)` |
| `CaptureGatewayInterface` | `capturePayment(CapturePaymentRequest)` |
| `ConfirmGatewayInterface` | `confirmPayment(PaymentOperationRequest)` |
| `CancelGatewayInterface` | `cancelPayment(PaymentOperationRequest)` |
| `RefundGatewayInterface` | `createRefund(CreateRefundRequest)`, `retrieveRefund(RetrieveRefundRequest)` |

Каждый request-DTO несёт `OperationId` и, где уместно, опциональный
`idempotencyKey`. `CreatePaymentRequest` добавляет сумму, **опциональный**
метод оплаты, опционального клиента, методы capture/confirmation, описание и
скалярные метаданные.

Опустить `paymentMethod` — выбрать отложенный сценарий: провайдер создаёт
платёж без привязанного метода и возвращает то, что нужно браузеру покупателя,
чтобы метод выбрать. Передать — сценарий, где метод уже существует: собран на
клиенте заранее или сохранён у провайдера.

`idempotencyKey` уезжает дословно в заголовок запроса к провайдеру, поэтому
валидируется как header-токен: непустой, не длиннее 255 байт, без управляющих
символов и пробелов. Отклонить его при конструировании лучше, чем получить
ошибку PSR-7 из глубины транспорта, которая не назовёт ни поля, ни запроса.

#### Что контракт фиксирует для адаптеров

**Минорные единицы — по ISO 4217.** `Money::$minorUnits` считает минорную
единицу валюты по ISO 4217: центы для USD и EUR (экспонента 2), целые иены для
JPY и целые кроны для ISK (экспонента 0), филсы для KWD и BHD (экспонента 3).
Таблицы экспонент в ядре нет; ядро фиксирует смысл, чтобы один `Money` значил
одну и ту же сумму для любого адаптера стека. Если формат провайдера на проводе
отличается, конвертирует адаптер. До любого HTTP-вызова он отвергает сумму,
которую не может представить точно, через `\InvalidArgumentException`, а при
маппинге ответа конвертирует обратно. Известные случаи:

| Провайдер | Валюты | Формат на проводе | Адаптер обязан |
|---|---|---|---|
| Stripe | ISK, UGX | двухзнаковый (`500` списывает 5 ISK); экспонента ISO 0 | умножать на 100 при отправке и делить при чтении |
| Stripe | BHD, JOD, KWD, OMR, TND | трёхзнаковый, последняя цифра должна быть `0` | отвергать сумму, не кратную 10 |
| PayPal | HUF, TWD | значение без дробной части; экспонента ISO 2 | отвергать сумму, не кратную 100, и слать `minorUnits / 100` |

В таблице — случаи, найденные ревью 2026-11-28; добавляя валюту в адаптер,
сверяйтесь с актуальным списком провайдера.

**Нулевая сумма принимается** `Money` и `CreatePaymentRequest`: ядро не знает
минимального списания провайдера. Провайдер её отвергнет, и адаптер поднимет
отказ как `PaymentException`. Если нужна понятная ошибка, проверяйте сумму
заказа до создания платежа.

**Две суммы: созданная и захваченная.** `PaymentAttempt::$amount` — сумма, на
которую создан платёж, в том виде, в каком её сообщает провайдер; для
авторизации это авторизованная сумма, и capture её не меняет.
`PaymentAttempt::$capturedAmount` — сколько провайдер уже списал всего:

| `capturedAmount` | Значение |
|---|---|
| `null` | ответ об этом не говорит (адаптеру нечего мапить) |
| ноль | ещё ничего не захвачено — авторизация в `RequiresCapture`, неоплаченный платёж |
| равна `amount` | прошёл автоматический или полный capture |
| меньше `amount` | частичный capture; остаток авторизации освобождён или ещё доступен — зависит от провайдера |

Сверяйте собранное с `capturedAmount`, а не с `amount`: после частичного
capture `$attempt->amount->equals($order->total)` по-прежнему истинно. Попытка
отвергает `capturedAmount` в другой валюте, но не ограничивает его сверху
`amount`: провайдер с overcapture может списать больше, и он авторитетен.
Адаптеры мапят поле из ответа:

| Провайдер | `amount` | `capturedAmount` |
|---|---|---|
| Stripe PaymentIntent | `amount` | `amount_received` (`amount_capturable` — что ещё можно захватить, а не захвачено) |
| Заказ PayPal | `purchase_units[0].amount` | сумма `purchase_units[0].payments.captures[].amount` со статусом `COMPLETED`; ноль, если их нет; в ответе на capture — возвращённая им захваченная сумма |

Адаптер, который не может прочитать захваченную сумму из ответа, оставляет
`null`, а не угадывает её по состоянию.

**Каждый create несёт ключ.** `CreatePaymentRequest::$idempotencyKey` —
непустая строка. Переданный ключ используется как есть; без него запрос сам
генерирует случайный UUID v4 (36 символов). Действующий ключ существует до
вызова, поэтому доходит до адаптера (`Idempotency-Key` у Stripe,
`PayPal-Request-Id` у PayPal) и переживает тот сбой, который важен, — таймаут,
после которого читать уже не из чего:

```php
$request = new CreatePaymentRequest(operationId: $op, amount: $total, paymentMethod: $pm);
$store->rememberKey($order, $request->idempotencyKey);   // опционально: пережить рестарт

$attempt = $retry->run(fn () => $gateway->createPayment($request));   // тот же объект, тот же ключ
```

Стройте запрос один раз, вне цикла ретраев: замыкание, вызывающее
`new CreatePaymentRequest(...)`, на каждой попытке генерирует новый ключ, и
каждая попытка — новый платёж. Чтобы повторить из другого процесса, передайте
сохранённый ключ обратно в `idempotencyKey:`. Для capture, confirm, cancel и
refund ключ остаётся необязательным и никогда не генерируется; адаптеры этого
семейства при `null` не шлют ключа, и такой запрос небезопасен для ретрая. См.
раздел cookbook «OperationId против idempotency key».

Ядро ограничивает ключ 255 байтами — лимитом Stripe. Провайдер может принимать
меньше: `PayPal-Request-Id` у PayPal — не длиннее 108 символов. Адаптер, чей
провайдер ограничивает строже, обязан отвергать более длинный ключ через
`\InvalidArgumentException` до HTTP-вызова и указать свой лимит в README. Лимит
ядра остаётся 255, так что ключ, допустимый для одного адаптера, может быть
отвергнут другим; если приложение маршрутизирует между провайдерами, держите
ключи в пределах наименьшего лимита.

**Маперы конвертируют ошибки value-объектов.** Мапер ответа или вебхука,
строящий value-объекты ядра из данных провайдера, ловит их
`\InvalidArgumentException` (неизвестная валюта, слишком длинный id) и
перебрасывает как `MalformedResponseException`. `WebhookProcessor` считает
терминальными только `MalformedResponseException` и
`UnsupportedWebhookEventException` из `map()`; любое другое исключение
освобождает claim и получает ответ 503, так что неконвертированная ошибка
value-объекта заставляет тот же payload приходить снова бесконечно. Extractor
типа события и recognizer работают вне этой обработки: для тела, которое они не
могут прочитать, они возвращают `null` (это даёт `UnknownWebhookEvent`), а не
бросают исключение.

**У состояний возврата нет карты переходов.** Возврат Stripe может позже
перейти из `succeeded` в `failed`. Перезапрашивайте возврат, прежде чем
действовать по его исходу.

Capability — типизированные значения в неизменяемом `CapabilitySet`:

```php
$capabilities = CapabilitySet::of(
    new PartialRefundCapability(maximumRefunds: 5),
    new ThreeDsCapability(versions: ['2.2.0']),
    new WebhookCapability(signatureRequired: true),
    new SandboxCapability(),
);

$capabilities->has(PartialRefundCapability::class); // true
$capabilities->get(ThreeDsCapability::class)?->versions; // ['2.2.0']
```

Дубликаты типов capability отклоняются. Реализуйте маркер `Capability`, чтобы
поставлять провайдер-специфичные capability.

### Реестр и роутер

`GatewayRegistry` индексирует шлюзы по ключу провайдера и отклоняет дубликаты.
`PaymentGatewayRouter` выбирает провайдера **только при создании** через
принадлежащую приложению `GatewaySelectionPolicyInterface`; все остальные
операции маршрутизируются по провайдеру, уже вшитому в ссылку платежа/возврата:

```php
$registry = new GatewayRegistry([$stripeGateway, $adyenGateway]);
$router = new PaymentGatewayRouter(
    registry: $registry,
    selectionPolicy: new FixedGatewaySelectionPolicy(new PaymentProvider('stripe')),
);

$attempt = $router->createPayment(new GatewaySelectionContext(
    request: $createRequest,
    tenantId: 'tenant-1',
    riskLevel: 'low',
));

$refund = $router->createRefund($createRefundRequest); // маршрут по $request->payment->provider
```

Пакет не поставляет неявную политику по умолчанию — маршрутизация является
бизнес-решением. `FixedGatewaySelectionPolicy` — явная политика одного
провайдера. Вызов опциональной операции у шлюза, не реализующего нужный
ISP-интерфейс, бросает `\LogicException`; неизвестный провайдер —
`\OutOfBoundsException`. `GatewayRegistry::capability()` отвечает «поддерживает
ли провайдер X возможность Y», не трогая шлюз.

### Транспорт

```php
$request = (new JsonRequestBuilder($requestFactory, $streamFactory))
    ->build('POST', 'https://api.example.test/payments', ['amount' => 1200], AuthContext::bearer($token));
$response = (new Psr18Transport($client))->send($request);
$payload = (new JsonResponseDecoder())->decode($response);
```

`Psr18Transport` только делегирует вызов внедрённому клиенту. Политики повторов
и таймаутов остаются декораторами приложения. `FormRequestBuilder` кодирует
тела `application/x-www-form-urlencoded` только из скалярных данных.
`AuthContext` (`bearer()`, `basic()`, `fromHeaders()`) — in-memory передача
заголовков. `JsonResponseDecoder` преобразует HTTP- и провайдерские ошибки в
типизированные подклассы `PaymentException` и никогда не журналирует учётные
данные или исходные заголовки:

| Условие | Исключение |
|---|---|
| 401 | `UnauthorizedException` |
| 403 | `ForbiddenException` |
| 404 | `NotFoundException` |
| 429 | `RateLimitedException` |
| 5xx | `ServerException` |
| Тип `idempotency_error` | `IdempotencyConflictException` |
| Тип `refund_error` / refund-код | `RefundFailedException` |
| Тип `card_error` / `decline` | `ProviderDeclinedException` |
| Прочие не-2xx | `PaymentException` |
| Тело 2xx — не JSON-объект | `MalformedResponseException` |
| Ошибка PSR-18 | `TransportException` |

Статус решает раньше, чем читается тело. Ответ-ошибка, тело которого не
JSON-объект (HTML или пустая страница балансировщика, CDN или WAF во время
сбоя), всё равно бросает исключение своей строки по статусу — с сообщением
`Payment provider request failed` и без провайдерских метаданных. Поэтому 5xx
остаётся retryable `ServerException`, а `MalformedResponseException` значит
только то, что не удалось прочитать успешный ответ.

`PaymentException` предоставляет санитизированные `providerCode`,
`providerType`, `providerParameter` и скалярный `details` по allow-list:
`request_id` и `retry_after` с верхнего уровня тела, `decline_code` и
`payment_intent` (id из `error.payment_intent`) из объекта ошибки. Карта,
отклонённая при подтверждении во время create, оставляет платёж у провайдера, и
`details['payment_intent']` — способ повторить попытку на нём, а не создавать
второй. Заголовки ответа декодер не читает, поэтому если провайдер шлёт
request id или `Retry-After` только заголовком, эти ключи пусты — читайте их из
ответа в адаптере.

`IdempotencyConflictException` обычно значит, что ключ переиспользован с
запросом, отличным от первого: ошибка вызывающего. Повтор с тем же ключом упадёт
так же, повтор с новым ключом повторит операцию, поэтому ни то ни другое не
политика ретраев. Один случай ретраибелен: `providerCode`
`idempotency_key_in_use` (Stripe отвечает 409) значит, что параллельный запрос с
тем же ключом ещё выполняется, и повтор позже с тем же ключом вернёт его
результат. Строка про refund ловит любой провайдерский код, содержащий `refund`,
включая коды вроде `charge_already_refunded`; адаптер, которому нужно точнее,
классифицирует по `providerCode` сам.

### Webhooks

`WebhookProcessor` навязывает такой порядок ingress:

```text
проверить подпись -> захватить event id -> распознать и смапить -> durable-приём
```

Адаптеры предоставляют реализации валидатора, экстрактора типа события,
распознавателя и маппера payload. Приложение предоставляет атомарное хранилище
событий и один из двух режимов приёма:

```php
$acceptance = new QueuedWebhookEventAcceptance($queue); // AfterValidation
// или: new SynchronousWebhookEventAcceptance($reconciler); // AfterPersistence

$processor = new WebhookProcessor(
    validator: $validator,
    eventTypeExtractor: $extractor,
    eventRecognizer: $recognizer,
    payloadMapper: $mapper,
    eventStore: $eventStore,
    eventAcceptance: $acceptance,
);

$result = $processor->process(new WebhookInput(
    rawBody: $rawBody,
    provider: $provider,
    headers: $headers,
    requestMetadata: ['request_id' => $requestId],
));
```

`AfterValidation` означает подтверждение только после успешной валидации и
durable-приёма очередью. `AfterPersistence` выполняет reconciliation синхронно
и подтверждает только после того, как авторитетное состояние повторно получено
и сохранено. Исключение приёма пробрасывается, а незавершённый захват
освобождается, чтобы провайдер мог повторить доставку.

Обработка возвращает запечатанный `WebhookProcessingResult`:
`ProcessedWebhook`, `WebhookValidationFailed`, `UnknownWebhookEvent`,
`UnsupportedWebhookEvent`, `RejectedWebhookEvent` или `ReplayedWebhookEvent`.
Сужайте через
`instanceof` — каждый исход предоставляет только валидные для него поля:
обработанный результат всегда несёт своё событие и политику подтверждения, а
отказ — свою причину. Повтор никогда не запускает распознавание и маппинг
заново.

Маппер выдаёт `ObservedPaymentEvent` — санитизированное **наблюдение**, а не
авторитетное состояние провайдера. Оно связывает ссылку платежа (или возврата)
с провайдер-нейтральным состоянием, сырым статусом провайдера и скалярным
payload по allow-list; вложенные провайдерские данные отклоняются, потому что
наблюдения durable.

### HTTP-endpoint для webhook

`WebhookProcessorRegistry` сопоставляет провайдеров процессорам;
`WebhookController` — PSR-7 адаптер над реестром:

```php
$registry = new WebhookProcessorRegistry([
    new WebhookProcessorRegistration(provider: new PaymentProvider('stripe'), processor: $stripeProcessor),
]);
$controller = new WebhookController(registry: $registry, responseFactory: $responseFactory);

$response = $controller->handle($serverRequest, 'stripe');
```

Контроллер никогда не включает провайдерские payload или причины валидации в
ответы; исход раскрывается только в заголовке `X-Payments-Webhook-Outcome`.

Монтируйте webhook-маршрут **до** любого body-parsing middleware. Проверка
подписи покрывает точные полученные байты, а поток, уже прочитанный другим
middleware, читается как пустая строка — и тогда любая проверка провалится при
верном секрете и верной подписи. У этого случая отдельный исход `empty_body`,
чтобы он диагностировался по ответу, а не выглядел как неправильный секрет:

| Результат | Статус | Заголовок исхода |
|---|---|---|
| Неизвестный/невалидный ключ провайдера | 404 | `provider_not_found` |
| `ProcessedWebhook` | 204 | `processed` |
| `ReplayedWebhookEvent` | 204 | `replayed` |
| `UnknownWebhookEvent` | 204 | `ignored_unknown` |
| `UnsupportedWebhookEvent` | 204 | `ignored_unsupported` |
| `RejectedWebhookEvent` | 204 | `rejected_payload` |
| `WebhookValidationFailed` | 400 | `validation_failed` |
| Пустое тело запроса | 400 | `empty_body` |
| Исключение обработки | 503 | `processing_failed` (retryable) |
| Чужой тип результата | 500 | `processing_failed` |

### Публичный API

| Область | Типы |
|---|---|
| Value-объекты | `Money`, `OperationId`, `PaymentProvider`, `PaymentReference`, `RefundReference`, `CustomerReference`, `PaymentMethodReference`, `RefundReason`, `ProviderEventType`, `ProviderRequestInfo`, `PaymentFailure` |
| Enum'ы | `PaymentState`, `RefundState`, `CaptureMethod`, `ConfirmationMethod`, `WebhookAcknowledgementPolicy` |
| Доменные модели | `PaymentIntent`, `PaymentAttempt`, `RefundAttempt`, `ObservedPaymentEvent` |
| Запросы операций | `CreatePaymentRequest`, `CapturePaymentRequest`, `PaymentOperationRequest`, `CreateRefundRequest`, `RetrievePaymentRequest`, `RetrieveRefundRequest` |
| Контракты шлюзов | `PaymentGatewayInterface`, `CaptureGatewayInterface`, `ConfirmGatewayInterface`, `CancelGatewayInterface`, `RefundGatewayInterface` |
| Capability | `Capability`, `CapabilitySet`, `PartialRefundCapability`, `SandboxCapability`, `ThreeDsCapability`, `WebhookCapability` |
| Next actions | `NextAction`, `RedirectToUrl`, `UseSdk`, `DisplayInstructions` |
| Маршрутизация | `GatewayRegistry`, `GatewaySelectionPolicyInterface`, `FixedGatewaySelectionPolicy`, `GatewaySelectionContext`, `PaymentGatewayRouter` |
| Транспорт | `TransportInterface`, `Psr18Transport`, `TransportException` |
| Построение запросов | `RequestBuilderInterface`, `RawJsonRequestBuilderInterface`, `JsonRequestBuilder`, `FormRequestBuilder`, `AuthContext` |
| Декодирование ответов | `ResponseDecoderInterface`, `JsonResponseDecoder`, `MalformedResponseException` |
| Ошибки провайдера | `PaymentException`, `UnauthorizedException`, `ForbiddenException`, `NotFoundException`, `RateLimitedException`, `ProviderDeclinedException`, `RefundFailedException`, `IdempotencyConflictException`, `ServerException` |
| Webhook вход/результаты | `WebhookInput`, `WebhookValidationResult`, `ValidWebhook`, `InvalidWebhook`, `WebhookProcessingResult`, `ProcessedWebhook`, `WebhookValidationFailed`, `UnknownWebhookEvent`, `UnsupportedWebhookEvent`, `ReplayedWebhookEvent`, `WebhookAcknowledgementPolicy` |
| Контракты webhook-адаптера | `WebhookValidatorInterface`, `WebhookEventTypeExtractorInterface`, `WebhookEventRecognizerInterface`, `WebhookPayloadMapperInterface`, `UnsupportedWebhookEventException` |
| Контракты webhook-persistence | `WebhookEventStoreInterface`, `WebhookClaimToken`, `WebhookEventQueueInterface`, `WebhookReconcilerInterface`, `WebhookEventAcceptanceInterface` |
| Оркестрация webhook | `WebhookProcessorInterface`, `WebhookProcessor`, `QueuedWebhookEventAcceptance`, `SynchronousWebhookEventAcceptance` |
| Webhook HTTP | `WebhookProcessorRegistry`, `WebhookProcessorRegistration`, `WebhookController` |

Две webhook-иерархии различаются намеренно.

`WebhookValidationResult` **закрыта, и это навязывает PHP**:
`WebhookValidatorInterface::validate()` объявляет нативный union
`ValidWebhook|InvalidWebhook`, так что реализация не может ни расширить
сигнатуру (fatal error при объявлении класса), ни вернуть другой класс
(`TypeError` с именем нарушающего значения). Подпись либо подлинна, либо нет;
провайдер-специфичные данные принадлежат стадии маппинга.

`WebhookProcessingResult` остаётся **открытой**. Декорирующий процессор может
добавить собственный исход — троттлинг, приостановленный tenant, выключенная
фича — не дожидаясь релиза этого пакета. `WebhookProcessor::process()` сужает
возвращаемый тип до шести поставляемых исходов, поэтому вызов конкретного
процессора допускает исчерпывающую цепочку `instanceof`; вызов через
`WebhookProcessorInterface` означает обработку неизвестного исхода.

`WebhookInput::header()` требует непустого имени заголовка. Имена заголовков,
переданные в `WebhookInput`, должны быть токенами RFC 9110 (буквы, цифры и
``!#$%&'*+-.^_`|~``); имя из одних цифр, которое PHP превратил в int-ключ
массива, принимается как строка. Всё остальное бросает
`\InvalidArgumentException`, и `WebhookController` отвечает на это как на любое
исключение обработки — 503 `processing_failed` с записью в лог. PSR-7-запрос
такого имени нести не может, так что из контроллера это срабатывает только на
сломанной реализации сообщений.

## Что реализует ваше приложение

Ядро намеренно не содержит персистенции, очередей, политики маршрутизации и
кэша токенов. Для первой рабочей интеграции ожидайте написать (или взять из
своего стека):

| Вы предоставляете | Почему это ваше |
|---|---|
| `WebhookEventStoreInterface` | Атомарные `claim()`/`complete()`/`release()`, ограждённые `WebhookClaimToken`, поверх вашей БД или Redis — защита от replay есть решение хранилища |
| `WebhookEventQueueInterface` **или** `WebhookReconcilerInterface` | Durable-граница: очередь для `AfterValidation`, авторитетный re-fetch + персистенция для `AfterPersistence` |
| Персистенция intent/attempt | Проекции `PaymentIntent`/`PaymentAttempt`; адаптеры только возвращают попытки |
| `GatewaySelectionPolicyInterface` | Бизнес-маршрутизация (или `FixedGatewaySelectionPolicy` для одного провайдера) |
| Кэш токенов для OAuth-провайдеров | Например, PayPal — в адаптере `rasuvaeff/payments-paypal` (ещё не опубликован) есть `PayPalCachedTokenProvider`, остаётся только wiring |
| Retry/timeout-декораторы | Транспорт намеренно «глупый»; ретраить только идемпотентные операции |

## Безопасность

`AuthContext` — in-memory передача, а не сериализуемый DTO учётных данных.
`serialize()` бросает `\LogicException`, `var_dump()`/`print_r()` показывают
имена заголовков со значениями `[REDACTED]`, а параметры с секретом у
`bearer()`, `basic()` и `fromHeaders()` помечены `#[\SensitiveParameter]`, так
что бросившая фабрика не кладёт секрет в stack trace. `var_export()` и
приведение `(array)` читают приватное свойство напрямую — к этому объекту их не
применять. `basic()` отвергает username с `:` (RFC 7617 делит пару по первому
двоеточию).
Учётные данные никогда не должны попадать в URL или логи. Декодирование ошибок
сохраняет только провайдерские code/type/parameter плюс request- и
retry-метаданные по allow-list; исходные заголовки ответа и произвольные
значения payload отбрасываются, а провайдерский текст обрезается до 1024 байт
прежде чем поедет дальше внутри сообщения исключения.

Один путь утечки стоит назвать отдельно, потому что он обходит всё
перечисленное: `Psr18Transport` кладёт исключение PSR-18 в `previous`, а часть
клиентов держат на этом исключении объект запроса — вместе с заголовком
`Authorization`. Репортер ошибок, сериализующий цепочки исключений или
прикрепляющий упавший запрос, тем самым опубликует живой API-ключ. Настройте
репортер не прикреплять объекты запроса и вычищать `Authorization` в
before-send хуке.

Каждый value-объект идентичности валидирует вход паттернами с якорем `\z` и
ограничением длины в байтах, так что ключ провайдера, код валюты или id ссылки
с завершающим переводом строки отклоняется, а не проходит дальше молча.
`Money` использует только целочисленную арифметику — никакого дрейфа
float-округления в суммах. `RedirectToUrl` принимает только HTTPS URL.
`PaymentFailure`, `ObservedPaymentEvent` и request-метаданные принимают только
скалярные значения по allow-list.

`WebhookInput` держит точные байты тела и валидационные заголовки в памяти.
Никогда не журналируйте и не сериализуйте `rawBody`, `headers()` или
несанитизированные request-метаданные; для короткоживущих аудит-записей
используйте `sanitizedHeaders()` и `sanitizedRequestMetadata()`. Оба редактируют
значения по имени, сверяясь со списком классов секретов (authorization, cookie,
signature, secret, token, api key, credential, password, bearer, session,
private) — это best-effort фильтр, а не гарантия: незнакомый вендорский префикс
всё ещё может пройти, поэтому политика логирования остаётся на вашей стороне.
Ограничение размера тела запроса применяйте на HTTP-границе. Durable-очередь получает
только `ObservedPaymentEvent`, чей payload уже должен быть санитизирован
маппером провайдера.

У `WebhookEventStoreInterface` три вызова, и значимы все три. `claim()` обязан
быть атомарным и возвращает `WebhookClaimToken`; `complete()` помечает событие
завершённым. `release()` делает обычные сбои обработки повторяемыми.

Именно `complete()` делает восстановление протухших захватов возможным. Процесс
может умереть между `claim()` и концом обработки, не дойдя до `release()` —
SIGKILL, OOM-killer, `max_execution_time`, фатальная ошибка, рестарт пода. Без
сигнала о завершении хранилище не отличает захват «в работе» от завершённого, и
оба доступных выбора неверны: истекающие захваты — уже обработанное событие
обрабатывается повторно на следующем ретрае провайдера; неистекающие —
прерванное событие получает replay-исход, HTTP 204, провайдер прекращает
ретраи, и событие потеряно без единой ошибки. С `complete()` правило
однозначно: незавершённый захват с истёкшим лизом протух и обязан быть
перезахватываемым; завершённый не выдаётся никогда.

Токен — ограждение, делающее истечение лиза безопасным. `complete()` и
`release()` обязаны сверять его с сохранённым захватом и при несовпадении не
делать ничего: воркер, чей лиз истёк и захват перехвачен, держит устаревший
токен, и его `release()` не должен удалять живой захват нового владельца —
иначе третья доставка начнёт обработку параллельно.

Если durable-очередь живёт в той же БД, есть более простой вариант: коммитить
строку захвата и строку очереди одной транзакцией — тогда «захват существует»
уже означает «событие durable». Это работает только для очереди в той же БД:
брокер (SQS, Redis) в транзакцию не входит, и таким развёртываниям нужен лиз
плюс `complete()`.

Неотображаемый payload тоже не retryable. `WebhookProcessor` превращает
`MalformedResponseException` от маппера в `RejectedWebhookEvent`, завершает
захват и позволяет мосту подтвердить: те же байты будут падать так же вечно, а
провайдеры отключают эндпоинт, который постоянно ошибается, — и теряются
**последующие**, здоровые события. То же для
`UnsupportedWebhookEventException` (→ `UnsupportedWebhookEvent`). `reason` исхода —
сообщение исключения, обрезанное до 1024 байт по границе символа; пустое или
пробельное сообщение заменяется фиксированным, так что никакой текст сообщения
не превращает терминальный вердикт обратно в retryable-сбой. Записывайте отказы для человека: они значат,
что адаптер и провайдер разошлись в понимании формата. Реализации очереди должны быть идемпотентны по провайдеру и id
события провайдера, чтобы сбой процесса вокруг durable-границы не мог
продублировать бизнес-работу. Webhook-наблюдения никогда не обновляют
состояние платежа напрямую: `PaymentState::canTransitionTo()`
рекомендательна, а reconciliation обязан повторно получить авторитетное
состояние провайдера перед сохранением или публикацией события.
`WebhookController` не раскрывает вызывающей стороне внутренности валидации —
только статус-код и токен исхода. Из-за этой же
непрозрачности контроллер принимает необязательный PSR-3 логгер: иначе
`503 processing_failed` не оставляет следа причины, и обнаружение держится на
том, кто следит за долей 503.

```php
$controller = new WebhookController(
    registry: $registry,
    responseFactory: $responseFactory,
    logger: $logger, // необязателен; контракт ответа не меняется
);
```

## Примеры

Запускаемые сниппеты — в [examples/](examples/).

| Скрипт | Показывает | Нужен сервер? |
|---|---|---|
| `build-json-request.php` | Кодирование JSON-запроса и bearer-аутентификация | Нет |
| `process-webhook.php` | Валидация, захват от повторов и durable-приём очередью | Нет |

## Кукбук и безопасность

- [Кукбук](docs/cookbook.ru.md) — подключение Stripe/PayPal, маршрутизация,
  возвраты, приём вебхуков, политики подтверждения, ретраи.
- [Безопасность и хранение данных](docs/security-retention.ru.md) — работа с
  креденшалами, границы валидации вебхуков, сроки хранения по классам данных.

## Разработка

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

## Лицензия

BSD-3-Clause
