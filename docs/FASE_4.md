# Fase 4 — Redis com invalidação transacional

Redis aprovado ao solicitar o início da fase 4 em 31/10/2026. Implementação no backend; contrato HTTP 0.1.0 e interface de produto preservados. Exportação assíncrona/RabbitMQ permanecem fora do escopo.

## Implementação e decisões

TaskListQuery define a consulta substituível. ListTasks mantém o acesso ao MySQL; CachedTaskList o envolve por Decorator no container Symfony. Somente listagens são cacheadas, como TaskPage/TaskView/PageQuery imutáveis, sem entidades Doctrine, usuário, senha, JWT ou cabeçalhos HTTP. GET por ID continua consultando o banco.

O pool Symfony Cache usa Redis 7.4.12 no ambiente medido e PhpRedis 6.1.0. Redis fica na rede interna, sem porta publicada, sem persistência e com limite de 64 MB/allkeys-lru. Há um Redis separado para testes. O backend pode continuar funcionando sem Redis, inclusive nas escritas e no health de liveness.

Chaves incluem versão do formato/ordenação, ID do proprietário, geração, página e limite normalizados:

```text
tasks_v1_u{userId}_g{generation}_p{page}_l{limit}_created_id_desc
```

O namespace do pool separa ambientes. TTL padrão: 30 segundos. TASK_CACHE_ENABLED=0 desativa as leituras/escritas de cache; a geração ainda avança nas mutações. REDIS_URL configura conexão lazy, timeout e read_timeout de 0,1 s, sem repetição programada pelo cliente. A indisponibilidade pode acrescentar tentativas limitadas de rede na leitura; os tempos do MySQL e do sistema operacional não são um SLA total da requisição.

## Consistência e invalidação

A geração é users.task_cache_version, uma versão aleatória de 128 bits. Cada criação, edição ou exclusão altera tarefa e geração no mesmo flush/transação Doctrine. A nova geração só fica visível a outras conexões após commit. Novos usuários recebem geração aleatória; a migration preserva as contas existentes com uma geração inicial.

A autenticação já lê User no MySQL em cada requisição. A consulta usa a geração dessa mesma leitura, sem um SELECT adicional só para o cache. A invalidação é lógica: gerações anteriores ficam inacessíveis para requisições que carregam o usuário após o commit; suas entradas expiram pelo TTL. Nenhum DEL ou operação Redis precisa funcionar depois da escrita.

Intercalação verificada com MySQL/Redis reais:

1. Leitor obtém geração G1 e consulta um resultado antigo.
2. Escritor grava a tarefa e G2 atomicamente e confirma a transação.
3. Leitor termina atrasado e popula somente a chave G1.
4. A próxima autenticação obtém G2 e nunca lê a entrada G1.

Isso também evita ressuscitar dados antigos quando Redis volta após uma falha de conexão: as mutações continuaram avançando a geração no MySQL. Dois escritores que começaram em G1 produzem versões distintas, mesmo que carreguem User antes do primeiro commit. Falha de SQL desfaz tarefa e geração juntas.

A garantia é para escritas pelos serviços e leituras no banco primário. Uma requisição cuja autenticação precedeu um commit pode terminar com dados anteriores. Listagem e COUNT não constituem snapshot único; vale a semântica concorrente já definida no contrato. Alterações manuais por SQL/importações devem avançar a geração ou limpar o pool. Restauração do banco exige considerar também o cache.

## Indisponibilidade e custos

Symfony RedisAdapter trata falhas normais de leitura/gravação como miss e registra warning. O decorator também trata exceções específicas de cache/Redis. A consulta MySQL fornece o resultado; erro do banco é propagado e não disfarçado nem repetido como erro de cache. Se a gravação do cache falhar depois do cálculo, o resultado já calculado é reutilizado.

Warnings são enviados em JSON; a configuração não registra corpos de tarefas, senhas ou tokens. Cache HTTP continua no-store; X-Request-ID continua novo em cada resposta, inclusive em hits. Uma resposta quente ainda valida JWT e consulta o usuário.

Cada mutação acrescenta um UPDATE da linha do proprietário e invalida todas as páginas desse usuário. Escritas muito frequentes do mesmo usuário podem disputar essa linha; isso não foi submetido a teste de carga. Entradas antigas ocupam memória até expirar/serem removidas pela política de memória. Cache frio acrescenta acesso ao Redis e pode ser mais lento que a consulta direta.

A API de callback do Symfony mantém sua proteção local contra cálculos simultâneos em PHP-FPM; beta=0 desativa recomputação probabilística antecipada. Não foi criado nem demonstrado um lock distribuído entre hosts: preenchimentos redundantes são possíveis e não alteram a garantia de geração. Não há alegação de capacidade horizontal específica.

## Verificações executadas

| Verificação | Resultado |
| --- | --- |
| make setup | Extensão Redis, serviço interno e migration incremental; dados existentes preservados |
| make check | Composer, YAML/container/schema, PHPStan 6 sem supressões e formatter aprovados |
| make test-coverage | 86 testes, 1.095 asserções; 92,91% das linhas (262/282) |
| CachedTaskList | 95,83% das linhas; demais serviços de tarefas em 100% |
| Redis real | Frio 2 consultas de tarefas; quente 0; separação por proprietário/página/tamanho e TTL natural |
| Mutações | Criar/editar/excluir invalidam todas as variantes do dono, preservando cache de outra conta |
| Concorrência | Leitor atrasado após commit e escritores com geração inicial igual, em conexões MySQL distintas |
| Falhas | Redis realmente inacessível, recuperação com chaves antigas ainda presentes e rollback de SQL |
| HTTP | Hit ainda faz uma consulta de autenticação; não expõe cache sem token e gera request ID novo |
| E2E desktop/celular | 20 execuções com Redis ligado + 8 dos fluxos de tarefas com o Redis temporário desligado |
| Angular acumulado | 78 testes; lint/formatação e build de produção aprovados |

O teste de leitor atrasado controla a intercalação entre consulta, commit e preenchimento; não é um teste probabilístico de estresse. O cenário de falha retém chaves no Redis saudável enquanto uma conexão real para uma porta inacessível representa a perda de acesso, permitindo verificar recuperação com dados antigos presentes. O E2E adicional para o processo Redis inteiro do ambiente temporário e repete o CRUD pela interface.

## Medição reproduzível

```sh
make setup
make benchmark
```

Saída: backend/var/benchmarks/cache.json. A CI executa o mesmo comando e coleta o JSON com a cobertura. Um registro da execução local está em [medicoes/fase4-cache.json](medicoes/fase4-cache.json).

Mesma massa de 20 mil tarefas, dois proprietários, 240 caracteres de descrição e limite 20. Para cada página/cenário: 10 aquecimentos + 100 amostras, identidade Doctrine limpa. Sem cache chama ListTasks; frio esvazia somente o namespace do ensaio antes de cada amostra; quente pré-aquece o mesmo resultado. Fixture transacional com rollback e namespace exclusivo removido ao finalizar.

O tempo inclui serviço, Redis quando aplicável, hidratação e JSON. Exclui leitura do usuário, JWT, roteamento, rede até o navegador e limpeza/pré-aquecimento do cache. Execução sequencial, Docker/Linux aarch64, PHP 8.1.34/MySQL 8.0.44. Estes valores não representam latência HTTP nem vazão sob concorrência.

| Página | Cenário | Mediana (ms) | p95 (ms) | Consultas de tarefas |
| --- | --- | ---: | ---: | ---: |
| 1 | Sem cache | 0.805 | 0.838 | 2 |
| 1 | Cache frio | 0.883 | 0.933 | 2 |
| 1 | Cache quente | 0.064 | 0.072 | 0 |
| 250 | Sem cache | 2.382 | 2.463 | 2 |
| 250 | Cache frio | 2.443 | 2.517 | 2 |
| 250 | Cache quente | 0.064 | 0.076 | 0 |
| 500 | Sem cache | 3.952 | 4.247 | 2 |
| 500 | Cache frio | 4.074 | 4.559 | 2 |
| 500 | Cache quente | 0.066 | 0.078 | 0 |

Em todos os cenários sem cache/frio houve duas consultas de tarefas; no quente, zero. O teste HTTP mediu separadamente uma consulta de User mesmo no hit. EXPLAIN continua usando o índice composto com Backward index scan, sem Using filesort, e o índice simples com Using index no COUNT. Não se conclui que Redis seja necessário para todo CRUD; o ganho demonstrado é para releituras dentro do TTL, com a mesma geração/página/tamanho.

## Reprodução integrada e limites da entrega

No frontend, com caminho explícito ou referência Git compatível do backend:

```sh
E2E_VERIFY_REDIS_OUTAGE=1 bash scripts/e2e.sh /caminho/absoluto/para/agu-php
```

O coordenador cria API/MySQL/Redis temporários, executa 20 cenários, para somente o Redis temporário, repete oito execuções de tarefas, reinicia o Redis e remove a infraestrutura temporária. Não para o Redis de desenvolvimento. Os relatórios normal e de indisponibilidade ficam separados no frontend.

Verificações locais concluídas. A execução remota da CI, clones dos repositórios próprios e PRs permanecem pendentes da fase 5; não houve commit/push/publicação nesta fase.

Referências de implementação: [RedisAdapter/Symfony 6.4](https://symfony.com/doc/6.4/components/cache/adapters/redis_adapter.html), [pools de cache](https://symfony.com/doc/6.4/cache.html), [Decorator no container](https://symfony.com/doc/6.4/service_container/service_decoration.html) e [PhpRedis 6.1](https://github.com/phpredis/phpredis/releases/tag/6.1.0).
