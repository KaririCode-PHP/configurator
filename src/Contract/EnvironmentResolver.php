<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Contract;

use KaririCode\Contract\Environment\EnvironmentResolverInterface;

/**
 * Local alias for the ecosystem EnvironmentResolverInterface.
 *
 * Maintained for backward compatibility during the 4.x cycle.
 * New code should type-hint against the canonical interface:
 * {@see \KaririCode\Contract\Environment\EnvironmentResolverInterface}
 *
 * @deprecated 4.2.0 Use KaririCode\Contract\Environment\EnvironmentResolverInterface directly
 *
 * @package KaririCode\Configurator
 * @since   4.1.0 ARFA 1.3
 */
interface EnvironmentResolver extends EnvironmentResolverInterface
{
}
