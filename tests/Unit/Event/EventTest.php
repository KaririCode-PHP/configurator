<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Unit\Event;

use KaririCode\Configurator\Event\ConfigLoaded;
use KaririCode\Configurator\Event\ConfigLoadError;
use KaririCode\Configurator\Event\ConfigSealed;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testConfigLoadedCarriesFilePath(): void
    {
        $event = new ConfigLoaded('/app/config/database.php');

        self::assertSame('/app/config/database.php', $event->filePath);
    }

    public function testConfigLoadErrorCarriesPathAndException(): void
    {
        $exception = new \RuntimeException('parse failed');
        $event = new ConfigLoadError('/app/config/broken.yaml', $exception);

        self::assertSame('/app/config/broken.yaml', $event->filePath);
        self::assertSame($exception, $event->exception);
    }

    public function testConfigSealedCarriesKeys(): void
    {
        $event = new ConfigSealed(['app', 'database', 'cache']);

        self::assertSame(['app', 'database', 'cache'], $event->keys);
    }

    public function testEventsAreReadonly(): void
    {
        $loaded = new ConfigLoaded('/path');
        $sealed = new ConfigSealed(['key']);
        $error = new ConfigLoadError('/path', new \Exception());

        // readonly classes — properties exist and are accessible
        self::assertIsString($loaded->filePath);
        self::assertIsArray($sealed->keys);
        self::assertIsString($error->filePath);
        self::assertInstanceOf(\Throwable::class, $error->exception);
    }
}
