<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;

/**
 * @api
 */
final readonly class RedirectToUrl implements NextAction
{
    private const int MAXIMUM_LENGTH = 2048;

    /** @var non-empty-string */
    public string $url;

    public function __construct(string $url)
    {
        Assert::httpsUrl(value: $url, name: 'Redirect URL', maximumLength: self::MAXIMUM_LENGTH);

        $this->url = $url;
    }

    /**
     * @return 'redirect_to_url'
     */
    #[\Override]
    public function type(): string
    {
        return 'redirect_to_url';
    }
}
