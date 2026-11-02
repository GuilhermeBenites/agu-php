# Fechamento da fase 0

Registro histórico da fase 0. Consulte o README para o estado atual do projeto.

Data: 30/10/2026. Estado: **concluída — planejamento e contrato**. A fase 1 não foi iniciada.

## Entregáveis

- [Escopo, decisões, responsabilidades, requisitos e etapas](PLANEJAMENTO.md).
- [OpenAPI 3.0.3, versão de contrato 0.1.0](openapi.yaml), com 6 caminhos, 9 operações e 14 schemas.
- [README](../README.md) com estado real do projeto, documentação e comandos planejados.
- [Enunciado original](ENUNCIADO.md), preservado integralmente a partir do README do skeleton.
- Revisão dos tipos e operações planejados pelo frontend, em seu próprio repositório.

## Decisões registradas

Dois repositórios independentes; exportação assíncrona e RabbitMQ fora do escopo atual. PHP 8.1, Symfony 6.4 já resolvido no skeleton, Doctrine com attributes e MySQL 8.0. Angular 17.3/TypeScript 5.4, Material e NgRx no consumidor.

JWT RS256, TTL inicial de uma hora, sessionStorage na SPA, restauração com GET /api/me e logout local. Tarefas com título/descrição, consulta por ID, PUT completo, paginação limitada e ordem fixa. Erros JSON uniformes, descrição anulável e datas UTC com precisão de segundos.

Redis permanece como decisão pendente da fase 4. Marcar tarefa como concluída, busca/filtros, tempo real e os demais opcionais não entram no contrato inicial. URLs dos repositórios próprios e referências publicadas serão acrescentadas na preparação de entrega.

## Verificações executadas

| Verificação documental | Resultado |
| --- | --- |
| Estrutura OpenAPI e referências internas, com openapi-spec-validator 0.7.2 | Aprovada |
| Exemplos do OpenAPI contra seus schemas, com openapi-schema-validator 0.6.3 e verificação de formatos | 28 exemplos aprovados |
| Casos inválidos contra schemas: campos ausentes/desconhecidos, tipos, limites, datas e paginação | 12 rejeições esperadas confirmadas |
| Correspondência dos campos/tipos/obrigatoriedade previstos no Angular | 10 interfaces, 30 campos e 12 códigos de erro conferidos |
| Exemplos JSON de lista vazia e erro no documento do frontend | 2 exemplos aprovados contra o OpenAPI |
| Operações públicas/protegidas, operationIds únicos e DELETE 204 sem schema de corpo | Aprovada nas 9 operações |
| Rastreabilidade dos obrigatórios do enunciado | Coberta pela matriz de requisitos |
| Links locais dos documentos e preservação do README original | Aprovados |
| Alterações restritas a documentação, sem mudanças em código, manifests ou lockfiles | Conferidas nos dois repositórios |

A validação foi executada em ambiente Python temporário, sem adicionar dependências ao PHP ou Angular. Comando estrutural executado na raiz deste repositório:

```sh
/private/tmp/agu-phase0-validation-20261030/bin/python -m openapi_spec_validator docs/openapi.yaml
```

Para reproduzir a validação estrutural em outro ambiente, usar o procedimento do [README](../README.md#verificações-e-testes). A conferência complementar usou os schemas do próprio OpenAPI para validar os exemplos, rejeitar 12 entradas inválidas e comparar as declarações de campos previstas no documento do frontend. Essa comparação não é uma compilação TypeScript nem uma execução da API.

## Limites da evidência e próxima fase

As verificações acima são estáticas/documentais. Não foram executados servidor, migrations, testes PHPUnit, testes Angular, E2E ou builds dos projetos. As regras textuais de normalização, limite da senha em bytes, isolamento, persistência, headers e expiração exigem testes da implementação nas fases correspondentes; schema válido não demonstra essas regras em execução.

O PHP local identificado é 8.4 e o Node local é 22; a fase 1 deve preparar os runtimes de referência documentados. Instalação limpa, scripts reais, configuração da URL da API, bootstrap, servidor HTTP, migrations e CI inicial são entregáveis dessa próxima fase.

Publicação, commits e PRs não foram realizados. A execução segue uma fase por vez, conforme solicitado.
