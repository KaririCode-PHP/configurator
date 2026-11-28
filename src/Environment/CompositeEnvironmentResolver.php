<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Environment;

use KaririCode\Contract\Environment\EnvironmentResolver;

/**
 * Asks several environment resolvers in priority order and answers with
 * the first value found, so sources compose without code: a .env store
 * before the process environment, test overrides before both, a vault
 * before everything.
 *
 * Preconditions: resolvers are passed in priority order; an empty list
 * resolves nothing.
 *
 * Thread-safety: immutable; as safe as the resolvers it holds.
 *
 * Side effects: none of its own; each get() asks the resolvers in turn.
 *
 * Example:
 *
 *     $resolver = new CompositeEnvironmentResolver(
 *         new InMemoryEnvironmentResolver(['APP_ENV' => 'test']),
 *         new SystemEnvironmentResolver(),
 *     );
 *
 * References: ADR-004, ADR-010; kariricode/contract
 * `Environment\EnvironmentResolver`. ARFA 1.3 P2: composition.
 *
 * @since 4.2.0
 */
final readonly class CompositeEnvironmentResolver implements EnvironmentResolver
{
    /** @var list<EnvironmentResolver> */
    private array $resolvers;

    public function __construct(EnvironmentResolver ...$resolvers)
    {
        $this->resolvers = array_values($resolvers);
    }

    #[\Override]
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

    #[\Override]
    public function has(string $name): bool
    {
        return array_any(
            $this->resolvers,
            static fn(EnvironmentResolver $resolver): bool => $resolver->has($name),
        );
    }
}
