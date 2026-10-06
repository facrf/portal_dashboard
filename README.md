# 🌐 Portal Dashboard

> Um dashboard leve, extremamente customizável e focado em privacidade para o seu Homelab / Homeserver.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.1-777bb4.svg)](https://www.php.net/)
[![SQLite Version](https://img.shields.io/badge/SQLite-3-003b57.svg)](https://www.sqlite.org/)

O **Portal Dashboard** é uma alternativa minimalista e segura a ferramentas como Heimdall e Homepage. Ele foi projetado para quem deseja centralizar os acessos do seu servidor caseiro sem abrir mão do controle total sobre seus dados.

---

## 🎯 Filosofia do Projeto

* **Zero Telemetria incorporada:** Sem rastreadores, sem pingbacks, sem análise de dados externa. O que acontece no seu servidor, fica no seu servidor.
* **100% Local:** Dependência zero de APIs ou nuvens de terceiros.
* **Eficiência Máxima:** Construído puramente com **PHP** e **SQLite**, consumindo o mínimo possível de hardware (ideal para rodar em mini PCs, notebooks antigos ou Raspberry Pi).
* **Liberdade de Customização:** Configure e organize seus serviços de forma simples e direta.

---

## ✨ Funcionalidades

* 🚀 **Inicialização Instantânea:** Sem bancos de dados pesados ou processos de setup complexos.
* 📶 **Monitoramento Inteligente:** O painel testa automaticamente e em tempo real se seus serviços estão online, validando tanto requisições HTTP quanto conexões diretas a IPs ou portas (TCP/NTP) locais.
* 📱 **Design Responsivo:** Acesse e gerencie seu homelab perfeitamente pelo computador, tablet ou celular.
* 💾 **Persistência Simples:** Configurações armazenadas localmente em um banco de dados SQLite de arquivo único.
* 🎨 **Altamente Customizável:** Crie categorias, adicione links de serviços e organize o layout de acordo com sua necessidade.
* 🔒 **Privacidade Total:** Fontes e recursos 100% hospedados localmente. Sem chamadas para APIs de terceiros (como Google Fonts).

---

## 🛠️ Tecnologias Utilizadas

* **Backend:** PHP (8.1+)
* **Servidor Web:** Nginx
* **Banco de Dados:** SQLite 3
* **Licença:** GNU GPL v3

---

## 🚀 Como Instalar

Você pode rodar o Portal Dashboard diretamente no seu servidor web de preferência ou via Docker.

### Método 1: Servidor Web Local (Apache / Nginx)

1. **Requisitos Prévios:**
   * Servidor Web (Apache, Nginx, etc.)
   * PHP 8.1 ou superior instalado.
   * Extensões `pdo_sqlite`, `mbstring` e `curl` habilitadas; `yaml` para importar Homepage.

2. **Clonar o Repositório:**
   ```bash
   git clone https://github.com/facrf/portal_dashboard.git
   cd portal_dashboard
   ```

3. **Configurar Permissões:**
   O banco local fica em `db_data/bd.db`. Dê acesso de escrita somente a esse diretório:
   ```bash
   mkdir -p /caminho/para/portal_dashboard/db_data
   sudo chown -R www-data:www-data /caminho/para/portal_dashboard/db_data
   ```

   Para armazená-lo em outro local, defina `PORTAL_DB_PATH` com o caminho completo do arquivo.

4. **Acessar no Navegador:**
   Acesse `http://localhost/portal_dashboard` (ou o IP do seu servidor).

---

### 🐳 Instalação com Docker Compose (Recomendado)

Como a imagem do **Portal Dashboard** é compilada automaticamente e hospedada no GitHub Container Registry (GHCR), você não precisa clonar este repositório para rodar o projeto no seu servidor. Suporta `amd64`, `arm64`, `arm32v7` e `riscv64`.

1. Crie um arquivo chamado `docker-compose.yml` (ou crie uma nova **Stack** no seu Portainer).
2. Cole o seguinte conteúdo:

```yaml
version: '3.8'

services:
  portal-dashboard:
    image: ghcr.io/facrf/portal_dashboard:latest
    container_name: portal_dashboard
    ports:
      - "8080:80" # Porta onde o painel ficará acessível (mude se necessário)
    volumes:
      # Mapeamento seguro para o banco de dados
      - /seu_caminho/banco_portal:/var/www/db_data
      # Mapeamento dos ícones
      - /seu_caminho/icons:/var/www/html/icons
    restart: unless-stopped
```

A imagem não inclui uma coleção de ícones de terceiros. Monte sua própria pasta em
`/var/www/html/icons`, use o nome de um arquivo desse volume ou informe uma URL no
cadastro do serviço. Isso mantém a imagem pequena, reproduzível e sem downloads em
tempo de execução.

Se o portal estiver atrás de um proxy reverso, configure apenas o IP (ou CIDR restrito) desse proxy como confiável:

```yaml
    environment:
      - PORTAL_TRUSTED_PROXIES=172.20.0.10
```

Separe vários proxies com vírgulas. Não use todas as redes privadas (`10.0.0.0/8`, `172.16.0.0/12` ou `192.168.0.0/16`): prefira o IP fixo do Nginx Proxy Manager, Traefik ou Cloudflare Tunnel. O proxy deve sobrescrever ou anexar corretamente `X-Forwarded-For` e `X-Forwarded-Proto`. Em acesso direto, deixe a variável ausente.

---

## ⚙️ Customização

O portal permite consultar e abrir os serviços sem login, incluindo busca e indicadores de disponibilidade. O botão **Configuração** solicita autenticação e leva à área administrativa após o login. Gerenciar serviços e usuários, salvar anotações e reorganizar cards exige autenticação. Ao sair, você retorna ao portal público. No primeiro acesso administrativo, crie o administrador pela rede local.

O rodapé contém um **Mural de avisos**, visível para todos os visitantes. Após entrar, abra **Editar avisos**, escreva o comunicado e clique em **Publicar avisos**. Para ocultar o mural dos visitantes, apague o texto e publique novamente. As anotações já existentes são preservadas como avisos. O acesso sem login vale para quem conseguir alcançar o servidor: para disponibilizar o portal somente na rede interna, restrinja o acesso no firewall ou proxy. Cada serviço mantém sua própria autenticação.

Toda a configuração é feita diretamente pela interface do painel de administração (ou manipulando diretamente o banco SQLite se você preferir a linha de comando).

Você pode:
* Adicionar novos cards com ícones personalizados.
* Agrupar serviços por categorias (ex: Mídia, Monitoramento, Rede).
* Definir links internos (para uso local) e externos (via tunnels/reverso) para o mesmo serviço.

O estado dos serviços é consultado em lotes e mantido em cache por 45 segundos para
evitar que cada visitante gere uma conexão nova por card.

---

## ✅ Validação local

Para executar a mesma validação usada no CI:

```bash
docker build -t portal-dashboard:test .
docker run --rm portal-dashboard:test php tests/run.php
```

---

## 📄 Licença

Este projeto está sob a licença GNU GPL v3. Isso significa que você é livre para usar, modificar e distribuir o software, desde que mantenha as alterações sob a mesma licença de código aberto. Veja o arquivo LICENSE para mais detalhes.

Consulte também o [changelog](CHANGELOG.md) para detalhes de atualização e migração.

---

## 🤝 Contribuições

Feedbacks, relatórios de bugs e Pull Requests são extremamente bem-vindos!

1. Faça um Fork do projeto.
2. Crie uma branch para sua modificação (`git checkout -b feature/nova-funcionalidade`).
3. Envie suas alterações (`git commit -am 'Adiciona nova funcionalidade'`).
4. Faça o Push da branch (`git push origin feature/nova-funcionalidade`).
5. Abra um Pull Request.

---

Criado com ☕ por **facrf**.

---

## Monitoramento, sessões e importação

Cada serviço pode configurar um método (`auto`, `http`, `tcp`, `ntp`), um destino de monitoramento separado do link e os códigos HTTP aceitos. O padrão HTTP é `200-399`; para serviços que respondem com autenticação, use por exemplo `200-399,401,403`. A verificação HTTP usa HEAD, não segue redirecionamentos e valida o certificado TLS. HTTPS em portas como 8443 continua sendo verificado por HTTPS. TCP verifica a abertura da conexão; NTP exige resposta válida, com modo servidor e stratum de 1 a 15. No modo automático, `udp://` é tratado como NTP; outros protocolos UDP não são suportados.

O cache dura 45 segundos e é compartilhado pelos endpoints atuais e antigos. Uma reserva de até 8 segundos impede verificações simultâneas do mesmo serviço. O navegador usa no máximo dois lotes simultâneos, com até cinco serviços por lote. Falhas na consulta do portal aparecem como **indeterminado**, sem afirmar que o serviço está offline. Os indicadores são atualizados ao carregar a página; recarregue para consultar novamente.

Trocar uma senha revoga todas as sessões desse usuário, inclusive a sessão usada para fazer a alteração. A duração configurada é contada desde o login e conferida no servidor. Sessões anteriores à migração precisarão de um novo login. Diminuir a duração passa a valer na próxima requisição.

A importação tem duas etapas: **Revisar importação** e **Aplicar importação**. A prévia mostra serviços válidos, itens sem destino e erros. Ela expira em 10 minutos e é rejeitada se os dados do portal mudarem nesse intervalo. Cancelar ou falhar na validação mantém os dados existentes. A aplicação é transacional.

O backup nativo substitui configurações, categorias e serviços; contas e senhas são preservadas e não estão incluídas na exportação JSON. Configurações de monitoramento, tags e ordem são incluídas. Faça um backup antes de restaurar. Heimdall e Homepage adicionam apenas os serviços válidos revisados. O YAML é lido pela extensão `yaml`, suporta comentários, aspas e textos multilinha, e rejeita múltiplos documentos, âncoras, aliases e tags explícitas. Limites: 5 MB, 500 categorias, 5.000 serviços e 20.000 linhas YAML.

Arquivos do banco e seus auxiliares (`-wal`, `-shm`, `-journal`) ficam bloqueados no Nginx e Apache fornecidos. O Apache precisa respeitar `.htaccess`; em configurações próprias, bloqueie explicitamente `db_data`, `tests`, `templates`, `lang` e os módulos PHP internos. Prefira `PORTAL_DB_PATH` fora da raiz pública e mantenha as permissões de escrita apenas no diretório do banco. Os ícones podem ser montados como somente leitura.

A ordenação confirma o salvamento e restaura a posição anterior na interface em caso de falha. A busca externa exige clicar em **Pesquisar na web**, que informa o envio ao DuckDuckGo. Imagens e ícones externos configurados também fazem requisições a seus respectivos servidores.

## Desenvolvimento e publicação

A inicialização está dividida em `database.php` (SQLite e migrações), `auth.php` (sessões e acesso) e `i18n.php` (idiomas), carregados por `db.php`. A instalação e as migrações usam um lock por banco para impedir alterações concorrentes de schema. `health.php` concentra o monitoramento; `imports.php` concentra normalização, prévia e restauração. Os templates compartilhados estão em `templates/` e o JavaScript comum em `assets/ui.js`.

O CI valida pull requests antes do merge. Pushes em `main`, releases e execução manual validam o projeto antes de publicar no GHCR. Publicação: `linux/amd64`, `linux/arm64`, `linux/arm/v7`, `linux/riscv64`; tags `latest` na branch principal, `sha-<SHA completo>` e versões semânticas nas releases. O pacote YAML 2.3.0 tem SHA-256 fixo no Dockerfile e é compilado diretamente para evitar o problema de rede PECL no PHP 8.3/riscv64. A imagem candidata recebe a tag SHA e executa os testes PHP nas quatro arquiteturas (em riscv64, os testes CLI não criam subprocessos sob QEMU e são complementados pela integração HTTP externa com Nginx/PHP-FPM); somente após sucesso o pipeline atualiza `latest` e as tags de release. O repositório no GitHub precisa permitir escrita em Packages pelo `GITHUB_TOKEN`. Quando o desenvolvimento usa um remoto espelhado, o workflow inicia após o espelhamento alcançar o GitHub.

Validação completa local:

```bash
docker build -t portal-dashboard:test .
docker run --rm portal-dashboard:test php tests/run.php
docker run --rm -v "$PWD:/app:ro" -w /app node:22-alpine sh -c 'node --check assets/ui.js && node tests/ui.cjs'
docker run --rm -d --name portal-integration -p 127.0.0.1:18080:80 portal-dashboard:test
python3 tests/integration.py
docker run --rm --network container:portal-integration -v "$PWD:/app:ro" -w /app node:22-alpine node tests/rendered.cjs
docker stop portal-integration
```

Execute o teste HTTP exclusivamente contra um contêiner descartável com banco vazio: ele cria usuários, altera senhas e restaura serviços de teste. As suítes PHP removem o próprio banco temporário ao terminar.

A imagem Docker usa PHP 8.4 e inclui as extensões necessárias; instalações próprias exigem PHP 8.1+.
