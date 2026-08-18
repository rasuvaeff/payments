<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\RedirectToUrl;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RedirectToUrl::class)]
final class RedirectToUrlTest
{
    public function acceptsAbsoluteHttpsUrl(): void
    {
        $action = new RedirectToUrl(url: 'https://checkout.example.test/continue');

        Assert::same($action->type(), 'redirect_to_url');
        Assert::same($action->url, 'https://checkout.example.test/continue');
    }

    public function rejectsHttpUrl(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new RedirectToUrl(url: 'http://checkout.example.test/continue');
    }

    public function enforcesUrlLengthBoundary(): void
    {
        $prefix = 'https://example.test/';
        $valid = $prefix . str_repeat('x', 2048 - strlen($prefix));

        Assert::same(strlen((new RedirectToUrl(url: $valid))->url), 2048);

        Expect::exception(\InvalidArgumentException::class);
        new RedirectToUrl(url: $valid . 'x');
    }
}
