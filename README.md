# KaririCode Framework: Configuration Component

[![en](https://img.shields.io/badge/lang-en-red.svg)](README.md)
[![pt-br](https://img.shields.io/badge/lang-pt--br-green.svg)](README.pt-br.md)

![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![PHPUnit](https://img.shields.io/badge/PHPUnit-78E130?style=for-the-badge&logo=phpunit&logoColor=white)

A flexible and powerful configuration management component for the KaririCode Framework, providing robust configuration handling capabilities for PHP applications.

## Features

- Support for multiple configuration file formats (PHP, JSON, YAML)
- Hierarchical configuration structure
- Easy access to configuration values
- Configuration validation
- Merge strategies for combining multiple configuration sources
- Extensible loader system for custom configuration sources
- Secure handling of sensitive configuration data

## Installation

To install the KaririCode Configuration component, run the following command:

```bash
composer require kariricode/configuration
```

## Basic Usage

### Step 1: Setting Up Configuration Files

Create your configuration files in the supported formats (PHP, JSON, or YAML). For example:

```php
// config/app.php
<?php
return [
    'name' => 'MyApp',
    'version' => '1.0.0',
    'debug' => true,
];
```

```json
// config/database.json
{
  "host": "localhost",
  "port": 3306,
  "username": "root",
  "password": "secret"
}
```

```yaml
# config/cache.yaml
driver: redis
host: localhost
port: 6379
```

### Step 2: Initializing the Configuration Manager

Set up the Configuration manager in your application's bootstrap file:

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use KaririCode\Configurator\Configuration;
use KaririCode\Configurator\Loader\JsonLoader;
use KaririCode\Configurator\Loader\PhpLoader;
use KaririCode\Configurator\Loader\YamlLoader;

$config = new Configuration();

$config->registerLoader(new PhpLoader());
$config->registerLoader(new JsonLoader());
$config->registerLoader(new YamlLoader());

// Load configuration files
$config->load(__DIR__ . '/../config/app.php');
$config->load(__DIR__ . '/../config/database.json');
$config->load(__DIR__ . '/../config/cache.yaml');
```

### Step 3: Accessing Configuration Values

Once the configuration is loaded, you can access values like this:

```php
// Get a single configuration value
$appName = $config->get('app.name');
$dbHost = $config->get('database.host');
$cacheDriver = $config->get('cache.driver');

// Check if a configuration key exists
if ($config->has('app.debug')) {
    // Do something
}

// Get all configuration values
$allConfig = $config->all();
```

### Step 4: Using Environment-Specific Configuration

You can load environment-specific configuration files:

```php
$environment = getenv('APP_ENV') ?: 'production';
$config->load(__DIR__ . "/../config/{$environment}/app.php");
```

## Advanced Usage

### Custom Loaders

You can create custom loaders for specific file types:

```php
use KaririCode\Configurator\Contract\Configurator\Loader;

class XmlLoader implements Loader
{
    public function load(string $path): array
    {
        // Implementation for loading XML files
    }

    public function getTypes(): array
    {
        return ['xml'];
    }
}

$config->registerLoader(new XmlLoader());
```

### Merge Strategies

The component supports different merge strategies for combining configurations:

```php
use KaririCode\Configurator\MergeStrategy\StrictMerge;

$config = new Configuration(
    mergeStrategy: new StrictMerge()
);
```

### Validation

The component includes automatic validation of configuration values:

```php
use KaririCode\Configurator\Validator\AutoValidator;

$config = new Configuration(
    validator: new AutoValidator()
);
```

## Development

This package keeps no QA tools in `require-dev`. [KaririCode Devkit](https://github.com/KaririCode-PHP/devkit) (`kcode`) installs PHPUnit, PHPStan, Psalm, PHP-CS-Fixer and Rector into `.kcode/` (git-ignored) the first time a command needs them.

Install `kcode` once (PHP 8.4+):

```bash
wget https://github.com/KaririCode-PHP/devkit/releases/latest/download/kcode.phar
chmod +x kcode.phar
sudo mv kcode.phar /usr/local/bin/kcode
```

From the repository root:

```bash
composer install          # runtime dependencies only
kcode init                # generate .kcode/ and install the toolchain (first run)
kcode test                # PHPUnit (--coverage, --suite=Unit, any PHPUnit option)
kcode analyse             # PHPStan, then Psalm
kcode cs:fix              # apply the code style (--check reports only)
kcode quality             # the CI gate: cs-fixer, PHPStan, Psalm, PHPUnit
kcode quality --docker    # the same gate inside the kcode PHP container
```

Project overrides go in `devkit.php` (`kcode init --config` writes a template); run `kcode init` after editing it. `kcode clean --all` removes `.kcode/`.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support and Community

- **Documentation**: [https://kariricode.org](https://kariricode.org)
- **Issue Tracker**: [GitHub Issues](https://github.com/KaririCode-Framework/kariricode-configurator/issues)
- **Community**: [KaririCode Club Community](https://kariricode.club)
- **Professional Support**: For enterprise-level support, contact us at support@kariricode.org

## Acknowledgments

- The KaririCode Framework team and contributors.
- The PHP community for their continuous support and inspiration.

---

Built with ❤️ by the KaririCode team. Empowering developers to build more robust and flexible PHP applications.

Maintained by Walmir Silva - [walmir.silva@kariricode.org](mailto:walmir.silva@kariricode.org)
