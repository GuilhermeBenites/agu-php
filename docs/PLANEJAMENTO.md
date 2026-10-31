# Escopo, decisões e etapas

Fase 0 concluída documentalmente em 30/10/2026. As funcionalidades descritas aqui são o contrato de implementação; fundação, autenticação, tarefas e cache foram concluídos localmente nas fases 1 a 4. Entrega pretendida: 01/11/2026 à noite, America/Campo_Grande.

## Escopo

Entregar cadastro, login JWT e criação, consulta, edição e exclusão das tarefas do próprio usuário, com SPA Angular responsiva, paginação, testes e execução reproduzível.

- Dois repositórios independentes, por decisão do candidato: este backend e o frontend Angular. A pasta pai compartilhada é conveniência local.
- Exportação assíncrona e RabbitMQ fora do escopo atual, por decisão do candidato.
- Redis aprovado em 31/10/2026 e implementado na fase 4, com invalidação transacional, fallback e medição.
- SSE, WebSocket, Elasticsearch/OpenSearch e X.509 fora do escopo inicial.
- Tarefa terá título e descrição; marcar como concluída, busca, filtros e ordenação configurável ficam fora do contrato 0.1.0.
- Sem refresh token, revogação remota de JWT, exclusão de conta ou controle otimista de concorrência nesta versão.

O [enunciado original](ENUNCIADO.md) foi preservado. Os dois repositórios próprios e as respectivas PRs serão preparados para entrega, respeitando a orientação de não publicar alterações nos repositórios do avaliador.

## Decisões da fase 0

| Tema | Decisão | Justificativa / consequência |
| --- | --- | --- |
| PHP | PHP 8.1 no Docker e CI | Atender ao enunciado; o PHP 8.4 local não será a referência de compatibilidade |
| Symfony | Linha 6.4, mantendo o major 6 | O skeleton permite `^6.0` e seu lock já resolve 6.4; registrar essa interpretação do texto 6.0 |
| ORM | Doctrine 3 com attributes PHP | Compatível com as dependências existentes; schema controlado por migrations |
| Banco | MySQL 8.0, inclusive nos testes de integração | Exercitar constraints e comportamento do banco da entrega |
| Arquitetura | Monólito modular, DTOs, serviços focados e repositories | Separar HTTP, operações e persistência sem aumentar o número de serviços implantados |
| Frontend | Angular 17.3, TypeScript 5.4, Material 17 e NgRx 17 | Aproveitar a base e atender aos requisitos mais específicos do enunciado |
| Node | Node 20.20.2, fixado na fase 1 | Compatibilidade documentada com Angular 17.3; Node 22 local não é a referência |
| Contrato | OpenAPI 3.0.3, versão inicial 0.1.0 | Contrato versionado neste repositório, revisado antes da implementação |
| JWT | RS256, TTL inicial 3600s, configurável | Chaves geradas por ambiente; assinatura/expiração verificadas no servidor |
| Sessão SPA | Token em sessionStorage; estado NgRx guarda usuário/status | Persiste reload na mesma aba; sem persistir o token no Store/DevTools; armazenamento acessível a JS exige cuidado com XSS |
| Erros | Envelope único, inclusive erros do firewall | Frontend usa code/details e requestId, com status HTTP previsíveis |
| Paginação | page/limit; ordem fixa createdAt DESC, id DESC | Simples de navegar e indexar; sem snapshot entre requisições concorrentes |
| Edição | GET por ID e PUT de ambos os campos editáveis | Permitir URL direta de edição; atualização completa e explícita |
| Concorrência | Last-write-wins para edição | Conflitos otimistas ficam como evolução; updatedAt não é um token de versão |

Referências de compatibilidade: [Angular](https://angular.dev/reference/versions), [segurança Symfony 6.4](https://symfony.com/doc/6.4/security.html) e [OpenAPI 3.0.3](https://spec.openapis.org/oas/v3.0.3.html). Essas decisões ainda não modificam as dependências do skeleton.

## Contrato HTTP

Fonte de verdade: [openapi.yaml](openapi.yaml). Os exemplos descrevem o comportamento a implementar, não respostas já verificadas da aplicação.

| Método e caminho | Autenticação | Resultado de sucesso | Fase |
| --- | --- | --- | --- |
| GET /api/health | Público | 200 Health | 1 |
| POST /api/register | Público | 201 User | 2 |
| POST /api/login | Público | 200 LoginResponse | 2 |
| GET /api/me | Bearer | 200 User | 2 |
| GET /api/tasks | Bearer | 200 TaskPage | 3 |
| POST /api/tasks | Bearer | 201 Task + Location | 3 |
| GET /api/tasks/{id} | Bearer | 200 Task | 3 |
| PUT /api/tasks/{id} | Bearer | 200 Task | 3 |
| DELETE /api/tasks/{id} | Bearer | 204 sem corpo | 3 |

Regras que devem orientar DTOs e testes:

- Campos JSON em camelCase; entidades Doctrine nunca são retornadas diretamente.
- Username: entre 3 e 50 caracteres, sem espaços, primeiro alfanumérico, demais alfanuméricos ou `.`, `_`, `-`. Lowercase ASCII após validar; mesma regra no login e no cadastro. A SPA remove espaços das bordas antes do envio.
- Senha: mínimo de 8 caracteres e máximo de 72 bytes UTF-8; sem trim. Validar bytes explicitamente, além dos limites de schema. Persistir apenas o hash pelo hasher do Symfony.
- Title: de 1 a 255 caracteres no payload, com conteúdo não branco. Description: até 10000 caracteres no payload. Após validar os limites, aplicar trim; descrição vazia vira null.
- POST aceita description ausente e a normaliza para null. PUT exige title e description, permitindo null. Campos desconhecidos, inclusive userId e datas, são rejeitados com 422.
- IDs inteiros positivos até 2147483647. Proprietário obtido da autenticação, também incluído nos critérios de consulta/alteração/exclusão.
- ID inválido, ausente no banco ou pertencente a outra conta recebe a mesma resposta 404 `task_not_found`. URLs fora das rotas recebem 404 `not_found` no mesmo envelope.
- Datas UTC no formato `YYYY-MM-DDTHH:mm:ssZ`; createdAt imutável. Edições no mesmo segundo podem manter o mesmo valor textual de updatedAt.
- Paginação: page=1 e limit=20 por padrão; limit entre 1 e 100. Valores inválidos e parâmetros desconhecidos recebem 422. Página além do total recebe 200 com items vazio e totais preservados. Banco vazio tem totalPages=0.
- 400 para JSON malformado/corpo vazio; 415 para mídia de escrita não JSON; 422 para JSON válido com formato/campos inválidos; 409 para username duplicado. Os schemas descrevem os limites do payload recebido; a normalização só ocorre depois dessa validação.
- Autenticação antecede validação em rotas protegidas. No PUT, consulta do recurso por ID/proprietário antecede leitura/validação do corpo.
- Erros têm `error: {code, message, details}` e `requestId`. Details contém violações em 422 e array vazio nos outros casos. Mensagens em português; o frontend decide o comportamento pelo code e apresenta mensagens apropriadas.
- 405 usa `method_not_allowed` e header Allow. Falhas 500 usam `internal_error` sem detalhes internos. A padronização cobre erros gerados pela aplicação Symfony.
- Respostas da API incluem Cache-Control: no-store e X-Request-ID com 32 caracteres hexadecimais gerados pelo servidor. Em erros, o ID é igual ao do corpo. Redis é cache interno do servidor desde a fase 4.
- Health verifica somente inicialização HTTP. Conexão com MySQL é verificada separadamente na fundação; indisponibilidade do banco não é inferida a partir do health.

## Sessão e integração entre repositórios

O login devolve token, tokenType, expiresIn e user. No frontend, a sessão é mantida na aba usando sessionStorage. No reload, o cliente verifica exp localmente e consulta `/api/me` antes de liberar a área protegida; o servidor é a autoridade sobre validade do JWT.

Logout ou 401 em chamada protegida limpa o token e os estados de usuário/tarefas e leva ao login. Não há endpoint de logout/revogação: um JWT emitido permanece válido no servidor até expirar. Falha de rede/500 durante a restauração da sessão deve permitir nova tentativa, sem tratar a falha como credencial inválida. Após cadastro, navegar ao login.

Endereços locais previstos: HTTP da API em `http://localhost:8080/api` e SPA em `http://localhost:4200`. As portas são configuráveis. PHP-FPM não será exposto como endpoint HTTP do navegador.

Backend aceita CORS apenas das origens configuradas; no ambiente local, `http://localhost:4200`. Permitir Authorization, Content-Type, métodos da API e preflight OPTIONS público; expor X-Request-ID e Location. Sem autenticação por cookies e sem credentials CORS.

O frontend lê apiBaseUrl de uma configuração pública de execução, planejada em `/assets/config.json`. Nenhuma credencial pode entrar nesse arquivo. O interceptor envia Bearer apenas ao prefixo exato da API configurada e às operações protegidas. Não importar código por caminho relativo da pasta irmã.

O OpenAPI deste repositório será referenciado por tag ou commit quando os repositórios próprios estiverem publicados. Até lá, os documentos do frontend identificam este arquivo e sua versão 0.1.0. Alterações do contrato exigem revisão coordenada dos DTOs, tipos, examples e testes consumidores. Não duplicar o OpenAPI no frontend.

## Rastreabilidade dos requisitos

| Requisito / qualidade | Entrega responsável | Fase | Evidência prevista |
| --- | --- | --- | --- |
| PHP/Symfony, Doctrine/MySQL | Backend, ambiente e migrations | 1 | Instalação limpa, schema válido e testes no MySQL |
| DTO e Services | Backend, autenticação e tarefas | 2–3 | Revisão das fronteiras e testes dos serviços/HTTP |
| JWT e cadastro/login | Backend e frontend | 2 | API, formulários, sessão e E2E real |
| Adicionar tarefa | Backend e frontend | 3 | 201, persistência e E2E de criação |
| Editar tarefa | Backend e frontend | 3 | GET/PUT, validação, datas e E2E de edição |
| Remover tarefa | Backend e frontend | 3 | 204 sem corpo e E2E de exclusão |
| Listar todas as tarefas próprias | Backend e frontend | 3 | Paginação navegável, ordenação e totais |
| Angular 17 / TypeScript / SASS / HTML / CSS | Frontend | 1–3 | Build de produção e revisão de templates/estilos |
| Comunicação REST entre frontend e backend | Ambos | 2–3 | Contrato HTTP e E2E com API real |
| Material e interface responsiva | Frontend | 2–3 | Componentes, teclado e larguras móvel/desktop |
| Formulários reativos | Frontend | 2–3 | Testes de validação/erros e E2E |
| NgRx | Frontend, auth e tasks | 2–3 | Tests de reducers/selectors/effects e reset da sessão |
| Isolamento entre usuários | Backend, com E2E consumidor | 2–3 | Usuário B não acessa nem altera dados de A |
| Escalabilidade | Backend | 3–4 | Paginação, índice/EXPLAIN e medição; Redis implementado |
| Testes e organização | Ambos, em cada incremento | 1–5 | Suíte acumulada, análise estática e revisão |
| Dois repositórios reproduzíveis | Cada repositório | 1 e 5 | README, CI própria e instalação independente |

## Etapas e comandos planejados

| Fase | Entrega / aceite |
| --- | --- |
| 0 | Escopo e decisões, OpenAPI com exemplos válidos, revisão de compatibilidade do consumidor e relatório documental |
| 1 | Ambiente HTTP/MySQL, bootstrap, migrations, chaves JWT, qualidade/CI e testes básicos; health 200 e rota protegida 401 |
| 2 | Autenticação ponta a ponta; erros, expiração e restauração de sessão cobertos em API/Angular/E2E |
| 3 | CRUD/paginação completo; isolamento, validação, persistência, interface e E2E aprovados |
| 4 | Redis aprovado; invalidação, indisponibilidade, consistência e medição verificadas |
| 5 | Instalação limpa, suítes/builds/CI nos dois repositórios e documentação de entrega |

Comandos implementados na raiz deste repositório na fase 1, validados localmente:

| Comando | Finalidade |
| --- | --- |
| make setup | Preparação idempotente do ambiente, dependências, chaves locais e migrations, sem apagar dados |
| make up / make down | Iniciar/parar serviços do backend |
| make check | Composer validate, configuração/schema, PHPStan e formatter em modo de verificação |
| make test | Testes unitários, integração MySQL e HTTP, em banco exclusivo de testes |
| make test-coverage | Suíte com relatório de cobertura do código próprio |
| make benchmark | Mesma massa, consulta direta/cache frio/quente e EXPLAIN, com saída JSON |

Esses comandos executam PHP 8.1.34 no ambiente do projeto. A configuração de teste não poderá apontar para o banco de desenvolvimento. CI usará os mesmos scripts, com MySQL de teste. Testes de Redis entram apenas na fase correspondente.

Cada fechamento de fase registra entregas, comandos executados, resultado, pendências e a versão compatível do outro repositório quando houver integração. Teste obrigatório pendente mantém a fase funcional aberta. A revisão desta fase consta em [FASE_0.md](FASE_0.md).

Estado após a fase 1: fundação concluída localmente, com 11 testes/35 asserções, análise estática e migrations aprovados. Evidências em [FASE_1.md](FASE_1.md). Registro histórico da fundação; o estado mais recente está no fechamento da fase 4. A CI remota ainda não foi executada.

Estado após a fase 2: autenticação concluída localmente, com 36 testes/376 asserções e serviços de autenticação com 100% de cobertura de linhas. Integração navegador/API aprovada em 12 cenários coordenados pelo frontend. Evidências em [FASE_2.md](FASE_2.md). Registro histórico; as fases 3 e 4 foram concluídas depois, conforme os relatórios próprios.

Estado após as fases 3 e 4 (31/10/2026): CRUD/paginação e Redis concluídos localmente. Suíte: 86 testes/1.095 asserções, 92,91% das linhas; PHPStan/schema aprovados. E2E: 20 execuções com Redis e oito repetidas com Redis desligado, todas aprovadas. Índice/EXPLAIN e comparação sem cache/frio/quente registrados. A invalidação usa geração no mesmo commit da tarefa, evitando dependência de DEL após commit. Evidências em [FASE_3.md](FASE_3.md) e [FASE_4.md](FASE_4.md). Fase 5 permanece pendente; sem commit, push ou CI remota.

Fase 5: reprodução limpa e CI remota do backend aprovadas, com evidências em [FASE_5.md](FASE_5.md). Destinos próprios definidos e branch de implementação publicada. Abertura das PRs será manual por decisão do candidato; o estado do frontend é registrado no repositório correspondente.
