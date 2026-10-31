# Título

Implementar API de tarefas com JWT, isolamento por usuário e cache Redis transacional

# Descrição

O skeleton não oferecia um fluxo HTTP executável com autenticação e CRUD completos. Esta implementação entrega cadastro/login JWT, consulta da sessão e criação, listagem paginada, edição e exclusão de tarefas próprias, conforme o OpenAPI 0.1.0.

Controllers foram limitados à fronteira HTTP; DTOs/mappers validam o contrato, serviços representam casos de uso e repositories concentram consultas por proprietário. Redis envolve a listagem por Decorator, com geração gravada junto da tarefa no MySQL para impedir a reutilização de cache antigo após mutações ou indisponibilidade. Não há dependência do cache nas escritas.

O ambiente inclui Nginx/PHP/MySQL/Redis, migrations, geração de chaves locais, comandos de qualidade, cobertura, benchmark e CI. Backend e [frontend Angular](https://github.com/GuilhermeBenites/agu-angular) permanecem independentes.

## Validação local

- Instalação em cópia limpa, com novas dependências/chaves e bancos exclusivos; duas migrations aprovadas.
- make check, make test-coverage e make benchmark aprovados: 86 testes/1.095 asserções, 92,91% de linhas.
- OpenAPI e 59 validações de exemplos; 29 respostas HTTP contra schemas, mais CORS/preflight na API descartável.
- 20 execuções E2E em desktop/celular e oito repetidas com Redis desligado.

Relatórios, método de medição e limites em docs/FASE_4.md e docs/FASE_5.md. O ganho medido é do serviço de consulta; não há alegação de capacidade HTTP sob carga. Escritas do mesmo usuário disputam sua linha de geração; edições usam last-write-wins.
