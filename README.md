# 🌐 Portal Dashboard

> Um dashboard leve, extremamente customizável e focado em privacidade para o seu Homelab / Homeserver.

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.0-777bb4.svg)](https://www.php.net/)
[![SQLite Version](https://img.shields.io/badge/SQLite-3-003b57.svg)](https://www.sqlite.org/)

O **Portal Dashboard** é uma alternativa minimalista e segura a ferramentas como Heimdall e Homepage. Ele foi projetado para quem deseja centralizar os acessos do seu servidor caseiro sem abrir mão do controle total sobre seus dados. 

---

## 🎯 Filosofia do Projeto

* **Zero Telemetria:** Sem rastreadores, sem pingbacks, sem análise de dados externa. O que acontece no seu servidor, fica no seu servidor.
* **100% Local:** Dependência zero de APIs ou nuvens de terceiros. 
* **Eficiência Máxima:** Construído puramente com **PHP** e **SQLite**, consumindo o mínimo possível de hardware (ideal para rodar em mini PCs, notebooks antigos ou Raspberry Pi).
* **Liberdade de Customização:** Configure e organize seus serviços de forma simples e direta.

---

## ✨ Funcionalidades

* 🚀 **Inicialização Instantânea:** Sem bancos de dados pesados ou processos de setup complexos.
* 📱 **Design Responsivo:** Acesse e gerencie seu homelab perfeitamente pelo computador, tablet ou celular.
* 💾 **Persistência Simples:** Configurações armazenadas localmente em um banco de dados SQLite de arquivo único.
* 🎨 **Altamente Customizável:** Crie categorias, adicione links de serviços e organize o layout de acordo com sua necessidade.

---

## 🛠️ Tecnologias Utilizadas

* **Backend:** PHP (8.0+)
* **Banco de Dados:** SQLite 3
* **Licença:** GNU GPL v3

---

## 🚀 Como Instalar

Você pode rodar o Portal Dashboard diretamente no seu servidor web de preferência ou via Docker.

### Método 1: Servidor Web Local (Apache / Nginx)

1. **Requisitos Prévios:**
   * Servidor Web (Apache, Nginx, etc.)
   * PHP 8.0 ou superior instalado.
   * Extensão `php-sqlite3` habilitada.

2. **Clonar o Repositório:**
   ```bash
   git clone [https://github.com/facrf/portal_dashboard.git](https://github.com/facrf/portal_dashboard.git)
   cd portal_dashboard

3. 
   Dicas: 
   Configurar Permissões:
Certifique-se de que o usuário do servidor web (ex: www-data) tenha permissão de leitura e escrita na pasta do projeto para manipular o banco de dados SQLite: sudo chown -R www-data:www-data /caminho/para/portal_dashboard


Acesse através do seu navegador: http://localhost/portal_dashboard (ou o IP do seu servidor).


### 🐳 Instalação com Docker Compose (Recomendado)

Como a imagem do **Portal Dashboard** é compilada automaticamente e hospedada no GitHub Container Registry (GHCR), você não precisa clonar este repositório para rodar o projeto no seu servidor.

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
      # Mapeie a pasta onde seu banco SQLite ficará salvo de forma persistente
      - /caminho/no/seu/servidor/database:/var/www/html/database
    restart: unless-stopped
    

⚙️ Customização
Toda a configuração é feita diretamente pela interface do painel de administração (ou manipulando diretamente o banco SQLite se você preferir a linha de comando).

Você pode:

Adicionar novos cards com ícones personalizados.

Agrupar serviços por categorias (ex: Mídia, Monitoramento, Rede).

Definir links internos (para uso local) e externos (via tunnels/reverso) para o mesmo serviço.

📄 Licença
Este projeto está sob a licença GNU GPL v3. Isso significa que você é livre para usar, modificar e distribuir o software, desde que mantenha as alterações sob a mesma licença de código aberto. Veja o arquivo LICENSE para mais detalhes.

🤝 Contribuições
Feedbacks, relatórios de bugs e Pull Requests são extremamente bem-vindos!

Faça um Fork do projeto.

Crie uma branch para sua modificação (git checkout -b feature/nova-funcionalidade).

Envie suas alterações (git commit -am 'Adiciona nova funcionalidade').

Faça o Push da branch (git push origin feature/nova-funcionalidade).

Abra um Pull Request.

Criado com ☕ por facrf.
