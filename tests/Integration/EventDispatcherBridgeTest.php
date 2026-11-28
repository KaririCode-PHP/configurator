<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Integration;

use KaririCode\Configurator\Contract\Loader;
use KaririCode\Configurator\Event\ConfigLoaded;
use KaririCode\Configurator\Event\ConfigLoadError;
use KaririCode\Configurator\Event\ConfigSealed;
use KaririCode\Configurator\Exception\AccessException;
use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Exception\ValidationException;
use KaririCode\Configurator\Integration\EventDispatcherBridge;
use KaririCode\Configurator\MergeStrategy\ReplaceMerge;
use KaririCode\Configurator\Validator\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class EventDispatcherBridgeTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../Fixture/Config';

    private RecordingDispatcher $dispatcher;

    #[\Override]
    protected function setUp(): void
    {
        $this->dispatcher = new RecordingDispatcher();
    }

    public function testLoadDispatchesConfigLoadedForEachFile(): void
    {
        $bridge = new EventDispatcherBridge($this->dispatcher);

        $bridge->load(self::FIXTURES . '/app.php')->load(self::FIXTURES . '/services.json');

        self::assertSame(
            [self::FIXTURES . '/app.php', self::FIXTURES . '/services.json'],
            array_map(static fn(ConfigLoaded $event): string => $event->filePath, $this->dispatcher->of(ConfigLoaded::class)),
        );
    }

    public function testAFailedLoadDispatchesConfigLoadErrorAndRethrows(): void
    {
        $bridge = new EventDispatcherBridge($this->dispatcher);
        $missing = self::FIXTURES . '/missing.yaml';

        try {
            $bridge->load($missing);
            self::fail('the file does not exist');
        } catch (LoaderException $exception) {
            $errors = $this->dispatcher->of(ConfigLoadError::class);

            self::assertCount(1, $errors);
            self::assertSame($missing, $errors[0]->filePath);
            self::assertSame($exception, $errors[0]->exception);
            self::assertSame([], $this->dispatcher->of(ConfigLoaded::class));
        }
    }

    public function testSealDispatchesTheTopLevelKeysAsStrings(): void
    {
        $bridge = new EventDispatcherBridge($this->dispatcher);
        $bridge->inner->merge(['app' => ['name' => 'demo'], 7 => 'seven']);

        $bridge->seal();

        $sealed = $this->dispatcher->of(ConfigSealed::class);
        self::assertCount(1, $sealed);
        self::assertSame(['app', '7'], $sealed[0]->keys);
    }

    public function testReadsDelegateToTheDecoratedConfiguration(): void
    {
        $bridge = new EventDispatcherBridge($this->dispatcher);
        $bridge->load(self::FIXTURES . '/app.php')->seal();

        self::assertSame('TestApp', $bridge->get('app.name'));
        self::assertSame('fallback', $bridge->get('app.missing', 'fallback'));
        self::assertSame('pgsql', $bridge->getString('database.driver'));
        self::assertSame(5432, $bridge->getInt('database.port'));
        self::assertSame(1.5, $bridge->getFloat('app.rate'));
        self::assertTrue($bridge->getBool('app.debug'));
        self::assertSame(['auth', 'cors', 'logging'], $bridge->getArray('middleware'));
        self::assertTrue($bridge->has('cache.ttl'));
        self::assertFalse($bridge->has('cache.missing'));
        self::assertSame($bridge->inner->all(), $bridge->all());
    }

    public function testDefaultsPassThroughTheTypedReads(): void
    {
        $bridge = new EventDispatcherBridge($this->dispatcher);
        $bridge->seal();

        self::assertSame('x', $bridge->getString('a', 'x'));
        self::assertSame(3, $bridge->getInt('a', 3));
        self::assertSame(2.5, $bridge->getFloat('a', 2.5));
        self::assertTrue($bridge->getBool('a', true));
        self::assertSame(['d'], $bridge->getArray('a', ['d']));
    }

    public function testTheMergeStrategyAndTheValidatorReachTheConfiguration(): void
    {
        $bridge = new EventDispatcherBridge($this->dispatcher, new ReplaceMerge(), new SchemaValidator(['app.missing' => 'string']));
        $bridge->load(self::FIXTURES . '/app.php')->load(self::FIXTURES . '/app.production.php');

        self::assertSame(['debug' => false], $bridge->getArray('app'));

        $this->expectException(ValidationException::class);

        $bridge->seal();
    }

    public function testTheInnerConfigurationTakesLoadersTheBridgeDoesNotWrap(): void
    {
        $bridge = new EventDispatcherBridge($this->dispatcher);
        $bridge->inner->registerLoader(new class implements Loader {
            #[\Override]
            public function load(string $path): array
            {
                return ['loaded' => basename($path)];
            }

            #[\Override]
            public function supports(string $extension): bool
            {
                return $extension === 'custom';
            }
        }, 'custom');

        $bridge->load('/virtual/settings.custom')->seal();

        self::assertSame('settings.custom', $bridge->getString('loaded'));
    }

    public function testASealedBridgeRejectsLoads(): void
    {
        $bridge = new EventDispatcherBridge($this->dispatcher);
        $bridge->seal();

        $this->expectException(AccessException::class);

        $bridge->load(self::FIXTURES . '/app.php');
    }
}
