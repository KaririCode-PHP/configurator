# ADR-005: Value Processor Chain for Extensible Transformations

## Status
Accepted

## Context
Configuration values often require transformations beyond what file
loaders provide: environment variable interpolation, type casting
from string representations, decryption of encrypted secrets, and
domain-specific conversions (URL parsing, DSN decomposition).

Symfony solves this with `EnvVarProcessorInterface` tightly coupled
to the DI container. Spring Boot uses `@Value` annotations with
`ConversionService`. Both approaches require framework coupling.

## Decision
Implement a **ValueProcessor** contract with a **ValueProcessorChain**
composite that applies transformations recursively across the entire
configuration tree at seal() time.

Built-in processors:
- **EnvironmentProcessor** — `${VAR:default}` interpolation
- **TypeCastProcessor** — `int:8080`, `bool:true`, `json:{...}`, `csv:a,b`
- **EncryptedValueProcessor** — `ENC(base64-ciphertext)` decryption

Chain evaluation order: processors execute in registration order.
Each processor's `supports()` is called first; only matching processors
transform the value. Results cascade: processor N's output is input to
processor N+1, enabling composition (env→typecast→decrypt).

TypeCastProcessor is extensible via `registerCaster(prefix, closure)`,
allowing domain-specific casters without core modification.

## Consequences
- Zero-dependency transformations — no DI container required
- Composable: `${PORT}` → `int:8080` → `8080` (int) in single pass
- Processing happens before validation, so schema checks transformed values
- Custom processors integrate via the ValueProcessor contract
- EncryptedValueProcessor uses AES-256-GCM — production-grade security
