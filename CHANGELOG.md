# Changelog

Todas as mudanças relevantes do Portal Dashboard são registradas neste arquivo.

## Não publicado

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
