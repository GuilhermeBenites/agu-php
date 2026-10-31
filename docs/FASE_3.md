# Fase 3 — tarefas completas

Implementação funcional validada em 30/10/2026; fechamento documental consolidado em 31/10/2026, após a interrupção da sessão. Contrato OpenAPI 0.1.0 preservado. A fase 4 revalida esse mesmo CRUD com cache e está registrada separadamente.

## Entrega

CRUD restrito ao proprietário autenticado, DTOs de entrada/resposta, serviços CreateTask, ListTasks, FindOwnedTask, UpdateTask e DeleteTask e consultas em TaskRepository. Controllers cuidam de HTTP e não serializam entidades Doctrine.

GET /api/tasks oferece paginação com page=1/limit=20, limite 100 e ordenação createdAt DESC/id DESC. GET por ID permite edição por URL. POST devolve 201/Location; PUT exige título e descrição, permitindo null; DELETE devolve 204 sem corpo. Textos são validados antes de trim; descrição vazia vira null.

IDs inválidos, ausentes no banco ou pertencentes a outra conta têm o mesmo 404. Na edição, a busca pelo proprietário ocorre antes de ler o corpo. Autenticação, erros, datas UTC e cabeçalhos seguem o contrato. createdAt permanece na edição; updatedAt tem precisão de segundos. Edições concorrentes seguem last-write-wins.

## Evidência executada na fase 3

| Verificação | Resultado |
| --- | --- |
| make check | Composer, YAML/container, schema, PHPStan 6 e formatter aprovados |
| make test-coverage | 72 testes, 970 asserções; 92,46% das linhas (233/252) |
| Serviços de tarefas | 100% das linhas cobertas |
| Angular | 78 testes; build de produção aprovado |
| E2E real | 20 execuções aprovadas: 10 cenários em desktop/celular |
| Paginação | Massa estável de 25 tarefas, empates de data, sem omissões/repetições, limites e página além do total |
| Isolamento | Outra conta não lista, consulta, edita ou exclui; dados do proprietário preservados |
| Persistência/validação | Requisições independentes, datas, JSON/mídia/tipos/campos/limites Unicode, PUT completo, 204 vazio |

O E2E é coordenado no repositório frontend, com API e MySQL temporários e caminho explícito do backend; não depende de uma pasta irmã. Foram cobertos teclado, cancelamento da exclusão, edição após reload e recuperação de falhas na lista e nas escritas.

## Referência inicial da consulta

Medição de 30/10, sem Redis: MySQL 8.0.44/PHP 8.1.34, dois usuários com 10.000 tarefas cada, descrição de 240 caracteres. Cada cenário executou 10 aquecimentos e 100 amostras, com identidade Doctrine limpa. Fixture em transação com rollback.

| Página / limite | Mediana (ms) | p95 (ms) | Consultas de tarefas |
| --- | ---: | ---: | ---: |
| 1 / 20 | 0,990 | 1,278 | 2 |
| 250 / 20 | 2,700 | 3,114 | 2 |
| 500 / 20 | 4,353 | 5,721 | 2 |

EXPLAIN usou idx_tasks_user_created_id na seleção, com Backward index scan e sem Using filesort. COUNT usou o índice simples do proprietário com Using index; ele foi mantido. São estimativas do otimizador, não contagens medidas de linhas examinadas.

Esses tempos incluem serviço, hidratação e JSON; não incluem HTTP, JWT ou concorrência. OFFSET aumenta o custo nas páginas profundas. Não foi demonstrada capacidade de carga específica. O script evoluiu na fase 4 para comparar cenários com/sem cache na mesma execução, conforme FASE_4.md.

## Fechamento

Entregáveis funcionais concluídos localmente e revalidados na suíte acumulada da fase 4. README, contrato e relatórios acompanham cada repositório. Publicação, PRs, CI remota e reprodução a partir dos repositórios próprios permanecem na fase 5.
