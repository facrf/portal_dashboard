Contributing Guidelines

Obrigado pelo interesse em contribuir com este repositório.

Este projeto é um Dashboard customizável , com foco em simplicidade, clareza e manutenção fácil.
As regras abaixo existem para manter o histórico organizado e o código legível.
🎯 Objetivo do projeto

    Manter um Dashboard com foco em nao ter telemetria e bem organizado
    Garantir clareza no histórico de alterações
    Facilitar manutenção e evolução futura

📌 Padrão de commits

Use mensagens de commit claras, curtas e objetivas, no formato:

    content — alterações de texto, biografia, projetos

    style — layout, CSS, ajustes visuais

    fix — correções de erros

    config — configurações (CNAME, robots.txt, etc.)

    docs — documentação

    i18n — traduções

    chore — manutenção geral

🚫 Evite commits genéricos como:

    update
    fix stuff
    changes

🧩 Escopo dos commits

    Um commit deve representar uma única mudança lógica
    Evite misturar conteúdo, layout e configuração no mesmo commit
    Prefira commits pequenos e frequentes

📁 Organização do projeto

    index.php — portal público e monitoramento
    admin.php / config.php — áreas administrativas
    db.php — conexão, schema, migrações e sessão
    helpers.php — validação compartilhada
    tests/run.php — testes rápidos sem dependências externas
    Arquivos devem permanecer simples, legíveis e sem código não utilizado

🌍 Traduções (i18n)

    Textos traduzidos devem manter o mesmo significado do original
    Preserve consistência entre idiomas
    Evite traduções parciais sem contexto

🧪 Testes

    Antes de enviar uma alteração, execute:

    docker build -t portal-dashboard:test .
    docker run --rm portal-dashboard:test php tests/run.php

🚫 O que evitar

    Commits grandes sem explicação
    Alterações não relacionadas no mesmo commit
    Código comentado sem necessidade
    Arquivos temporários ou de backup no repositório

📄 Licença

Ao contribuir com este repositório, você concorda que sua contribuição será distribuída sob a mesma licença do projeto.

Obrigado por ajudar a manter este projeto organizado e profissional.
