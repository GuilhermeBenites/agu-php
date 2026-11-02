# Fechamento da fase 2 — Backend

Data: 30/10/2026. Estado: **concluída localmente — autenticação**. A fase 3 não foi iniciada.

## Entrega

- POST /api/register, POST /api/login e GET /api/me implementados conforme OpenAPI 0.1.0, preservando os caminhos definidos.
- Credentials com validação Symfony; mapper HTTP rejeita mídia incorreta, JSON malformado, formato/tipos inválidos e campos desconhecidos antes de chamar os serviços.
- RegisterUser e AuthenticateUser recebem DTO e retornam UserView/LoginView. Controllers não contêm persistência ou regra de autenticação; nenhuma entidade ou hash é serializado na resposta.
- Username ASCII validado e normalizado para lowercase; senha preservada sem trim, com mínimo de 8 caracteres e máximo de 72 bytes UTF-8.
- Hash pelo Symfony, unicidade por consulta e constraint MySQL; a corrida na gravação é convertida para UsernameTaken/409.
- JWT RS256, expiresIn refletindo JWT_TOKEN_TTL; GET /me valida a sessão e devolve apenas id/username.
- Envelope uniforme para 400/401/409/415/422, inclusive JWT ausente, inválido ou expirado. Endpoints públicos ignoram Bearer inválido; respostas protegidas incluem o desafio Bearer.
- Exceções de aplicação esperadas são convertidas antes do logger genérico do framework; falhas inesperadas continuam com requestId nos logs e resposta genérica.

## Verificações executadas

| Verificação | Resultado |
| --- | --- |
| Composer, YAML/container, schema MySQL, PHPStan nível 6 e formatter | Aprovados |
| PHPUnit com MySQL separado | **36 testes e 376 asserções aprovados** |
| Cobertura de linhas em RegisterUser e AuthenticateUser | **100% em ambos** |
| Cobertura global de linhas do backend | **71,11% — 128/180**, incluindo código de tarefas ainda provisório |
| Integração navegador/API/MySQL, coordenada pelo frontend | **12 cenários aprovados**, seis em desktop e seis em celular |

A suíte PHP inclui hash sem exposição de senha, normalização/case, credenciais incorretas com mensagem indistinguível, username duplicado, perda da corrida de unicidade, tipos/JSON/campos desconhecidos, senha Unicode no limite de bytes, ausência/adulteração/expiração de JWT e identidade retornada por /me.

A expiração do servidor foi verificada com um JWT realmente assinado e exp vencido. Os testes usam custo de hash reduzido somente em APP_ENV=test. A execução ponta a ponta utiliza o runtime de desenvolvimento, API real e banco novo em projeto Compose separado; não altera o banco de desenvolvimento principal.

## Reprodução e evidências

Na raiz deste repositório, com o setup da fase 1 pronto:

```sh
make check
make test-coverage
```

Cobertura em backend/var/coverage/html/index.html e clover.xml, ignorados pelo Git. A CI do backend executa os mesmos comandos; sua execução remota aguarda publicação dos repositórios próprios.

Para o E2E, seguir docs/TESTES_E2E.md no repositório frontend, passando explicitamente este diretório ou uma referência Git publicada. Os resultados e as capturas pertencem ao frontend.

## Limites do incremento

Sem refresh token ou revogação remota, conforme a decisão da fase 0. Logout encerra a sessão na SPA; o JWT emitido continua válido no servidor até expirar. CRUD/paginação/serviços finais de tarefas e Redis permanecem fora desta fase. Não houve migrations adicionais, publicação, commit ou PR.
