# Consulta SupportPal: Documentação

Visor somente-leitura do banco de dados de uma instância SupportPal, usado para
consulta interna (N1/N2/N3) de artigos da Base de Conhecimento e tickets **depois
que a licença do SupportPal é cancelada** (ou fica indisponível), sem depender da
aplicação original nem de licença ativa.

Aplicação única em PHP puro (876 linhas, um arquivo), sem framework, sem
dependências via Composer. Lê diretamente as tabelas do MySQL/MariaDB do
SupportPal via PDO.

## 1. Origem e instância de referência

| Item | Valor |
|---|---|
| Painel | cPanel/DirectAdmin (hosting compartilhado comum) |
| SSH | porta customizada (confirme com seu provedor, varia por conta) |
| Diretório do app | `/home/SEU_USUARIO/domains/SEU_DOMINIO/public_html` (ou equivalente cPanel) |
| URL pública | domínio próprio do cliente, ex.: `consulta-sp.exemplo.com.br` |
| PHP | 8.1+ (testado em 8.3.x via mod_lsapi/CGI) |
| Banco | MariaDB 10.6+, schema do SupportPal **5.7.5** (215 tabelas, schema completo) |
| Storage de anexos | diretório `storage/app` da instalação SupportPal original, pode estar no mesmo domínio, num domínio irmão da mesma conta, ou em conta cPanel separada |

**Importante para replicação:** o app não é standalone, ele depende de dois
recursos que precisam existir juntos (mesmo servidor ou acessíveis pela rede):
1. Um MySQL/MariaDB com o schema do SupportPal (pode ser cópia read-only/dump).
2. O diretório de storage físico do SupportPal (`storage/app`), para servir anexos.

Se qualquer um dos dois for descontinuado (ex.: apagar a instalação original do
SupportPal), a consulta de anexos para de funcionar mesmo que o banco continue
disponível. É preciso preservar/copiar a pasta `storage/app` também.

## 2. Arquivos

```
consulta-supportpal/
├── sp_viewer.php               # aplicação
├── .htaccess.example           # template sanitizado do .htaccess real (SetEnv)
├── sp_local_config.php.example # template sanitizado da config alternativa fora do webroot
├── .htaccess                   # config real de produção. NÃO versionado (.gitignore)
├── LICENSE
├── README.md / README.pt-br.md
└── DOCUMENTATION.md            # este arquivo
```

Os dois arquivos reais preenchidos (`.htaccess` e `sp_local_config.php`, se
usado) nunca devem ser commitados, ambos estão no `.gitignore`. Se a
instalação de origem não tiver git (comum em hosting compartilhado com
versionamento manual por sufixo de arquivo), vale adotar este repositório
como fonte de verdade dali em diante (ver §7).

## 3. Arquitetura da aplicação (`sp_viewer.php`)

Um único arquivo, roteado por query string, sem front controller/framework:

| Rota | Função | Descrição |
|---|---|---|
| `/` (sem params) | `render_search()` | Busca/listagem: abas KB e Tickets |
| `?tab=kb` | `list_articles()` | Lista artigos (busca por título/texto, filtro público/interno) |
| `?tab=tickets` | `list_tickets()` | Lista tickets (busca por nº/assunto/solicitante/e-mail, filtros de tag/depto/status/data, busca profunda no corpo das mensagens) |
| `?view=article&id=N` | `render_article()` | Detalhe de um artigo da KB |
| `?view=ticket&id=N` | `render_ticket()` | Detalhe de um ticket com thread de mensagens/notas |
| `?media=HASH` | `serve_media()` | Serve um anexo do disco (streaming, com validação de hash e path traversal) |
| `?tags=termo` | `suggest_tags()` | Autocomplete de tags (JSON) |
| `?logout=1` | (nenhuma função) | Encerra a sessão |
| `?diag=1` (junto com `?view=ticket`) | `render_diag()` | Diagnóstico do mapeamento `by`/`type` de `ticket_message` (ver §5) |

### Autenticação
- Senha única compartilhada (não há usuários individuais). Hash bcrypt em
  `SP_APP_HASH`, verificado com `password_verify()`.
- Sessão PHP nativa (`session_start()`), sem "lembrar-me", com cookie
  `Secure`/`HttpOnly`/`SameSite` e timeout de inatividade (ver §6.1).
- Delay de 600ms em senha errada (mitigação simples de força bruta,
  limitação conhecida, ver §6.1).
- Camada extra de rede: `<RequireAny>` no `.htaccess` (allowlist de IP/CIDR do
  Apache), essa é a trava principal. Existe também um allowlist opcional
  `SP_ALLOW_IPS` verificado em PHP (`$_SERVER['REMOTE_ADDR']`), redundante com
  o `.htaccess`.

### Renderização de conteúdo de terceiros
Artigos e mensagens de ticket guardam HTML (`purified_text`/`text`) vindo do
editor rico do SupportPal. Para exibir com fidelidade **sem risco de XSS**,
`render_html_frame()` injeta esse HTML dentro de um `<iframe sandbox="allow-same-origin">`,
sem `allow-scripts`, então qualquer `<script>` embutido no conteúdo antigo
não executa. A altura do iframe é ajustada via JS (`onload`) medindo o
`scrollHeight` do documento interno.

### Anexos / mídia
- URLs antigas do tipo `https://dominio-supportpal/download/{HASH}?...` dentro
  do HTML salvo são reescritas em tempo real para `?media={HASH}` (função
  `rewrite_media_urls()`), apontando para o endpoint local `serve_media()`.
- `serve_media()` busca `filename/folder/mime/size` na tabela `upload` pelo
  hash, testa 3 caminhos candidatos dentro de `SP_STORAGE` (`{storage}/{folder}/{hash}`,
  com extensão, e raiz), valida que o `realpath()` resolvido continua **dentro**
  de `SP_STORAGE` (proteção contra path traversal), aplica uma **allowlist de
  mime type** antes de decidir `inline` vs `attachment` (ver §6.1) e faz
  `readfile()`.

## 4. Configuração

`env_val()` procura cada chave em três lugares, nesta ordem de prioridade:
1. `sp_local_config.php` (arquivo fora do webroot, ver abaixo), se existir.
2. `$_SERVER` (preenchido por `SetEnv` no `.htaccess`).
3. `getenv()` (fallback; alguns handlers PHP só preenchem um dos dois).

| Variável | Obrigatória | Descrição |
|---|---|---|
| `SP_DB_HOST` | não (default `127.0.0.1`) | Host do MySQL/MariaDB |
| `SP_DB_NAME` | não | Nome do schema do SupportPal |
| `SP_DB_USER` | **sim** | Usuário MySQL, de preferência read-only (ver §6) |
| `SP_DB_PASS` | **sim** | Senha do usuário MySQL |
| `SP_APP_HASH` | **sim** | Hash bcrypt da senha de acesso à interface (`password_hash()`) |
| `SP_STORAGE` | não, mas sem ela mídia não funciona | Caminho físico absoluto da raiz `storage/app` do SupportPal original |
| `SP_ALLOW_IPS` | não | CSV de IPs, allowlist redundante em PHP |

Se `SP_APP_HASH` ou credenciais de banco faltarem, o app mostra uma tela de
"CONFIGURAÇÃO INCOMPLETA" com instruções (`fail_setup()`) em vez de quebrar
silenciosamente. Bom padrão a manter em replicações.

### Opção 1 (recomendada): `sp_local_config.php` fora do webroot

Copie `sp_local_config.php.example` para `sp_local_config.php`, coloque um
diretório **acima** do `public_html` (irmão dele, não dentro) e preencha os
valores reais. Vantagens sobre `SetEnv`:
- Funciona independente de o host ter `mod_env` disponível ou não (alguns
  perfis enxutos de EasyApache4 não instalam esse módulo por padrão).
- Por ficar fora da árvore servida pelo Apache, não existe configuração
  errada do servidor capaz de expor esse arquivo via HTTP; com `SetEnv` no
  `.htaccess`, a proteção depende inteiramente do bloqueio de dotfile do
  host estar correto (ver §6.1, achado real onde isso falhava para nomes
  com sufixo, tipo `.htaccess.bak.*`).

### Opção 2: `SetEnv` no `.htaccess`

Mais simples se o host já tem `mod_env` funcionando e você prefere manter
tudo dentro de um único arquivo. Ver `.htaccess.example`.

`env_val()` lê tanto de `$_SERVER` quanto de `getenv()` porque, dependendo do
handler PHP (mod_php vs PHP-FPM/mod_lsapi, comum em cPanel/DirectAdmin),
`SetEnv` do Apache só chega em uma das duas formas.

## 5. Schema do SupportPal usado pela aplicação

O SupportPal tem 215 tabelas; a aplicação só toca nestas:

**Base de conhecimento:**
- `article` (id, title, excerpt, plain_text, text, purified_text, published, protected, created_at, updated_at)
- `article_type`, `article_type_membership` (tipo do artigo; `internal` define se é KB pública ou interna)
- `article_category`, `article_cat_membership`
- `article_attachment` (article_id, upload_hash, original_name)

**Tickets:**
- `ticket` (id, number, subject, department_id, status_id, priority_id, user_id, internal, deleted_at, created_at, updated_at)
- `ticket_message` (id, ticket_id, user_id, user_name, `by`, `type`, text, purified_text, is_draft, created_at)
- `ticket_status`, `ticket_priority`, `department`
- `ticket_tag`, `ticket_tag_membership`
- `ticket_attachment` (ticket_id, message_id, upload_hash, original_name)
- `user`, `user_email_address`

**Compartilhada:**
- `upload` (id, hash, filename, folder, mime, size), índice único em `hash`

### Convenção `ticket_message.by` / `ticket_message.type`
```php
const MSG_TYPE_NOTE   = 1;  // type: 0 = resposta | 1 = nota interna
const MSG_BY_OPERATOR = 1;  // by:   0 = cliente   | 1 = operador
```
Isso é a convenção padrão do SupportPal 5.x, mas **não é garantida** entre
instalações/versões. O próprio código inclui `?diag=1` (visível na página de
um ticket) para conferir a distribuição real de `by`/`type` no banco antes de
confiar nos rótulos. **Ao replicar para outro cliente, rodar esse diagnóstico
primeiro** e ajustar as constantes se necessário.

### Tags curadas (`CURATED_TAGS`)
Um mapa fixo no topo do arquivo agrupa grafias duplicadas de tags reais do
banco (ex.: `setup` e `#setup` → rótulo único "Setup") para um seletor fixo na
busca de tickets. É específico dos dados de cada instância (bases grandes
tendem a acumular bastante sujeira histórica de tags). **Ao replicar para
outro cliente, esse mapa deve ser levantado de novo** a partir do
`ticket_tag` real do cliente (o autocomplete via `?tags=` continua
funcionando para tags fora da lista curada, então não é bloqueante, só
cosmético).

## 6. Segurança

**Bem feito:**
- PDO com prepared statements em 100% das queries com input do usuário (sem SQL cru concatenado).
- Validação de hash alfanumérico + checagem de `realpath()` dentro do storage antes de servir arquivo (`serve_media`), evita path traversal.
- HTML de terceiros isolado em iframe sandboxed sem `allow-scripts`, evita XSS armazenado do conteúdo antigo do SupportPal.
- Erros de configuração/conexão não vazam detalhes sensíveis ao usuário final.
- `declare(strict_types=1)`.

**⚠️ Divergência comum encontrada em instâncias reais:** o comentário no topo
do arquivo recomenda "usuário MySQL com permissão SELECT apenas", mas é comum
encontrar, em instalações herdadas, um usuário de produção com
**`GRANT ALL PRIVILEGES`** no schema em vez de apenas `SELECT` (confirme com
`SHOW GRANTS`). Como o app só faz leitura, isso é um privilégio
desnecessário. Se houver alguma falha futura (SQL injection introduzida em
manutenção, credencial vazada, etc.), o raio de dano é maior do que precisa
ser.

**Recomendação para toda instalação/replicação:** criar um usuário MySQL
dedicado, somente `SELECT` nas tabelas usadas (§5), e apontar
`SP_DB_USER`/`SP_DB_PASS` para ele:
```sql
CREATE USER 'sp_readonly'@'localhost' IDENTIFIED BY 'SENHA_FORTE_AQUI';
GRANT SELECT ON nome_do_schema.* TO 'sp_readonly'@'localhost';
FLUSH PRIVILEGES;
```

### 6.1 Auditoria de segurança: achados e correções aplicadas

Auditoria completa de código + verificação ao vivo contra uma instância real
em produção (durante uma replicação para outro cliente). Achado mais grave
**confirmado explorável com dados reais** (não teórico): a instância auditada
já tinha anexos armazenados com `mime = 'text/html'` na tabela `upload`, um
deles com `<script>` de verdade. `serve_media()` servia qualquer mime da
tabela com `Content-Disposition: inline`, sem allowlist, executando o
conteúdo no domínio da aplicação (fora do `<iframe sandbox>` que só protege
o corpo de tickets/artigos, não anexos).

**Corrigido:**
- 🔴 **Crítico.** `serve_media()`: allowlist `SAFE_INLINE_MIMES` (imagens,
  PDF, texto/CSV); qualquer mime fora da lista vira `Content-Disposition:
  attachment` (força download) + `Content-Security-Policy: sandbox` extra.
- 🟠 **Alto.** Cookie de sessão sem `Secure`/`HttpOnly`/`SameSite` (confirmado
  ao vivo via `Set-Cookie` na resposta HTTP): agora `session_set_cookie_params()`
  antes de `session_start()`, com `session.use_strict_mode` ligado.
- 🟡 **Médio.** Checagem de containment em `serve_media()` usava
  `str_starts_with()` sem barra final (prefixo de string, não de diretório);
  corrigido comparando contra `realpath($base) . '/'`.
- 🟡 **Médio.** Headers de segurança ausentes (`X-Frame-Options`, CSP, HSTS,
  `Referrer-Policy`) e dotfiles (`.htaccess.bak.*`) serváveis via web em pelo
  menos um cPanel testado (a regra padrão do host bloqueava só o nome exato
  `.htaccess`, não o prefixo). Adicionado bloco `<FilesMatch "^\.">` +
  `mod_headers` no `.htaccess.example`.
- 🟢 **Baixo.** Timeout de sessão por inatividade (30 min) e
  `set_time_limit(20)` no roteador (a busca profunda faz `LIKE` em
  `ticket_message.text`, sem índice, e o `max_execution_time` do host
  estava ilimitado).

**Não corrigido (decisão consciente):** rate limiting de login continua um
simples `usleep()` por requisição (paralelizável, mitigado parcialmente pela
allowlist de IP); `disable_functions`/`open_basedir` dependem de configuração
do host, não do código; senha única compartilhada sem trilha de auditoria
por usuário é uma escolha de arquitetura deliberada da ferramenta.

**Bug funcional encontrado durante a validação da correção acima (não é de
segurança, mas quebrava a listagem toda vez):** em `list_tickets()`, a
variável local da prepared statement reaproveitava o nome `$st`, o mesmo
do **parâmetro** `$st` (filtro de status). Depois de `$st = $db->prepare(...)`,
qualquer uso posterior de `$st` (no link do paginador, `urlencode($st)`)
pegava o objeto `PDOStatement` em vez do filtro de status, disparando
`TypeError` e truncando a página (tabela aparecia, mas sem paginador nem
fechamento do HTML) **em toda listagem de tickets**, não só ao filtrar por
status. Corrigido renomeando a variável local para `$stmt`.

### 6.2 Aplicação na instância original (Sierti, 2026-07-11)

Os commits `535d209`/`5a4cd72` (§6.1) foram desenvolvidos e validados durante
uma replicação para outro cliente. Em seguida, o mesmo fix foi conferido e
aplicado também na instância de produção original (a que deu origem a este
repositório, servidor com painel DirectAdmin, ver histórico local para
detalhes de acesso, não listados aqui por não pertencerem ao código):

- **Código em produção antes do fix:** MD5 idêntico ao commit `555d475`
  (import original), ou seja, nenhuma das correções de segurança tinha sido
  aplicada ali; permanecia vulnerável ao mesmo achado crítico do §6.1.
- **Checagem real do banco** (`SELECT mime, COUNT(*) FROM upload WHERE mime
  LIKE 'text/html%' OR mime LIKE '%svg%' OR mime LIKE '%xml%' GROUP BY
  mime`): exposição bem menor que a instância que originou a auditoria,
  nenhum `text/html` nem `image/svg+xml`; só 1 anexo `text/xml` real (uma
  NFS-e) e 4 planilhas `.xlsx` (formato Office real, falso-positivo do filtro
  `%xml%`, risco desprezível). Mesmo assim, o `text/xml` já bastava para
  ativar o mesmo vetor sem a allowlist de mime.
- **Backup antes do deploy:** `sp_viewer.php` e `.htaccess` anteriores
  copiados para fora do webroot antes da sobrescrita.
- **Limpeza adicional:** o diretório de produção também tinha o histórico de
  versões manuais (`sp_viewer.php-v1`…`v9`, `sp_viewer.OK_*.php`) soltas
  dentro do `public_html`, nenhuma tinha a correção, e por não começarem
  com `.` não eram cobertas nem pela regra padrão de dotfile do host nem
  pelo `<FilesMatch "^\.">` novo. Movidas para fora do webroot junto com os
  backups.
- **Validação:** rodada via PHP CLI simulando sessão autenticada (não havia
  como testar via HTTP normalmente sem estar dentro da allowlist de IP nem
  saber a senha de acesso à interface). Confirmado com dados reais: busca de
  tickets e KB, detalhe de ticket e artigo, e download do anexo `text/xml`
  real (lido corretamente do disco). `php -l` sem erros; nenhum erro fatal
  nos testes.

### 6.3 Migração para `sp_local_config.php` (Sierti, 2026-07-11)

Depois do fix de segurança (§6.2), a instância original migrou de `SetEnv`
no `.htaccess` para o mecanismo `sp_local_config.php` fora do webroot
(commit `970fc45`, ver §4), eliminando a dependência de o bloqueio de
dotfile do host estar correto para proteger as credenciais.

- **Backup** de `.htaccess` e `sp_viewer.php` anteriores, fora do webroot,
  antes de qualquer sobrescrita.
- **`sp_local_config.php`** criado como irmão do `public_html` (não dentro
  dele), com os valores lidos direto do `.htaccess` de produção no momento
  da migração, inclusive um IP que tinha sido adicionado à allowlist desde
  o deploy anterior (lido fresco, não reaproveitado de cópia antiga).
  Permissão `600`.
- **`.htaccess`** perdeu as linhas `SetEnv` de credenciais; manteve
  `DirectoryIndex`, `<RequireAny>` (allowlist de IP intacta), `<FilesMatch>`
  e os headers de segurança.
- **Validação via PHP CLI**, desta vez **sem exportar nenhuma variável de
  ambiente** (só o `sp_local_config.php` real disponível), confirmando que a
  config vem exclusivamente do arquivo novo: busca de tickets/KB, detalhe de
  ticket/artigo e download do anexo `text/xml` real, todos OK. **Teste
  negativo**: renomeado o `sp_local_config.php` temporariamente (sem nenhuma
  outra fonte de config disponível) → app mostrou corretamente a tela de
  "CONFIGURAÇÃO INCOMPLETA" em vez de falhar silenciosamente ou usar config
  antiga; arquivo restaurado na sequência.
- **Achado à parte (fora do escopo desta migração):** o domínio está atrás
  de **Cloudflare Access** (redireciona para uma tela de login própria do
  Cloudflare antes de chegar no Apache), camada de autenticação adicional
  que não faz parte deste app nem foi alterada aqui, descoberta ao tentar
  validar via HTTP externo.

## 7. Replicando para outro provedor/cliente

Passo a passo genérico:

1. **Levantar os dados do cliente:**
   - Host/nome do MySQL/MariaDB do SupportPal do cliente e credenciais de leitura (criar usuário read-only, ver §6).
   - Caminho físico do `storage/app` da instalação SupportPal do cliente (confirmar via SSH; pode não estar no mesmo domínio/conta).
   - Faixas de IP que devem ter acesso (VPN, escritório, IPs fixos) para o `<RequireAny>`.
2. **Copiar `sp_viewer.php`** (arquivo único, sem dependências externas) para o `public_html` (ou subpasta) do domínio novo.
3. **Configurar credenciais** (ver §4 para o comparativo das duas opções):
   - **Recomendado:** copiar `sp_local_config.php.example` → `sp_local_config.php`, colocar um diretório acima do `public_html` (fora do webroot) e preencher `SP_DB_*`, `SP_APP_HASH` (gerar com `php -r 'echo password_hash("SENHA_ESCOLHIDA", PASSWORD_DEFAULT);'`) e `SP_STORAGE` com o caminho físico real do `storage/app` do cliente.
   - **Ou**, copiar `.htaccess.example` → `.htaccess` no destino e preencher as mesmas variáveis via `SetEnv`.
   - Em qualquer uma das opções, preencher as faixas de IP corretas em `<RequireAny>` no `.htaccess`.
   - **Fazer backup do `.htaccess` existente no destino antes de sobrescrever**, se houver.
4. **Conferir a versão do SupportPal do cliente.** Este código foi validado
   contra 5.7.5. Se a versão for muito diferente, checar principalmente:
   - se os nomes de tabela/coluna usados em §5 ainda existem;
   - a convenção `by`/`type` de `ticket_message` via `?diag=1` (§5);
   - o layout de pastas dentro de `storage/app` (a função `serve_media()` já
     tenta 3 candidatos, mas vale conferir manualmente um hash real).
5. **Revisar `CURATED_TAGS`** com as tags reais do novo cliente (opcional, cosmético).
6. **Testar:** login, busca KB, busca de tickets (incluindo `deep search`), abrir um artigo e um ticket com anexo, baixar um anexo, alternar tema, logout.
7. **Confirmar acesso de rede:** testar de dentro e de fora das faixas de IP liberadas para validar o `<RequireAny>`.
8. **Verificar mime types já armazenados nos anexos** (ver §6.1) antes de considerar o deploy concluído:
   ```sql
   SELECT mime, COUNT(*) FROM upload
   WHERE mime LIKE 'text/html%' OR mime LIKE '%svg%' OR mime LIKE '%xml%'
   GROUP BY mime;
   ```
   Se houver algum resultado, confirme que a versão do `serve_media()` em uso
   já tem a allowlist de mime (§6.1) antes de liberar acesso.

## 8. Ambiente local / versionamento

Este repositório existe para documentar a arquitetura e servir de base a
replicações futuras. Não é servido/executado localmente.

- O `.htaccess` real de cada instalação (com credenciais de produção) nunca
  deve ser commitado, está no `.gitignore`.
- `.htaccess.example` é a versão sanitizada, versionada, usada como ponto de
  partida em replicações (§7).
- Se a instalação de origem não tiver git (comum em hosting compartilhado
  com versionamento manual por sufixo de arquivo tipo `-v1`, `-v2`...), vale
  adotar este repositório como fonte de verdade dali em diante e fazer
  deploys a partir dele.

## 9. Pendências / possíveis melhorias (não aplicadas, apenas observadas)

- Trocar o usuário MySQL de produção para um `SELECT`-only real, em qualquer instância que ainda não tenha isso (§6).
- Adotar git no diretório de produção do servidor, ou ao menos fazer deploys a partir deste repositório local versionado.
- Remover páginas padrão do painel de hosting (`index.html` etc.) que não são usadas pelo app e podem confundir quem administra o hosting.
