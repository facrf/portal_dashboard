# Changelog

Todas as mudanças relevantes do Portal Dashboard são registradas neste arquivo.

## 2026-10-22 — Atualização para PHP 8.5, melhorias de segurança, performance e homelab

- Base Docker atualizada para PHP 8.5 (`php:8.5-fpm-alpine`, PHP 8.5.12).
- Removida chamada redundante a `curl_close()` em `health.php`, eliminando avisos de depreciação do PHP 8.5 (gerenciamento automático de ciclo de vida pelo garbage collector desde o PHP 8.0).
- Configurado OPcache dedicado e otimizado em `php-security.ini` para alta performance e baixo consumo de memória em hardware modesto (ex: Raspberry Pi).
- Otimização do SQLite com `PRAGMA synchronous = NORMAL` em modo WAL, reduzindo operações síncronas de I/O em disco preservando durabilidade.
- Migração 4 adiciona suporte a certificados SSL autoassinados no monitoramento de saúde (`health_insecure_ssl`), com suporte também via variável de ambiente `PORTAL_HEALTH_INSECURE_SSL` para ambientes homelab.
- Monitoramento concorrente de múltiplos serviços HTTP via `curl_multi` no endpoint de status em lote, acelerando a carga inicial do dashboard.
- Adicionada opção de download de snapshot binário online do SQLite via `VACUUM INTO` em `config.php`, garantindo backup consistente sem bloqueio do WAL.
- Suporte a PWA (Progressive Web App) com `manifest.webmanifest` e meta tags para instalação na tela inicial em dispositivos móveis e desktops.
- Atalho de teclado para pesquisa rápida de serviços (`/` ou `Ctrl+K` / `Cmd+K` para focar; `Esc` para desfocar).
- Reforço de segurança no Nginx com cabeçalho `Content-Security-Policy` estrito e rate limiting (`limit_req_zone`) no `/login.php`.
- Mantida compatibilidade com PHP 8.1+ para instalações manuais.

## 2026-10-15 — Otimizações de concorrência, compatibilidade e bloqueio de locks

- Aprimorados `.gitignore` e `.dockerignore` para ignorar completamente arquivos de trava de migração (`*.migration.lock`, `*.lock`), locks do SQLite (`*.db-wal`, `*.db-shm`, etc.) e conteúdo gerado de banco de dados local (`db_data/*`), preservando a estrutura base.
- Bloqueado o acesso web direto a arquivos `*.lock` e `*.migration.lock` no Nginx (`nginx.conf`) e Apache (`.htaccess`).
- Garantido rollback com tratamento de exceções robusto na transação `BEGIN IMMEDIATE` durante o cadastro do primeiro administrador em `login.php`.
- Substituído `rand()` por `random_int()` para delay anti-timing contra ataques de força bruta no login.
- Otimizada a limpeza de tentativas de login expiradas, executando apenas em requisições POST ou periodicamente, evitando travamento de escrita no SQLite durante requisições GET.
- Limpeza imediata da superglobal `$_SESSION = []` antes de `session_destroy()` no logout em `login.php` e `index.php`.
- Compatibilizada a checagem HTTP em `health.php` com PHP 8.2+ utilizando `CURLOPT_PROTOCOLS_STR` para prevenir avisos de depreciação de `CURLOPT_PROTOCOLS`.
- Centralizada a validação e contraste de cor de tags no dashboard através do helper `validatedColor()`.
- Criação de diretório de banco em `database.php` tornada idempotente e segura contra concorrência entre processos.
- Comparação estrita com `in_array(..., true)` na seleção de idioma em `i18n.php`.

## 2026-10-06 — Sessões, monitoramento e importação

- Bloqueado acesso HTTP ao diretório do banco, arquivos auxiliares SQLite e módulos internos.
- Separadas conexão/migrações, autenticação, idiomas, monitoramento e importação em módulos próprios; extraídos templates e JavaScript comuns.
- Migração 3 acrescenta versão de sessão, configuração de monitoramento e reservas de cache sem descartar dados.
- Trocar senha revoga todas as sessões do usuário; expiração absoluta passa a ser conferida no servidor. Sessões antigas exigem novo login.
- Inicialização/migração SQLite serializada por lock de arquivo; verificada com seis processos concorrentes.
- Comparação de redes do cadastro inicial usa bytes e funciona também no PHP de 32 bits (arm/v7).
- Cadastro inicial revalida a ausência de usuários sob lock de escrita.
- Monitoramento HTTP preserva o protocolo em portas não padrão e aceita códigos configurados (padrão 200–399). TCP e NTP são métodos explícitos.
- Cache compartilhado com o endpoint antigo e reserva por serviço evitam checagens duplicadas. Navegador limita a concorrência a dois lotes e distingue falha de consulta de serviço offline.
- Importação usa parser YAML real, prévia com contagens/erros, confirmação, expiração de dez minutos e detecção de dados alterados. Backup inclui monitoramento e preserva contas.
- Salvamento da ordem ganhou confirmação e restauração visual em caso de falha.
- Completados textos dos formulários e fluxos novos em português, inglês e espanhol; labels associados aos campos e suporte a movimento reduzido.
- Busca externa passou a exigir clique explícito no link identificado para DuckDuckGo.
- Requisitos documentados: PHP 8.1+, pdo_sqlite, mbstring e curl; yaml para Homepage. Docker inclui todas essas extensões e permite ícones somente leitura.
- Base Docker atualizada para PHP 8.4; o código mantém compatibilidade com PHP 8.1+.
- YAML 2.3.0 compilado a partir do pacote oficial com SHA-256 fixo, evitando falha do PECL no PHP 8.3/riscv64.
- RISC-V executa os testes PHP diretos e integração HTTP externa com Nginx/PHP-FPM; testes CLI que criam subprocessos ficam nas outras arquiteturas devido ao timeout observado sob QEMU.
- Verificações de runtime por arquitetura têm etapas e limites de tempo próprios; servidor HTTP da suíte é encerrado ao concluir as requisições.
- A tag latest é atualizada somente após os testes PHP da imagem candidata passarem nas quatro arquiteturas.
- CI valida pull requests, testes PHP/JavaScript e integração real com Nginx/PHP-FPM antes de publicar amd64, arm64, arm/v7 e riscv64 no GHCR.

### Atualização desta versão

Faça backup antes de atualizar e mantenha o volume do banco. As migrações são automáticas; será necessário entrar novamente. Revise serviços que respondem HTTP 401/403 e ajuste os códigos aceitos. Para servidores personalizados, replique os bloqueios de diretórios e arquivos auxiliares do banco. Imagens externas e pesquisas iniciadas pelo usuário podem acessar serviços externos.

## Histórico anterior

### Segurança

- Validação centralizada de URLs, cores, identificadores, usuários e senhas.
- Senhas novas devem ter entre 10 e 72 caracteres.
- Sessões passaram a usar o identificador estável do usuário.
- Adicionados CSP, HSTS em HTTPS, Permissions Policy, cookies reforçados e ocultação da versão do PHP.
- Proteção adicional contra serviços órfãos com foreign keys do SQLite.

### Banco de dados e backup

- Caminho padrão local alterado para `db_data/bd.db`.
- Adicionada a variável `PORTAL_DB_PATH`; no Docker ela aponta para `/var/www/db_data/bd.db`.
- Instalações antigas que ainda usam o banco no diretório pai continuam sendo detectadas automaticamente.
- Migrações agora são versionadas e habilitam WAL e `busy_timeout`.
- A restauração valida o arquivo antes de apagar dados e preserva ordem, tags e cores.

### Monitoramento e interface

- Checagens de disponibilidade são feitas em pequenos lotes e armazenadas em cache por 45 segundos.
- Ordenação ganhou botões acessíveis para teclado e dispositivos de toque.
- Administração e configuração receberam viewport responsivo.
- Traduções em português, inglês e espanhol foram completadas.
- O CSS usa versão baseada no arquivo, permitindo cache do navegador.

### Docker e CI

- Adicionado healthcheck à imagem.
- A coleção opcional de ícones não é mais incorporada ao contexto da imagem; use volume ou URLs.
- O pipeline valida build, migrações, regras de segurança, traduções e sintaxe antes da publicação.
- Imagens publicadas recebem também uma tag baseada no SHA do commit.

### Atualização

1. Faça backup do banco atual.
2. Atualize o código ou a imagem Docker.
3. Mantenha o volume `/var/www/db_data` no Docker.
4. Se usava ícones locais, mantenha o volume `/var/www/html/icons`.
5. Inicie o portal; as migrações são aplicadas automaticamente e preservam os dados existentes.
