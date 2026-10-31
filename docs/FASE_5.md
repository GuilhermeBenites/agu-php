# Fase 5 — entrega e reprodução

Validação local concluída em 31/10/2026. Publicação e CI remota serão registradas abaixo após as execuções; abertura das PRs atribuída ao candidato por sua orientação.

## Reprodução limpa

Foram exportados os arquivos destinados ao Git para diretórios aleatórios independentes em /private/tmp, excluindo .git, vendor, node_modules, chaves JWT, variáveis locais, cobertura, builds e dados existentes. Como a implementação ainda não estava publicada, essa primeira prova usou snapshots do working tree; a validação remota posterior usa checkout Git.

Um projeto Compose exclusivo da API expôs 18080; o frontend exclusivo expôs 14200, com volume node_modules novo. Somente configuração documentada de porta, origem CORS e URL da API foi alterada. O ambiente de desenvolvimento em 8080/4200 foi preservado.

| Verificação | Resultado local |
| --- | --- |
| make setup | Dependências pelo lockfile, chaves novas e duas migrations em banco vazio |
| make check | Composer, YAML/container/schema, PHPStan 6 e formatter aprovados |
| make test-coverage | 86 testes/1.095 asserções; linhas 92,91% |
| make benchmark | Nove cenários, frio/direto com duas consultas de tarefas e quente com zero |
| Frontend independente | npm ci, lint, 78 testes/91,56% de linhas e build aprovados |
| OpenAPI | Documento válido, 59 validações de exemplos e 29 respostas HTTP contra schemas |
| CORS e cabeçalhos | Origem 14200, preflight PUT, Location, no-store, request ID e 204 vazio |
| E2E real | 20 execuções normais e oito com Redis desligado, desktop/celular |

O E2E também recebeu .env.local deliberadamente inválido na cópia do backend: banco/cache externos inexistentes, caminhos JWT inválidos, outro TTL/origem e cache desativado. As variáveis do processo temporário prevaleceram; as 28 execuções passaram e 23 chaves Redis estavam presentes antes da parada. As chaves do E2E passaram a usar volume próprio e o frontend pode usar E2E_FRONTEND_PROJECT, evitando recriar o projeto de desenvolvimento.

Os fontes funcionais foram comparados por hash entre origem e cópias após os ajustes de tooling; documentação/CI foi finalizada separadamente. [Registro e fingerprints](medicoes/fase5-reproducao.json). Logs locais em backend/var/release/phase5, fora do Git. Os enunciados originais foram preservados.

## Comandos utilizados

Na cópia da API, com HTTP_PORT=18080 no .env da raiz e CORS_ALLOW_ORIGIN para localhost:14200 em backend/.env.local:

```sh
make COMPOSE='docker compose -p agu-phase5-api' setup
make COMPOSE='docker compose -p agu-phase5-api' check
make COMPOSE='docker compose -p agu-phase5-api' test-coverage
make COMPOSE='docker compose -p agu-phase5-api' benchmark
```

O frontend foi instalado em outro diretório, com FRONTEND_PORT=14200 e apiBaseUrl=http://localhost:18080/api. O roteiro integrado está no frontend, com diretório explícito da API; não importa arquivos da pasta irmã.

O verificador em scripts/validate-contract.py valida o OpenAPI e exemplos por padrão. --api cria fixtures somente em uma API descartável indicada pelo executor; na reprodução usou o Nginx da rede exclusiva e --origin http://localhost:14200. Não imprime senhas ou tokens. O workflow valida estaticamente o contrato; PHPUnit/E2E verificam HTTP em ambientes isolados.

## Revisão para entrega

[Arquitetura](ARQUITETURA.md), [demonstração](DEMONSTRACAO.md) e [texto da PR](PR.md) finalizados. O README contém instalação, configuração e comandos independentes. Os limites de cache, concorrência, JWT e medição estão explícitos, sem alegação de capacidade sob carga.

Destinos definidos pelo candidato: [backend](https://github.com/GuilhermeBenites/agu-php) e [frontend](https://github.com/GuilhermeBenites/agu-angular). main mantém a base do skeleton; feat/complete-task-manager contém a implementação. A referência submission-v1 do backend identifica a versão consumida pelo E2E. A abertura das PRs será manual; não há envio ao repositório do avaliador.

## Publicação e CI remota

Em preparação. Não considerar CI remota aprovada até haver execução concluída registrada nesta seção. PRs não são abertas automaticamente por decisão do candidato.
