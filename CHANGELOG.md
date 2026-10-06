# Changelog

Todas as mudanças relevantes do Portal Dashboard são registradas neste arquivo.

## 2026-10-06 — Sessões, monitoramento e importação

- Bloqueado acesso HTTP ao diretório do banco, arquivos auxiliares SQLite e módulos internos.
- Separadas conexão/migrações, autenticação, idiomas, monitoramento e importação em módulos próprios; extraídos templates e JavaScript comuns.
- Migração 3 acrescenta versão de sessão, configuração de monitoramento e reservas de cache sem descartar dados.
- Trocar senha revoga todas as sessões do usuário; expiração absoluta passa a ser conferida no servidor. Sessões antigas exigem novo login.
- Inicialização/migração SQLite serializada por lock de arquivo; verificada com seis processos concorrentes.
- Cadastro inicial revalida a ausência de usuários sob lock de escrita.
- Monitoramento HTTP preserva o protocolo em portas não padrão e aceita códigos configurados (padrão 200–399). TCP e NTP são métodos explícitos.
- Cache compartilhado com o endpoint antigo e reserva por serviço evitam checagens duplicadas. Navegador limita a concorrência a dois lotes e distingue falha de consulta de serviço offline.
- Importação usa parser YAML real, prévia com contagens/erros, confirmação, expiração de dez minutos e detecção de dados alterados. Backup inclui monitoramento e preserva contas.
- Salvamento da ordem ganhou confirmação e restauração visual em caso de falha.
- Completados textos dos formulários e fluxos novos em português, inglês e espanhol; labels associados aos campos e suporte a movimento reduzido.
- Busca externa passou a exigir clique explícito no link identificado para DuckDuckGo.
- Requisitos documentados: PHP 8.1+, pdo_sqlite, mbstring e curl; yaml para Homepage. Docker inclui todas essas extensões e permite ícones somente leitura.
- CI valida pull requests, testes PHP/JavaScript e integração real com Nginx/PHP-FPM antes de publicar amd64, arm64 e arm/v7 no GHCR.

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
