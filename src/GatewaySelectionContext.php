<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

/**
 * Application routing inputs for one new provider attempt.
 *
 * @api
 */
final readonly class GatewaySelectionContext
{
    private const int VALUE_MAXIMUM_LENGTH = 255;

    /** @var non-empty-string|null */
    public ?string $tenantId;

    /** @var non-empty-string|null */
    public ?string $riskLevel;

    public function __construct(
        public CreatePaymentRequest $request,
        ?string $tenantId = null,
        ?string $riskLevel = null,
    ) {
        $this->assertOptionalValue(value: $tenantId, name: 'Tenant id');
        $this->assertOptionalValue(value: $riskLevel, name: 'Risk level');

        $this->tenantId = $tenantId;
        $this->riskLevel = $riskLevel;
    }

    /** @psalm-assert null|non-empty-string $value */
    private function assertOptionalValue(?string $value, string $name): void
    {
        if ($value !== null && (trim($value) === '' || strlen($value) > self::VALUE_MAXIMUM_LENGTH)) {
            throw new \InvalidArgumentException(sprintf('%s must be non-blank and at most 255 bytes', $name));
        }
    }
}
