<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Contract;

use KaririCode\Contract\Configuration\ValueProcessorInterface;

/**
 * Local alias for the ecosystem ValueProcessorInterface.
 *
 * Maintained for backward compatibility during the 4.x cycle.
 * New code should type-hint against the canonical interface:
 * {@see \KaririCode\Contract\Configuration\ValueProcessorInterface}
 *
 * @deprecated 4.2.0 Use KaririCode\Contract\Configuration\ValueProcessorInterface directly
 *
 * @package KaririCode\Configurator
 * @since   4.1.0 ARFA 1.3
 */
interface ValueProcessor extends ValueProcessorInterface
{
}
