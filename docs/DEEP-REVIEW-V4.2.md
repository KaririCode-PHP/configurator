# Deep Review — KaririCode Configurator V4.2

Registro consolidado da revisão profunda da entrega V4.2 (promoção de
contratos + bridge Dotenv + refactor). Segue a disciplina de ADR do
KaririCode e a rastreabilidade ARFA 1.3 P5 (observabilidade).

## Método

Sem runtime PHP no ambiente de revisão, a **lógica de controle** de cada
componente crítico foi portada fielmente para Python e **executada** contra
os asserts reais dos testes. Validação estrutural (balanceamento, PSR-4,
`strict_types`, imports órfãos, tipos de retorno) por tokenizer de estado.

Limite do método: as portas validam algoritmo e fluxo, não a sintaxe PHP
exata nem tipagem estática. `composer test` + `phpstan analyse` continuam
obrigatórios antes de publicar.

## Achados

Bugs funcionais corrigidos (8 no total: 6 funcionais + 2 de precisão de doc).

| # | Sev | Componente | Sintoma | Correção |
|---|-----|------------|---------|----------|
| 1 | Alto | `EncryptedValueProcessor` | `decrypt(encrypt(''))` lançava "too short": `minLength` tinha `+1`, mas GCM produz ciphertext de 0 bytes para entrada vazia. `encrypt`/`decrypt` não eram inversos. | `minLength = NONCE + TAG` |
| 2 | Alto | `composer.json` | `require` pedia `kariricode/contract ^4.0`, mas as interfaces do ecossistema são `@since 4.1.0` — instalar 4.0.x daria "interface not found" em runtime. | Constraint `^4.1` |
| 3 | Médio | `YamlLoader` | `testDetectsMergeKey` falhava: `<<: *base` casava a detecção de alias antes da de merge key. | Merge key checado antes de alias |
| 4 | Médio | `YamlLoader` | `testNestedSequenceOfMappings` falhava: `-` puro seguido de mapping indentado roteava para `parseMapping` (retornava `{}`). | Dispatch inclui `firstChild === '-'` |
| 5 | Médio | `YamlLoader` | `0b1010`/`0o17` viravam `0`: `intval($v, 0)` não reconhece prefixos `0b`/`0o`. | Branches por radix; `0755` vira decimal (YAML 1.2) |
| 6 | Baixo | `EncryptedValueProcessor` | `hex2bin(): string\|false` atribuído a propriedade `string` (viola PHPStan máximo) + resolução de chave duplicada. | `resolveKey()` privado compartilhado |
| 7 | Baixo | `PhpLoader` | Doc afirmava "prevents directory traversal", mas `realpath()` só canonicaliza — sem diretório-base não há contenção. | Doc corrigida; contenção é do chamador |
| 8 | Baixo | `CacheBridge` | Doc dizia "compiled data", mas o bridge constrói `Configuration` direto, **sem a cadeia de value processors** (`${VAR}`, `ENC()`, `int:` não são aplicados). | Doc corrigida com o escopo real |

## Verificação executada

| Suíte (Python, executada) | Asserções |
|---------------------------|-----------|
| `EnvironmentProcessor` (parser de chaves aninhadas) | 22 |
| Composite / Dotenv / TypeCast / SchemaValidator | 47 |
| `RecursiveMerge` + resolução dot-notation | 12 |
| Roundtrip AES-256-GCM (vazio, unicode, adulteração, chave errada) | 10 |
| Parser YAML completo (conformance) | 45 |
| Parsing de inteiros por radix | 19 |
| `ReloadableConfiguration` (throttle + detecção mtime) | 10 |
| **Total** | **165** |

Estrutural: 64/64 arquivos balanceados · 41 fontes limpos (sem imports
órfãos, tipos de retorno presentes, nomes de classe batendo) · PSR-4
namespace↔path consistente · `composer.json` válido com `contract ^4.1`.

## Componentes auditados sem defeito funcional

`JsonLoader` (`JSON_THROW_ON_ERROR` + rejeição de não-array), `ReplaceMerge`
(`array_replace` top-level), `Psr11Bridge` e `ConfigurationProcessor` (ambos
via `ConfigurationBuilder`), accessores tipados (`getString`/`getArray`/`has`
com coerção scalar consistente e `null` tratado como ausente por toda a classe).

## Características de projeto (não são bugs)

- `ReloadableConfiguration`: deleção de arquivo observado **não** dispara
  reload (apenas mudança de mtime); edições no mesmo segundo colapsam
  (limite inerente do polling por `filemtime`, documentado no ADR-006).
- `has()` retorna `false` para chave presente com valor `null` — `null` é
  tratado como ausente de forma consistente em todos os accessores.

## Limitações do que não pôde ser verificado

Rodada 1: `php -l`, PHPUnit e PHPStan não rodaram (sem PHP no ambiente);
a lógica foi coberta por portas Python. **Superado pela Rodada 2**, que
executou tudo em PHP 8.4.26. O que continua sem verificação real está
listado ao fim da Rodada 2.

---

# Rodada 2 — execução real em PHP 8.4.26

## Método

- PHP 8.4.26, Composer, PHPUnit 11.5.56, PHPStan 2.x **nível max** sobre
  configurator, dotenv (bridge) e contract.
- Testes diferenciais do **loader PHP real** (não portas): YAML contra
  PyYAML 6.0.3; TOML contra `tomllib` — incluindo documentos que o oráculo
  rejeita e que o loader também deve rejeitar.
- Verificação automatizada da Code Quality Directive V4.0 / ARFA 1.3 e da
  exatidão de `@throws` (fechamento transitivo, herança de PHPDoc das
  interfaces; validada por teste de mutação).
- Harness fora do pacote: stub de `KaririCode\Contract\Processor\ProcessorInterface`
  com assinatura inferida (`process(mixed): mixed`).

## Achados e correções

| # | Componente | Achado | Severidade |
|---|---|---|---|
| 1 | YamlLoader | `- chave: valor` virava string — listas de objetos corrompidas | Alta |
| 2 | YamlLoader | sequência na indentação da chave gerava chaves numéricas | Alta |
| 3 | YamlLoader | `\|-` e `>-` perdiam o conteúdo; blocos perdiam linhas em branco | Alta |
| 4 | YAML e TOML | `\\` antes de aspa corrompia arrays e comentários | Alta |
| 5 | TomlLoader | 15 documentos inválidos aceitos; `a = 1` + `a.b = 2` descartava o valor; duplicatas sobrescreviam | Alta |
| 6 | CacheBridge | chave ordenava arquivos (merge depende da ordem); estratégia fora da chave; separador `\|` ambíguo | Alta |
| 7 | YamlLoader | comentário indentado com `:` virava chave; apóstrofo em texto plano escondia comentário; `\u` não decodificado | Média |
| 8 | YamlLoader | feature não suportada perdia o código 1007 (embrulhada em 1102); `catch (\Throwable)` | Média |
| 9 | TomlLoader | erro reportava `'toml'` como caminho e linha errada após array multi-linha | Média |
| 10 | Resolvers de sistema | `${argv}` resolvia para `"Array"` com warning | Média |
| 11 | EncryptedValueProcessor | `encrypt()` lançava `\RuntimeException` sem código → `encryptionFailed()` (1009) | Conformidade |
| 12 | Contratos | `get()` prometia lançar em path malformado (nunca lança); `getBool` citava "semântica de filtro" (falso); `Loader` omitia 1007/1008 | Documentação |
| 13 | EnvironmentProcessor | condição de laço sempre verdadeira | Baixa |
| 14 | Variádicos | argumentos nomeados quebravam o tipo `list` | Baixa |
| 15 | Testes | fixtures inválidos no diretório escaneado (3 erros desde a V4.0); `.toml` usado como extensão não suportada; teste gravando 27 arquivos na árvore de fontes; `tempnam` vazando em `/tmp`; 6 supressões `@` | Testes |
| 16 | composer.json | `ext-openssl` e interfaces PSR não declaradas; sugestão de bridge PSR-3 inexistente | Empacotamento |
| 17 | README / SPEC | "AES-256-GCM implementado do zero" (usa ext-openssl); tabelas de erro incompletas; taxonomia desatualizada; Psr11Bridge "requer PSR-11" | Documentação |

Causas-raiz do YAML e do TOML e o redesenho: emendas nos ADR-002 e ADR-008.

## Conformidade — Directive V4.0 / ARFA 1.3

- **Zero ocorrências:** docs tautológicas, constantes sem tipo,
  `@package`/`@since` ausentes, E-001, E-003, `toArray`/`fromArray`, `@`.
- **Aplicado:** markers normalizados; `final readonly class` em
  EnvironmentProcessor, EventDispatcherBridge e CacheBridge; enum puro
  `TomlDefinition`; `array_any` em `CompositeEnvironmentResolver::has()`;
  `array<array-key, mixed>` para dados de configuração (chaves numéricas
  viram `int` no PHP); imports no lugar de FQCN inline; `preg_match` no
  lugar de `ctype_xdigit`.
- **Exceções justificadas:** `catch (\Throwable)` em
  `EventDispatcherBridge::load()` (observa, emite evento e relança — P5);
  `ConfigurationException` não-final (raiz da hierarquia).
- **Factories sem emissor embutido** (1000, 1104, 1201, 1202, 1300):
  mantidas como vocabulário para implementações customizadas dos
  contratos de extensão e marcadas *custom* nas tabelas de erro. Revisão
  da decisão inicial de depreciá-las.

## Mudanças de comportamento

- YAML: `|` e `>` terminam com uma quebra de linha (clip chomping);
  `|-` / `>-` reproduzem o resultado anterior.
- TOML: documentos inválidos antes aceitos agora falham com 1102.
- CacheBridge: formato da chave mudou — entradas antigas expiram pelo TTL.
- `encrypt()`: falha do OpenSSL lança `ConfigurationException` 1009
  (subclasse de `RuntimeException` — compatível).

## Métricas finais (medidas)

| Gate | Resultado |
|---|---|
| `php -l` | 71 arquivos, 0 erros |
| PHPUnit | 336 testes, 601 asserções, 0 falhas |
| PHPStan nível max | 0 erros |
| YAML vs PyYAML | 42/42 (37 parses idênticos, 5 rejeições exigidas) |
| TOML vs tomllib | 33/33 (17 parses idênticos, 16 rejeições exigidas) |
| `@throws` | 0 enganosos, 0 ausentes |

## Ainda sem verificação real / decisões pendentes

- **Release:** `kariricode/contract` publicado vai até 2.8.6 no Packagist;
  `^4.1` é hoje impossível de instalar. Publicar o contract 4.1 antes.
- **`ProcessorInterface`:** assinatura `process(mixed): mixed` inferida do
  uso — não confirmada contra o contract 4.x (GitHub com rate-limit).
- **Markers:** a Directive diz 72 colunas; 376 de 424 markers do
  ecossistema têm 73. Normalizado para 73 até decisão.
- **`InvalidArgumentException` sem código** (3 erros de programação):
  mantidas por semântica SPL e compatibilidade; alternativa compatível é
  uma exceção própria que estenda `InvalidArgumentException` com named
  constructor.
- **`get()`/`has()`:** `null` armazenado é indistinguível de chave ausente
  (documentado; mudar seria quebra de compatibilidade).
- **YAML:** linha em branco dentro de escalar plano multi-linha vira espaço.
- Apenas PHP 8.4.26 foi exercitado.
