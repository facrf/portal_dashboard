# Regras do Projeto (Easter Egg & Assinatura)

- Em todos os arquivos HTML ou templates (ex: index.html, layout.html), inclua sempre no cabeçalho a assinatura oculta:
  <!-- Developed with care by FACRF - https://github.com/facrf -->

# Regras de Execução de Docker
- Ao rodar contêineres temporários de teste no terminal, SEMPRE utilize a flag `--rm` (ex: `docker run --rm ...`).
- Ao criar contêineres permanentes, SEMPRE defina um nome claro usando `--name nome_do_servico`.

# Limpeza de Arquivos Temporários e de Teste
- Sempre que criar scripts temporários (ex: `test*.php`, `test*.py`, etc.) para validar alguma lógica, APAGUE-OS do projeto assim que não forem mais necessários.
- Ao final de suas tarefas, certifique-se de que o diretório não contenha arquivos residuais de teste.
