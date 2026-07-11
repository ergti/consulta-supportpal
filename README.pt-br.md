# Consulta SupportPal

*[Read in English](README.md)*

Um visor somente-leitura, em arquivo único, do banco de dados de uma
instância **SupportPal**. Feito para manter a equipe de suporte interna
(N1/N2/N3) consultando artigos da Base de Conhecimento e tickets **depois que
a licença do SupportPal foi cancelada ou a instância saiu do ar**, sem
depender da aplicação original nem de licença ativa.

## Por que isso existe

O SupportPal (software de helpdesk) exige licença ativa para rodar. Quando a
licença vence, anos de histórico de tickets e artigos de KB ficam
inacessíveis pela interface normal, mesmo os dados continuando lá no MySQL.
Essa ferramenta lê esses dados direto do banco, para que a equipe de suporte
não perca acesso ao próprio histórico.

## O que faz

- Busca e navegação em artigos da Base de Conhecimento (público/interno)
- Busca e navegação em tickets: por número, assunto, nome/e-mail do
  solicitante, tags, departamento, status, período, e opcionalmente busca
  profunda no corpo das mensagens
- Visualização da thread completa de um ticket (respostas + notas internas) e
  do conteúdo de artigos, renderizados com fidelidade ao original
- Serve os anexos originais (imagens, documentos) direto do disco
- Login por senha única compartilhada (bcrypt), allowlist de IP via `.htaccess`

## O que deliberadamente não faz

Sem framework, sem dependências via Composer, sem acesso de escrita ao banco
do SupportPal. São ~900 linhas de PHP puro em um único arquivo, somente
leitura do início ao fim.

## Requisitos

- PHP 8.1+ com PDO/MySQL
- Acesso de leitura ao schema MySQL/MariaDB do SupportPal (é fortemente
  recomendado um usuário dedicado só `SELECT`, ver
  [DOCUMENTATION.md §6](DOCUMENTATION.md))
- Acesso de leitura ao diretório `storage/app` do SupportPal (para anexos)
- Apache com suporte a `.htaccess` (`mod_env`, `mod_headers`, `mod_authz_core`)

## Início rápido

1. Copie `sp_viewer.php` para a raiz web.
2. Copie `.htaccess.example` para `.htaccess` no mesmo diretório e preencha a
   allowlist de IP (o hash da senha e as credenciais do MySQL também podem
   ir aqui, mas veja a opção recomendada abaixo).
3. **Recomendado:** copie `sp_local_config.php.example` para
   `sp_local_config.php`, coloque um diretório *acima* da sua raiz web (irmão
   dela, não dentro) e preencha as credenciais do MySQL, o caminho do storage
   e o hash da senha de acesso (gere com `php -r 'echo
   password_hash("sua-senha", PASSWORD_DEFAULT);'`). Esse arquivo tem
   prioridade sobre o `.htaccess` e funciona independente de o servidor ter
   `mod_env` disponível; por ficar fora da árvore servida, não existe
   configuração errada do servidor capaz de expô-lo via HTTP.
4. Abra a URL. Se faltar algo obrigatório, o app mostra uma tela de
   configuração em vez de quebrar silenciosamente.

Para o guia completo de replicação (checklist para novo cliente/provedor),
mapeamento do schema do banco e notas de arquitetura, veja
**[DOCUMENTATION.md](DOCUMENTATION.md)**.

## Segurança

Este app passou por uma auditoria completa de segurança (código +
verificação ao vivo no ambiente real) em 2026-07-11. Os achados e correções
estão documentados em [DOCUMENTATION.md §6](DOCUMENTATION.md), com destaque
para uma correção crítica de XSS armazenado no endpoint que serve anexos
(allowlist de mime type), além de hardening de cookie de sessão e headers de
segurança. Leia essa seção antes de implantar em um ambiente novo.

Dados reais de infraestrutura (hostname, contas de servidor, caminhos
internos) de qualquer implantação em produção são deliberadamente mantidos
fora deste repositório. Veja [DOCUMENTATION.md](DOCUMENTATION.md) para o
setup genérico documentado aqui.

## Licença

[MIT](LICENSE).
