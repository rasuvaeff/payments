<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments\Tests;

use Rasuvaeff\Payments\DisplayInstructions;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(DisplayInstructions::class)]
final class DisplayInstructionsTest
{
    public function carriesTypedInstructions(): void
    {
        $action = new DisplayInstructions(instructionType: 'bank_transfer', text: 'Use the reference shown', metadata: ['reference' => 'ABC']);

        Assert::same($action->type(), 'display_instructions');
        Assert::same($action->metadata['reference'], 'ABC');
    }

    public function rejectsEmptyFieldsAndInvalidMetadata(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new DisplayInstructions(instructionType: '', text: 'Text');
    }

    public function rejectsEmptyText(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new DisplayInstructions(instructionType: 'bank_transfer', text: '');
    }

    public function rejectsNumericMetadataKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new DisplayInstructions(instructionType: 'bank_transfer', text: 'Text', metadata: [0 => 'value']);
    }

    public function rejectsNonScalarMetadataValue(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new DisplayInstructions(instructionType: 'bank_transfer', text: 'Text', metadata: ['nested' => []]);
    }

    public function rejectsEmptyMetadataKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new DisplayInstructions(instructionType: 'bank_transfer', text: 'Text', metadata: ['' => 'value']);
    }

    public function acceptsBoundaryLengths(): void
    {
        $instructions = new DisplayInstructions(instructionType: str_repeat('x', 128), text: str_repeat('x', 4096));

        Assert::same(strlen($instructions->instructionType), 128);
        Assert::same(strlen($instructions->text), 4096);
    }

    public function rejectsValuesPastBoundary(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new DisplayInstructions(instructionType: str_repeat('x', 129), text: 'Text');
    }

    public function rejectsTextPastBoundary(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new DisplayInstructions(instructionType: 'bank_transfer', text: str_repeat('x', 4097));
    }
}
