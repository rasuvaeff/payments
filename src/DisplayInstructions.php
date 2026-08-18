<?php

declare(strict_types=1);

namespace Rasuvaeff\Payments;

use Rasuvaeff\Payments\Internal\Assert;
use Rasuvaeff\Payments\Internal\Types;

/**
 * @api
 *
 * @psalm-import-type ScalarMap from Types
 */
final readonly class DisplayInstructions implements NextAction
{
    private const int INSTRUCTION_TYPE_MAXIMUM_LENGTH = 128;
    private const int TEXT_MAXIMUM_LENGTH = 4096;

    /** @var non-empty-string */
    public string $instructionType;

    /** @var non-empty-string */
    public string $text;

    /** @var ScalarMap */
    public array $metadata;

    /**
     * @param array<array-key, mixed> $metadata
     */
    public function __construct(
        string $instructionType,
        string $text,
        array $metadata = [],
    ) {
        Assert::nonEmpty(value: $instructionType, name: 'Instruction type', maximumLength: self::INSTRUCTION_TYPE_MAXIMUM_LENGTH);
        Assert::nonEmpty(value: $text, name: 'Instruction text', maximumLength: self::TEXT_MAXIMUM_LENGTH);
        Assert::scalarMap(value: $metadata, name: 'Instruction metadata');

        $this->instructionType = $instructionType;
        $this->text = $text;
        $this->metadata = $metadata;
    }

    /**
     * @return 'display_instructions'
     */
    #[\Override]
    public function type(): string
    {
        return 'display_instructions';
    }
}
