<?php

declare(strict_types=1);

namespace KaririCode\Configurator\Tests\Integration;

use KaririCode\Configurator\ConfigurationBuilder;
use KaririCode\Configurator\Event\ConfigReloaded;
use KaririCode\Configurator\Event\ConfigReloadFailed;
use KaririCode\Configurator\Exception\LoaderException;
use KaririCode\Configurator\Integration\ReloadableConfiguration;
use PHPUnit\Framework\TestCase;

final class ReloadableConfigurationTest extends TestCase
{
    private string $directory;

    private string $file;

    private RecordingDispatcher $dispatcher;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/kariricode-config-reload-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->file = $this->directory . '/app.json';
        $this->write($this->file, '{"app": {"name": "first", "port": 8080, "rate": 1.5, "debug": true, "tags": ["a"]}}', time() - 100);
        $this->dispatcher = new RecordingDispatcher();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = glob($this->directory . '/*');
        array_map(unlink(...), $files === false ? [] : $files);
        rmdir($this->directory);
    }

    public function testReadsServeTheBuiltConfiguration(): void
    {
        $config = $this->reloadable(interval: 3600.0);

        self::assertSame('first', $config->get('app.name'));
        self::assertSame('none', $config->get('app.missing', 'none'));
        self::assertSame('first', $config->getString('app.name'));
        self::assertSame(8080, $config->getInt('app.port'));
        self::assertSame(1.5, $config->getFloat('app.rate'));
        self::assertTrue($config->getBool('app.debug'));
        self::assertSame(['a'], $config->getArray('app.tags'));
        self::assertTrue($config->has('app.port'));
        self::assertFalse($config->has('app.missing'));
        self::assertSame(['app' => ['name' => 'first', 'port' => 8080, 'rate' => 1.5, 'debug' => true, 'tags' => ['a']]], $config->all());
    }

    public function testDefaultsPassThroughTheTypedReads(): void
    {
        $config = $this->reloadable(interval: 3600.0);

        self::assertSame('d', $config->getString('x', 'd'));
        self::assertSame(1, $config->getInt('x', 1));
        self::assertSame(0.5, $config->getFloat('x', 0.5));
        self::assertTrue($config->getBool('x', true));
        self::assertSame(['d'], $config->getArray('x', ['d']));
    }

    public function testAChangedFileReloadsOnTheNextCheck(): void
    {
        $config = $this->reloadable(interval: 0.0);

        self::assertFalse($config->checkAndReload());

        $this->write($this->file, '{"app": {"name": "second"}}', time());

        self::assertTrue($config->checkAndReload());
        self::assertSame('second', $config->getString('app.name'));
        self::assertFalse($config->checkAndReload());

        $reloaded = $this->dispatcher->of(ConfigReloaded::class);
        self::assertCount(1, $reloaded);
        self::assertSame([$this->file], $reloaded[0]->changedFiles);
        self::assertGreaterThanOrEqual(0.0, $reloaded[0]->reloadTimeMs);
    }

    /**
     * @return iterable<string, array{\Closure(ReloadableConfiguration): mixed}>
     */
    public static function reads(): iterable
    {
        yield 'get' => [static fn(ReloadableConfiguration $config): mixed => $config->get('app.name')];
        yield 'getString' => [static fn(ReloadableConfiguration $config): mixed => $config->getString('app.name')];
        yield 'getInt' => [static fn(ReloadableConfiguration $config): mixed => $config->getInt('app.port')];
        yield 'getFloat' => [static fn(ReloadableConfiguration $config): mixed => $config->getFloat('app.port')];
        yield 'getBool' => [static fn(ReloadableConfiguration $config): mixed => $config->getBool('app.debug')];
        yield 'getArray' => [static fn(ReloadableConfiguration $config): mixed => $config->getArray('app')];
        yield 'has' => [static fn(ReloadableConfiguration $config): mixed => $config->has('app.name')];
        yield 'all' => [static fn(ReloadableConfiguration $config): mixed => $config->all()];
    }

    /**
     * @param \Closure(ReloadableConfiguration): mixed $read
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('reads')]
    public function testEveryReadChecksForChanges(\Closure $read): void
    {
        $config = $this->reloadable(interval: 0.0);
        $this->write($this->file, '{"app": {"name": "second", "port": 9090, "debug": false}}', time());

        $read($config);

        self::assertCount(1, $this->dispatcher->of(ConfigReloaded::class));
        self::assertSame('second', $config->getString('app.name'));
    }

    public function testChecksWaitForTheInterval(): void
    {
        $config = $this->reloadable(interval: 3600.0);
        $this->write($this->file, '{"app": {"name": "second"}}', time());

        self::assertTrue($config->checkAndReload(), 'the first check runs at once');

        $this->write($this->file, '{"app": {"name": "third"}}', time() + 10);

        self::assertFalse($config->checkAndReload());
        self::assertSame('second', $config->getString('app.name'));
    }

    public function testForceReloadIgnoresTheIntervalAndReportsEveryWatchedFile(): void
    {
        $config = $this->reloadable(interval: 3600.0);
        $this->write($this->file, '{"app": {"name": "second"}}', time());

        $config->forceReload();

        self::assertSame('second', $config->getString('app.name'));
        self::assertSame([$this->file], $this->dispatcher->of(ConfigReloaded::class)[0]->changedFiles);
    }

    public function testAFileThatAppearsLaterCountsAsAChange(): void
    {
        $late = $this->directory . '/late.json';
        $builder = ConfigurationBuilder::create()->withFile($this->file);
        $config = new ReloadableConfiguration([$this->file, $late], $builder, 0.0, $this->dispatcher);

        self::assertFalse($config->checkAndReload(), 'a missing watched file is skipped');

        $this->write($late, '{}', time());

        self::assertTrue($config->checkAndReload());
        self::assertSame([$late], $this->dispatcher->of(ConfigReloaded::class)[0]->changedFiles);
    }

    public function testABrokenFileKeepsTheLastGoodConfigurationForReads(): void
    {
        $config = $this->reloadable(interval: 0.0);
        $this->write($this->file, '{"app": ', time());

        self::assertSame('first', $config->getString('app.name'));

        $failures = $this->dispatcher->of(ConfigReloadFailed::class);
        self::assertCount(1, $failures);
        self::assertSame([$this->file], $failures[0]->changedFiles);
        self::assertInstanceOf(LoaderException::class, $failures[0]->exception);
        self::assertSame([], $this->dispatcher->of(ConfigReloaded::class));

        self::assertFalse($config->checkAndReload(), 'the same broken file is not rebuilt again');
        self::assertCount(1, $this->dispatcher->of(ConfigReloadFailed::class));

        $this->write($this->file, '{"app": {"name": "fixed"}}', time() + 10);

        self::assertSame('fixed', $config->getString('app.name'));
    }

    public function testExplicitChecksRethrowAFailedRebuild(): void
    {
        $config = $this->reloadable(interval: 0.0);
        $this->write($this->file, '{"app": ', time());

        try {
            $config->checkAndReload();
            self::fail('the rebuild must fail');
        } catch (LoaderException) {
            self::assertCount(1, $this->dispatcher->of(ConfigReloadFailed::class));
        }

        $this->expectException(LoaderException::class);

        $config->forceReload();
    }

    public function testWithoutADispatcherAReloadStillHappens(): void
    {
        $builder = ConfigurationBuilder::create()->withFile($this->file);
        $config = new ReloadableConfiguration([$this->file], $builder, 0.0);
        $this->write($this->file, '{"app": {"name": "second"}}', time());

        self::assertTrue($config->checkAndReload());
        self::assertSame('second', $config->getString('app.name'));
    }

    public function testTypedReadsWithoutADefaultUseTheContractDefaults(): void
    {
        $config = $this->reloadable(interval: 3600.0);

        self::assertSame(0, $config->getInt('missing'));
        self::assertSame(0.0, $config->getFloat('missing'));
        self::assertFalse($config->getBool('missing'));
    }

    public function testAMissingWatchedFileDoesNotHideALaterChange(): void
    {
        $other = $this->directory . '/other.json';
        $this->write($other, '{"other": 1}', time() - 100);
        $builder = ConfigurationBuilder::create()->withFile($this->file)->withFile($other);
        $missing = $this->directory . '/missing.json';
        $config = new ReloadableConfiguration([$missing, $this->file, $other], $builder, 0.0, $this->dispatcher);

        $this->write($this->file, '{"app": {"name": "second"}}', time());
        $this->write($other, '{"other": 2}', time());

        self::assertTrue($config->checkAndReload());

        $reloaded = $this->dispatcher->of(ConfigReloaded::class)[0];
        self::assertSame([$this->file, $other], $reloaded->changedFiles);
        self::assertLessThan(60_000.0, $reloaded->reloadTimeMs);
        self::assertSame(2, $config->getInt('other'));
    }

    public function testAFailedRebuildWithoutADispatcherRethrowsTheBuilderFailure(): void
    {
        $builder = ConfigurationBuilder::create()->withFile($this->file);
        $config = new ReloadableConfiguration([$this->file], $builder, 0.0);
        $this->write($this->file, '{"app": ', time());

        self::assertSame('first', $config->getString('app.name'));

        $this->expectException(LoaderException::class);

        $config->forceReload();
    }

    private function reloadable(float $interval): ReloadableConfiguration
    {
        $builder = ConfigurationBuilder::create()->withFile($this->file);

        return new ReloadableConfiguration([$this->file], $builder, $interval, $this->dispatcher);
    }

    private function write(string $path, string $content, int $mtime): void
    {
        file_put_contents($path, $content);
        touch($path, $mtime);
        clearstatcache(true, $path);
    }
}
