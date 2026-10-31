# Roteiro de demonstração — API

1. Em uma instalação nova, executar make setup e consultar /api/health: 200, sem detalhes internos.
2. Cadastrar duas contas e autenticar; mostrar que a senha não aparece nas respostas e que GET /api/me exige JWT.
3. Criar uma tarefa: 201, Location, datas UTC e descrição anulável. Listar com page/limit e abrir por ID.
4. Editar com PUT, preservando createdAt; excluir e conferir 204 sem corpo/404 na repetição.
5. Com token da outra conta, conferir lista própria e 404 em GET/PUT/DELETE do primeiro proprietário. Campos como userId são rejeitados.
6. Mostrar os resultados de make check, make test-coverage e make benchmark. O cache quente evita as queries de tarefas; a autenticação continua no banco.
7. Apresentar o teste de leitor atrasado e a geração transacional; explicar por que Redis indisponível não impede a escrita.

O [roteiro pela interface](https://github.com/GuilhermeBenites/agu-angular/blob/feat/complete-task-manager/docs/DEMONSTRACAO.md) cobre o mesmo fluxo no Angular. Não usar uma conta pessoal para demonstrar exclusões.

## Conferência automática do contrato

Validação do OpenAPI e de seus exemplos, sem executar a API:

```sh
docker run --rm -v "$PWD/docs:/work/docs:ro" -v "$PWD/scripts:/work/scripts:ro" -w /work python:3.12-slim sh -c 'pip install --no-cache-dir -r scripts/contract-requirements.txt >/dev/null && python scripts/validate-contract.py'
```

O script também aceita --api URL e --origin ORIGEM. Essa opção cria contas/tarefas de fixture e deve apontar para uma API descartável, como a reprodução isolada da fase 5. Ela confere schemas de respostas reais, cabeçalhos, CORS/preflight, paginação, isolamento e erros; não imprime JWTs ou senhas. A CI usa o modo estático; os testes PHPUnit/E2E verificam o HTTP em seus ambientes isolados.
