# Instruções para Claude Code neste projeto

**Antes de assumir qualquer estado do projeto, leia `DOCUMENTATION.md` na
raiz.** Tem a arquitetura completa, schema do banco usado, variáveis de
ambiente, e o guia de replicação para outros clientes/provedores (§7).

## O que é este projeto

`sp_viewer.php` é o visor somente-leitura do banco do SupportPal (KB + tickets),
usado quando a licença do SupportPal de um cliente é cancelada/indisponível.
PHP puro, um arquivo, sem framework. Este repositório não é executado
localmente, serve como base de código/documentação para qualquer
replicação.

## Regras e preferências aprendidas

- **Backup antes de sobrescrever arquivos em qualquer servidor de produção:**
  `cp arquivo.php arquivo.php.bak.$(date +%Y%m%d%H%M%S)` antes de todo SCP/SSH
  que sobrescreva arquivo existente. Produção não tem controle de versão git.
- **Nunca commitar `.htaccess` real** (contém credenciais de banco e hash de
  senha de acesso). Está no `.gitignore`. Usar `.htaccess.example` como
  template sanitizado para qualquer replicação ou exemplo.
- **Ao replicar para outro cliente/provedor**, seguir o passo a passo da
  §7 de `DOCUMENTATION.md`. Em especial, confirmar a convenção `by`/`type`
  de `ticket_message` via `?diag=1` antes de confiar nos rótulos "cliente" vs
  "operador" e "resposta" vs "nota interna", pois isso pode variar entre
  instalações do SupportPal.
- **Usuário MySQL deveria ser somente `SELECT`.** A instância original em
  produção não segue essa própria recomendação (usuário com `ALL PRIVILEGES`),
  ver §6 de `DOCUMENTATION.md`. Ao criar/replicar uma nova instância, criar
  o usuário já como read-only.
- **Atualizar `DOCUMENTATION.md`** a cada mudança relevante de código,
  arquitetura ou processo de replicação.

## Acesso ao servidor de origem

Dados reais de acesso (host, usuário SSH, caminhos) de qualquer instalação
em produção **não ficam neste repositório**. São credenciais operacionais,
mantidas fora do controle de versão (anotação local/gestor de senhas de
quem administra aquela instância específica). Ver §1 e §7 de
`DOCUMENTATION.md` para o formato genérico esperado desses dados.
