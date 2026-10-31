# AGU — Backend

API de tarefas com PHP 8.1, Symfony 6.4, Doctrine e MySQL 8.0, consumida por um frontend Angular em repositório independente.

## Estado atual

Autenticação JWT, CRUD com DTOs/serviços, paginação isolada por usuário e cache Redis com invalidação transacional, testes e medição. Contrato OpenAPI 0.1.0 implementado; instalação e integração reproduzidas em cópias limpas.

Entrega em dois repositórios próprios. Evidências e estado da publicação/CI em [FASE_5.md](docs/FASE_5.md). Exportação/RabbitMQ permanecem fora do escopo.

## Preparação e execução

Requisitos: Docker com Compose e Make. Execute os comandos na raiz deste repositório; não é necessário instalar PHP/Composer no host nem ter o frontend em uma pasta irmã.

```sh
git clone --branch feat/complete-task-manager https://github.com/GuilhermeBenites/agu-php.git
cd agu-php
make setup
```

O setup constrói o PHP **8.1.34**, instala as dependências pelo lockfile, inicia MySQL/Redis, gera as chaves JWT locais sem sobrescrever as existentes, aplica migrations e inicia o Nginx. Pode ser executado novamente sem apagar os dados.

- API HTTP: `http://localhost:8080/api`.
- Health público: `http://localhost:8080/api/health`, retorna `{"status":"ok"}`.
- MySQL acessível apenas na rede interna do Compose; não ocupa a porta 3306 do host.
- Redis é interno, sem porta publicada, sem persistência e com limite de 64 MB. A API mantém o fluxo via MySQL se o cache ficar indisponível.
- MySQL de desenvolvimento usa volume persistente. O serviço mysql_test é separado e efêmero, iniciado pelos comandos de teste.

Para mudar a porta HTTP, copie `.env.example` para `.env` na raiz e altere HTTP_PORT. Configuração Symfony fica em `backend/.env`, com defaults locais versionados; variáveis reais ou `backend/.env.local` podem sobrescrever banco, APP_SECRET, JWT e CORS. As chaves PEM e os arquivos locais ficam fora do Git e do contexto da imagem.

```sh
make up      # iniciar após o setup
make logs    # logs dos serviços
make keys    # gerar chaves locais, preservando as existentes
make down    # parar serviços; preserva o volume do banco de desenvolvimento
```

O schema é controlado exclusivamente por migrations. O SQL de inicialização legado foi removido. A migration inicial cria users/tasks, unicidade de username, proprietário obrigatório com FK/cascade e índice para paginação.

## Verificações e testes

Após `make setup`:

```sh
make check
make test
make test-coverage
make benchmark
```

- `make check`: Composer validate, YAML/container Symfony, schema Doctrine/MySQL, PHPStan nível 6 e PHP CS Fixer em modo de verificação.
- `make test`: prepara migrations/schema no banco de testes e executa PHPUnit.
- `make test-coverage`: a mesma suíte com PCOV e relatórios em `backend/var/coverage/`.
- `make benchmark`: compara consulta direta, cache frio e quente com a mesma massa no banco de testes; resultado em `backend/var/benchmarks/cache.json`.
- O runner recusa banco de teste com nome igual ao de desenvolvimento ou sem sufixo `_test`, antes de executar migrations.
- Para aplicar a formatação: `docker compose exec -T backend composer format`.

A suíte acumulada tem **86 testes/1.095 asserções**, com **92,91% de cobertura de linhas**, usando MySQL e Redis reais separados do desenvolvimento. Inclui autenticação, CRUD, isolamento, paginação, TTL, invalidação, concorrência controlada, falha do cache e rollback. O frontend executou 20 cenários E2E com Redis e repetiu oito fluxos com o Redis temporário desligado. Evidências em [FASE_3.md](docs/FASE_3.md) e [FASE_4.md](docs/FASE_4.md).

A [CI própria](.github/workflows/ci.yml) usa esses mesmos comandos e publica o relatório de cobertura como artefato. O workflow também valida OpenAPI/exemplos; resultados locais e remotos são registrados em [FASE_5.md](docs/FASE_5.md).

## Autenticação

Cadastro recebe username/password e devolve id/username com 201. Login devolve token, tokenType, expiresIn e user. Envie Authorization: Bearer TOKEN ao consultar GET /api/me ou outras rotas protegidas. Senhas e hashes não aparecem nas respostas.

Username aceita 3 a 50 caracteres ASCII, conforme o contrato; senha tem no mínimo 8 caracteres e no máximo 72 bytes UTF-8. O servidor preserva a senha sem trim e normaliza o username para lowercase após validá-lo. O TTL inicial é 3600 segundos, configurável por JWT_TOKEN_TTL.

## Tarefas e cache

Todas as rotas de tarefas exigem JWT. GET /api/tasks aceita page/limit e retorna items/pagination; a ordenação é createdAt DESC/id DESC. POST cria com 201/Location; GET /api/tasks/{id} consulta; PUT substitui título/descrição; DELETE remove com 204 sem corpo. Propriedade vem da sessão, e outra conta recebe o mesmo 404 de tarefa inexistente. Consulte o OpenAPI para limites e exemplos.

CachedTaskList envolve ListTasks por Decorator. Chaves incluem usuário, geração, página, limite e ordenação. Uma geração gravada com cada mutação no mesmo commit invalida as páginas anteriores, inclusive quando Redis está indisponível. Não há operação Redis obrigatória no caminho de escrita. O cache guarda somente DTOs de listagem; GET por ID continua no banco.

Configuração em backend/.env, com sobrescrita local em backend/.env.local:

| Variável | Padrão | Finalidade |
| --- | --- | --- |
| TASK_CACHE_ENABLED | 1 | 0 desativa o cache sem mudar o contrato |
| TASK_CACHE_TTL | 30 | TTL positivo em segundos |
| REDIS_URL | redis://redis:6379/0 com opções | Conexão lazy; timeout/read_timeout 0,1 s; retry_interval=0 |

Reinicie o serviço backend após mudar a configuração local. As escritas continuam avançando a geração com o cache desativado, preparando uma reativação segura. Falhas são registradas como warnings em JSON. Redis de testes usa redis_test, sem compartilhar dados com o serviço de desenvolvimento.

No ensaio local, cache quente evitou as duas consultas de tarefas. O hit HTTP ainda consulta o usuário para autenticação; o ganho medido no serviço não equivale à latência HTTP. Cenários frios tiveram custo adicional. Método, números e limites em [FASE_4.md](docs/FASE_4.md).

## Integração e documentação

O frontend carrega apiBaseUrl de sua configuração pública de execução. Padrão de CORS: `http://localhost:4200`, sem cookies. Para outra origem, configure CORS_ALLOW_ORIGIN como regex restrita em `backend/.env.local` e atualize a URL da API no frontend conforme a porta utilizada.

- [Contrato OpenAPI 0.1.0 implementado](docs/openapi.yaml).
- [Escopo, decisões, requisitos e etapas](docs/PLANEJAMENTO.md).
- [Fechamento da fase 0](docs/FASE_0.md).
- [Fechamento da fase 1](docs/FASE_1.md).
- [Fechamento da fase 2](docs/FASE_2.md).
- [CRUD e medição inicial — fase 3](docs/FASE_3.md).
- [Cache, consistência, falhas e medição — fase 4](docs/FASE_4.md).
- [Entrega e reprodução limpa — fase 5](docs/FASE_5.md).
- [Arquitetura e decisões](docs/ARQUITETURA.md).
- [Roteiro de demonstração e contrato](docs/DEMONSTRACAO.md).
- [Enunciado original](docs/ENUNCIADO.md).

A validação automatizada de OpenAPI/exemplos e o teste opcional contra uma API descartável estão documentados em [DEMONSTRACAO.md](docs/DEMONSTRACAO.md).

## Submissão

Repositório: [GuilhermeBenites/agu-php](https://github.com/GuilhermeBenites/agu-php). Implementação na branch feat/complete-task-manager; main preserva o skeleton para comparação. Frontend: [GuilhermeBenites/agu-angular](https://github.com/GuilhermeBenites/agu-angular).

A abertura das PRs será feita manualmente pelo candidato. [Texto preparado](docs/PR.md) e [comparação da implementação](https://github.com/GuilhermeBenites/agu-php/compare/main...feat/complete-task-manager?expand=1). Nenhuma alteração será enviada ao repositório do avaliador.
