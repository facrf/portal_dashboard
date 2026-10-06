 # Política de Segurança

O Portal Dashboard leva a sério a segurança do nosso software, incluindo todos os repositórios de código-fonte gerenciados por meio da nossa organização no GitHub.

Se você acredita ter encontrado uma vulnerabilidade de segurança no Portal Dashboard, por favor, reporte-a para nós conforme descrito abaixo.

 ## Relatando Problemas de Segurança

Por favor, inclua as informações solicitadas listadas abaixo (o máximo que puder fornecer) para nos ajudar a entender melhor a natureza e o escopo do possível problema:

1.  **Tipo de problema**
2.  **Instruções passo a passo para reproduzir o problema**
3. **Prova de conceito (PoC) ou código de exploit** (se possível)
4. **Impacto potencial do problema**, incluindo como um invasor poderia explorar a vulnerabilidade

Essas informações nos ajudarão a triar seu relatório com maior rapidez. Toda e qualquer CVE será solicitada e emitida por meio da funcionalidade de relatório privado de vulnerabilidades do GitHub, sendo publicada juntamente com a divulgação (*disclosure*) oficial.

## Proteções e limites de implantação

- O portal é público para quem alcança o servidor. Administração e mutações exigem sessão e CSRF.
- Contas autenticadas têm acesso administrativo completo; não há papéis de leitor/editor.
- Mudanças de senha revogam sessões. A duração é absoluta desde o login e verificada no servidor.
- Nginx/Apache fornecidos bloqueiam dados SQLite, auxiliares WAL/SHM/journal, testes, templates e módulos internos. Apache precisa de `.htaccess` habilitado. Em outros servidores, replique essas regras e mantenha o banco fora da raiz pública.
- As checagens aceitam apenas destinos registrados por administradores, incluindo endereços internos necessários ao homelab. O cache e as reservas reduzem repetições; não são um limitador global de tráfego. Restrinja exposição pública no proxy/firewall quando necessário.
- Proxies confiáveis devem ser IPs ou CIDRs restritos e definidos em `PORTAL_TRUSTED_PROXIES`.
- Importações são revisadas antes da aplicação, têm limites e usam transação. YAML não desserializa objetos PHP e rejeita âncoras, aliases e tags explícitas.
- A CSP ainda permite scripts e estilos inline necessários às páginas atuais. Campos dinâmicos são escapados conforme o contexto; uma futura migração completa para scripts externos pode eliminar `unsafe-inline`.
- Exporte o JSON para configurações e serviços; para recuperar contas e hashes, mantenha um backup consistente do SQLite por uma ferramenta que suporte WAL (por exemplo, a API de backup SQLite). Não copie apenas o `.db` durante escritas ativas.
