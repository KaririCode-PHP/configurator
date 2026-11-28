<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Environment;

use KaririCode\Contract\Environment\EnvironmentResolverInterface;

/**
 * Chains multiple resolvers in priority order (first match wins).
 *
 * Enables composite resolution patterns without code duplication:
 *   - Dotenv loaded vars → system fallback
 *   - In-memory overrides → Dotenv → system
 *   - Vault → Dotenv → system
 *
 * @package KaririCode\Configurator
 * @since   4.2.0 ARFA 1.3
 */
final readonly class CompositeEnvironmentResolver implements EnvironmentResolverInterface
{
    /** @var list<EnvironmentResolverInterface> */
    private array $resolvers;

    public function __construct(EnvironmentResolverInterface ...$resolvers)
    {
        $this->resolvers = array_values($resolvers);
    }

    public function get(string $name): ?string
    {
        foreach ($this->resolvers as $resolver) {
            $value = $resolver->get($name);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        return array_any(
            $this->resolvers,
            static fn (EnvironmentResolverInterface $resolver): bool => $resolver->has($name),
        );
    }
}
