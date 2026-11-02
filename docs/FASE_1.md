# Fechamento da fase 1 — Backend

Data: 30/10/2026. Estado: **concluída localmente — fundação executável**. A fase 2 não foi iniciada.

## Entregáveis

- PHP 8.1.34 e Composer no Docker; Symfony 6.4.49, Runtime/Security e dependências instaláveis pelo lock, com platform PHP 8.1.
- Nginx HTTP em 8080, MySQL 8.0 interno e mysql_test separado; sem Redis/RabbitMQ no ambiente padrão e sem ocupar 80/3306 do host.
- Entidades tipadas/attributes, migrations como fonte única do schema e remoção do DDL de inicialização legado.
- Migration `Version20261030173938`: users/tasks, username único, FK/proprietário obrigatório, cascade e índice por usuário/data/ID.
- Health público; entry point 401; envelope de erro básico, request ID e no-store; CORS restrito com preflight público.
- Configuração JWT por ambiente e geração idempotente das chaves locais. Leitura da chave privada pelo usuário do PHP-FPM conferida sem exibir seu conteúdo.
- Makefile, PHPStan nível 6, PHP CS Fixer, PHPUnit/PCOV e workflow próprio de CI.
- [README executável](../README.md), com configuração, comandos e limites do incremento.

Os controllers herdados foram adaptados para APIs atuais e injeção de dependências. Tarefas são mapeadas explicitamente para JSON, evitando serializar o usuário e seu hash. DTOs/serviços/validação e contratos completos de autenticação/tarefas continuam nas fases 2 e 3.

## Verificações executadas

| Verificação | Resultado |
| --- | --- |
| Build Docker sem copiar vendor do host; Composer install pelo lock no PHP 8.1 | Aprovado |
| Bootstrap Symfony no runtime de referência | Symfony 6.4.49 / PHP 8.1.34 / UTC |
| Migration em MySQL novo | Uma migration, três instruções SQL aplicadas |
| `make setup`, incluindo instalação, chaves, migrations e HTTP | Aprovado; repetição preservou chaves e encontrou schema já atualizado |
| `make check` | Composer, YAML, container, schema, PHPStan e formatter aprovados |
| `make test-coverage` com MySQL separado | **11 testes e 35 asserções aprovados** |
| Runner com URL apontando para o banco de desenvolvimento | Rejeitado antes de migrations, conforme esperado |
| HTTP real em 8080, através do Nginx/FastCGI | Health 200 JSON, no-store e X-Request-ID |
| Chaves PEM e artefatos gerados | Ignorados pelo Git |

Casos automatizados: health sem exposição de configuração; tarefas sem JWT retornam 401 uniforme; método inválido retorna 405/Allow; preflight público; origens externa e localhost em outra porta não são liberadas; round trip ORM/MySQL com descrição null e datas imutáveis; unicidade de username, proprietário obrigatório/existente e cascade.

Cobertura global inicial: **42,86% das linhas (48/112)**. Inclui controllers de autenticação/tarefas ainda provisórios e sem cobertura funcional. O percentual será ampliado pelos casos de uso das fases 2 e 3; não representa cobertura completa do produto. Relatórios em `backend/var/coverage/html/index.html` e `clover.xml`, gerados localmente e ignorados no Git.

## Limites e continuidade

A CI foi escrita para executar os comandos validados localmente; execução remota ainda não ocorreu, pois os repositórios próprios não foram publicados. Não houve commits, push ou PRs.

O contrato OpenAPI 0.1.0 permanece alvo das fases 1 a 3. Fora deste aceite: DTOs/serviços de autenticação, validação final dos corpos, todas as variantes de erro JWT, GET /me, sessão completa na SPA, paginação e CRUD final, E2E de negócio e Redis. A fase 2 começa pela autenticação completa.
