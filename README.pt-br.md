# Framework KaririCode: Componente de Configuração

[![en](https://img.shields.io/badge/lang-en-red.svg)](README.md)
[![pt-br](https://img.shields.io/badge/lang-pt--br-green.svg)](README.pt-br.md)

![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![PHPUnit](https://img.shields.io/badge/PHPUnit-78E130?style=for-the-badge&logo=phpunit&logoColor=white)

Um componente de gerenciamento de configuração flexível e poderoso para o Framework KaririCode, fornecendo recursos robustos de manipulação de configurações para aplicações PHP.

## Características

- Suporte para múltiplos formatos de arquivos de configuração (PHP, JSON, YAML)
- Estrutura de configuração hierárquica
- Acesso fácil aos valores de configuração
- Validação de configuração
- Estratégias de mesclagem para combinar múltiplas fontes de configuração
- Sistema de carregadores extensível para fontes de configuração personalizadas
- Manipulação segura de dados de configuração sensíveis

## Instalação

Para instalar o componente de Configuração do KaririCode, execute o seguinte comando:

```bash
composer require kariricode/configuration
```

## Uso Básico

### Passo 1: Configurando os Arquivos de Configuração

Crie seus arquivos de configuração nos formatos suportados (PHP, JSON ou YAML). Por exemplo:

```php
// config/app.php
<?php
return [
    'nome' => 'MinhaApp',
    'versao' => '1.0.0',
    'debug' => true,
];
```

```json
// config/banco_de_dados.json
{
  "host": "localhost",
  "porta": 3306,
  "usuario": "root",
  "senha": "secreto"
}
```

```yaml
# config/cache.yaml
driver: redis
host: localhost
porta: 6379
```

### Passo 2: Inicializando o Gerenciador de Configuração

Configure o gerenciador de Configuração no arquivo de inicialização da sua aplicação:

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

// Carregue os arquivos de configuração
$config->load(__DIR__ . '/../config/app.php');
$config->load(__DIR__ . '/../config/banco_de_dados.json');
$config->load(__DIR__ . '/../config/cache.yaml');
```

### Passo 3: Acessando os Valores de Configuração

Uma vez que a configuração esteja carregada, você pode acessar os valores desta forma:

```php
// Obter um único valor de configuração
$nomeApp = $config->get('app.nome');
$hostBD = $config->get('banco_de_dados.host');
$driverCache = $config->get('cache.driver');

// Verificar se uma chave de configuração existe
if ($config->has('app.debug')) {
    // Faça algo
}

// Obter todos os valores de configuração
$todasConfigs = $config->all();
```

### Passo 4: Usando Configuração Específica do Ambiente

Você pode carregar arquivos de configuração específicos do ambiente:

```php
$ambiente = getenv('APP_ENV') ?: 'producao';
$config->load(__DIR__ . "/../config/{$ambiente}/app.php");
```

## Uso Avançado

### Carregadores Personalizados

Você pode criar carregadores personalizados para tipos específicos de arquivo:

```php
use KaririCode\Configurator\Contract\Configurator\Loader;

class XmlLoader implements Loader
{
    public function load(string $path): array
    {
        // Implementação para carregar arquivos XML
    }

    public function getTypes(): array
    {
        return ['xml'];
    }
}

$config->registerLoader(new XmlLoader());
```

### Estratégias de Mesclagem

O componente suporta diferentes estratégias de mesclagem para combinar configurações:

```php
use KaririCode\Configurator\MergeStrategy\StrictMerge;

$config = new Configuration(
    mergeStrategy: new StrictMerge()
);
```

### Validação

O componente inclui validação automática dos valores de configuração:

```php
use KaririCode\Configurator\Validator\AutoValidator;

$config = new Configuration(
    validator: new AutoValidator()
);
```

## Desenvolvimento

Este pacote não mantém ferramentas de QA no `require-dev`. O [KaririCode Devkit](https://github.com/KaririCode-PHP/devkit) (`kcode`) instala PHPUnit, PHPStan, Psalm, PHP-CS-Fixer e Rector em `.kcode/` (ignorado pelo git) na primeira vez que um comando precisa deles.

Instale o `kcode` uma vez (PHP 8.4+):

```bash
wget https://github.com/KaririCode-PHP/devkit/releases/latest/download/kcode.phar
chmod +x kcode.phar
sudo mv kcode.phar /usr/local/bin/kcode
```

Na raiz do repositório:

```bash
composer install          # só as dependências de runtime
kcode init                # gera .kcode/ e instala o toolchain (primeira vez)
kcode test                # PHPUnit (--coverage, --suite=Unit, qualquer opção do PHPUnit)
kcode analyse             # PHPStan e depois Psalm
kcode cs:fix              # aplica o estilo de código (--check só relata)
kcode quality             # o gate do CI: cs-fixer, PHPStan, Psalm, PHPUnit
kcode quality --docker    # o mesmo gate dentro do container PHP do kcode
```

Ajustes do projeto ficam em `devkit.php` (`kcode init --config` gera um modelo); rode `kcode init` depois de editá-lo. `kcode clean --all` remove `.kcode/`.

## Licença

Este projeto está licenciado sob a Licença MIT - veja o arquivo [LICENSE](LICENSE) para detalhes.

## Suporte e Comunidade

- **Documentação**: [https://kariricode.org](https://kariricode.org)
- **Rastreador de Problemas**: [GitHub Issues](https://github.com/KaririCode-Framework/kariricode-configurator/issues)
- **Comunidade**: [Comunidade KaririCode Club](https://kariricode.club)
- **Suporte Profissional**: Para suporte de nível empresarial, entre em contato conosco em support@kariricode.org

## Agradecimentos

- A equipe do Framework KaririCode e contribuidores.
- A comunidade PHP pelo seu contínuo suporte e inspiração.

---

Construído com ❤️ pela equipe KaririCode. Capacitando desenvolvedores para construir aplicações PHP mais robustas e flexíveis.

Mantido por Walmir Silva - [walmir.silva@kariricode.org](mailto:walmir.silva@kariricode.org)
