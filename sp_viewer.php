<?php
/**
 * sp_viewer.php: Consulta interna ao banco do SupportPal 5.7.5 (somente leitura)
 * -----------------------------------------------------------------------------
 * Uso interno (N1/N2/N3) antes do cancelamento da licença.
 * Lê direto do MySQL, sem depender da aplicação ou da licença ativa.
 *
 * Funções:
 *   - Buscar/exibir artigos do KB (público e interno)
 *   - Buscar/exibir tickets com respostas e notas internas
 *   - Renderizar o HTML original com fidelidade (iframe isolado)
 *   - Servir anexos (imagens, arquivos) a partir da tabela `upload`
 *
 * Segurança:
 *   - Login por senha compartilhada (hash em variável de ambiente)
 *   - Allowlist de IP opcional
 *   - PDO + prepared statements em tudo; nada de query crua sem bind
 *   - HTML do SupportPal renderizado em <iframe sandbox> (scripts neutralizados)
 *   - Recomendado: usuário MySQL com permissão SELECT apenas (ver README no fim)
 *
 * Credenciais NUNCA ficam no código. Defina por ambiente (ver bloco CONFIG).
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// Cookie de sessão hardenizado: HttpOnly (mitiga roubo de sessão via XSS),
// Secure (nunca trafega em HTTP puro) e SameSite (defesa extra contra CSRF).
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Timeout de sessão por inatividade (30 min): mitiga sessão esquecida aberta
// em navegador compartilhado, já que a senha de acesso é única e compartilhada.
const SESSION_IDLE_TIMEOUT = 1800;
if (!empty($_SESSION['auth']) && (time() - ($_SESSION['sp_last_seen'] ?? 0) > SESSION_IDLE_TIMEOUT)) {
    $_SESSION = [];
    session_destroy();
}
$_SESSION['sp_last_seen'] = time();

date_default_timezone_set('America/Sao_Paulo');

/* =====================================================================
 * CONFIG: tudo via ambiente. Em Apache/DA, defina via SetEnv no
 * .htaccess do diretório, ou exporte no PHP-FPM pool. Fallbacks abaixo
 * são apenas para desenvolvimento; em produção use o ambiente.
 *
 * Importante (DirectAdmin/PHP-FPM/mod_lsapi): variáveis de SetEnv às
 * vezes chegam só via $_SERVER e não via getenv(). env_val() lê dos dois.
 *
 * Alternativa recomendada: um arquivo de config PHP fora do webroot
 * (`sp_local_config.php`, irmão do diretório onde este arquivo está, não
 * dentro de public_html). Tem prioridade sobre $_SERVER/getenv() e não
 * depende de mod_env nem de nenhuma regra de bloqueio de dotfile do
 * servidor: por estar fora da árvore servida pelo Apache, não há como o
 * navegador acessá-lo, seja qual for a configuração do host. Deve devolver
 * um array associativo com as mesmas chaves do SetEnv, ex.:
 *   <?php return ['SP_DB_HOST' => '127.0.0.1', 'SP_DB_USER' => '...', ...];
 * ===================================================================== */
$SP_LOCAL_CONFIG = [];
$sp_local_config_file = dirname(__DIR__) . '/sp_local_config.php';
if (is_file($sp_local_config_file)) {
    $SP_LOCAL_CONFIG = require $sp_local_config_file;
}

function env_val(string $k, string $default = ''): string {
    global $SP_LOCAL_CONFIG;
    if (isset($SP_LOCAL_CONFIG[$k]) && $SP_LOCAL_CONFIG[$k] !== '') return (string)$SP_LOCAL_CONFIG[$k];
    if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') return (string)$_SERVER[$k];
    $v = getenv($k);
    return ($v !== false && $v !== '') ? (string)$v : $default;
}

$CFG = [
    'db_host'   => env_val('SP_DB_HOST', '127.0.0.1'),
    'db_name'   => env_val('SP_DB_NAME', 'supportpal'),
    'db_user'   => env_val('SP_DB_USER'),                // de preferência um usuário read-only
    'db_pass'   => env_val('SP_DB_PASS'),
    // Hash da senha de acesso à interface. Gere com:
    //   php -r 'echo password_hash("SUA_SENHA", PASSWORD_DEFAULT), "\n";'
    'app_hash'  => env_val('SP_APP_HASH'),
    // Caminho FÍSICO da raiz de uploads (contém as subpastas de `upload.folder`: public, tickets, email_log).
    // Ex.: /home/SEU_USUARIO/domains/SEU_DOMINIO/public_html/storage/app
    'storage'   => env_val('SP_STORAGE'),
    // Allowlist de IP por aplicação (CSV). Opcional; a trava principal é o .htaccess (RequireAny).
    'allow_ips' => env_val('SP_ALLOW_IPS'),
];

/* ---------------------------------------------------------------------
 * MAPEAMENTO ticket_message: confirme com ?diag=1 e ajuste se preciso.
 * Convenção padrão do SupportPal 5.x:
 *   type: 0 = resposta | 1 = nota interna
 *   by:   0 = cliente   | 1 = operador
 * ------------------------------------------------------------------- */
const MSG_TYPE_NOTE   = 1;  // valor de `type` que representa NOTA INTERNA
const MSG_BY_OPERATOR = 1;  // valor de `by` que representa OPERADOR

const PER_PAGE = 25;

/* Versão exibida na tela de login (link pro changelog no GitHub). Atualizar
 * a cada mudança relevante, seguindo semver (semver.org) e o CHANGELOG.md. */
const APP_VERSION = '1.0.0';
const APP_REPO_URL = 'https://github.com/ergti/consulta-supportpal';

/* ---------------------------------------------------------------------
 * Mime types seguros para exibir inline no navegador (serve_media). Fora
 * desta lista (ex.: text/html, image/svg+xml) o arquivo vira download
 * forçado (Content-Disposition: attachment) em vez de renderizar no
 * domínio da app. Anexos vêm de uma instância SupportPal historicamente
 * exposta à internet e podem conter HTML/SVG com <script> de terceiros.
 * IMPORTANTE: precisa ficar definida ANTES do roteador (que já pode
 * chamar serve_media() logo no início do script). Const de topo de
 * arquivo em PHP não é hoisted como function, só existe a partir da
 * linha onde a execução realmente passa por ela.
 * ------------------------------------------------------------------- */
const SAFE_INLINE_MIMES = [
    'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp',
    'application/pdf', 'text/plain', 'text/csv',
];

/* ---------------------------------------------------------------------
 * Tags curadas para o seletor fixo da aba Tickets.
 * Rótulo limpo => lista das grafias REAIS existentes no banco (agrupa
 * duplicatas). Editável conforme você for limpando as tags.
 * Tags de uso zero (prospecto, secutiry) ficam de fora; o resto das
 * ~2.873 tags continua acessível pelo autocomplete.
 * ------------------------------------------------------------------- */
const CURATED_TAGS = [
    'Mudança'      => ['mudança'],
    'Setup'        => ['setup', '#setup'],
    'Treinamento'  => ['treinamento'],
    'ModSecurity'  => ['modsecurity'],
    'Howto'        => ['howto'],
    'Shell Script' => ['shellscript', 'shell_script'],
    'Move'         => ['move'],
    'Auditoria'    => ['auditoria'],
    'Documentação' => ['documentação'],
    'Proposta'     => ['proposta'],
];

/* =====================================================================
 * Allowlist de IP (se configurada)
 * ===================================================================== */
if ($CFG['allow_ips'] !== '') {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $ok  = in_array($ip, array_map('trim', explode(',', $CFG['allow_ips'])), true);
    if (!$ok) {
        http_response_code(403);
        exit('403: acesso negado para este IP.');
    }
}

/* =====================================================================
 * Helpers
 * ===================================================================== */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dt(?int $ts): string  { return $ts ? date('d/m/Y H:i', $ts) : '-'; }

/**
 * Realça o termo buscado num texto, com segurança.
 * Escapa o HTML PRIMEIRO (h()) e só então insere <mark> no trecho que casa,
 * de forma case-insensitive e sem quebrar acentos UTF-8. Se não houver termo,
 * devolve o texto apenas escapado.
 */
function hl(?string $text, string $term): string {
    $safe = h($text);
    $term = trim($term);
    if ($term === '') return $safe;
    // escapa o termo para casar contra o texto JÁ escapado, e para o regex
    $needle = preg_quote(h($term), '/');
    return preg_replace('/(' . $needle . ')/iu', '<mark>$1</mark>', $safe) ?? $safe;
}

function pdo(array $cfg): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if ($cfg['db_user'] === '') {
        fail_setup('Credenciais do banco não definidas (SP_DB_USER / SP_DB_PASS).');
    }
    $dsn = "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Erro de conexão com o banco. Verifique as credenciais de ambiente.');
    }
    return $pdo;
}

function fail_setup(string $msg): void {
    http_response_code(500);
    echo "<!doctype html><meta charset='utf-8'><pre style='font:14px/1.5 monospace;padding:2rem;max-width:70ch'>";
    echo "CONFIGURAÇÃO INCOMPLETA\n\n" . h($msg) . "\n\n";
    echo "Defina as variáveis de ambiente (ex.: no .htaccess do diretório):\n\n";
    echo "  SetEnv SP_DB_HOST   127.0.0.1\n";
    echo "  SetEnv SP_DB_NAME   supportpal\n";
    echo "  SetEnv SP_DB_USER   sp_readonly\n";
    echo "  SetEnv SP_DB_PASS   ********\n";
    echo "  SetEnv SP_APP_HASH  \$2y\$...   (hash da senha de acesso)\n";
    echo "  SetEnv SP_STORAGE   /caminho/para/storage/uploads\n";
    echo "  SetEnv SP_ALLOW_IPS 200.0.0.1,10.0.0.0   (opcional)\n\n";
    echo "Gere o hash da senha de acesso com:\n";
    echo "  php -r 'echo password_hash(\"SUA_SENHA\", PASSWORD_DEFAULT), \"\\n\";'\n";
    echo "</pre>";
    exit;
}

/* =====================================================================
 * Autenticação (senha compartilhada)
 * ===================================================================== */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($CFG['app_hash'] === '') {
    fail_setup('Senha de acesso (SP_APP_HASH) não definida.');
}

if (empty($_SESSION['auth'])) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (password_verify((string)$_POST['password'], $CFG['app_hash'])) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        $err = 'Senha incorreta.';
        usleep(600000); // pequeno atraso anti-bruteforce
    }
    render_login($err);
    exit;
}

/* =====================================================================
 * Roteador
 * ===================================================================== */
// Teto de execução: a busca profunda (LIKE em ticket_message.text, sem
// índice) não deve conseguir segurar uma conexão indefinidamente.
@set_time_limit(20);
$db = pdo($CFG);

// Endpoint de mídia: ?media=HASH  → serve o arquivo do disco
if (isset($_GET['media'])) {
    serve_media($db, $CFG, (string)$_GET['media']);
    exit;
}

// Endpoint de autocomplete de tags: ?tags=termo → JSON com tags que batem
if (isset($_GET['tags'])) {
    suggest_tags($db, (string)$_GET['tags']);
    exit;
}

$view = $_GET['view'] ?? '';
if ($view === 'article' && isset($_GET['id'])) { render_article($db, (int)$_GET['id']); exit; }
if ($view === 'ticket'  && isset($_GET['id'])) { render_ticket($db, $CFG, (int)$_GET['id']); exit; }

render_search($db);
exit;

/* =====================================================================
 * MÍDIA: serve anexo a partir da tabela `upload`
 * Estrutura no disco do SupportPal: {storage}/{folder}/{hash}
 * (subpasta = upload.folder). Testa alguns candidatos por segurança.
 * ===================================================================== */
function serve_media(PDO $db, array $cfg, string $hash): void {
    // hash é alfanumérico; valida para evitar path traversal
    if (!preg_match('/^[A-Za-z0-9]+$/', $hash)) { http_response_code(400); exit('hash inválido'); }
    if ($cfg['storage'] === '') { http_response_code(500); exit('SP_STORAGE não configurado'); }

    $st = $db->prepare('SELECT filename, folder, mime, size FROM upload WHERE hash = :h LIMIT 1');
    $st->execute([':h' => $hash]);
    $u = $st->fetch();
    if (!$u) { http_response_code(404); exit('upload não encontrado'); }

    $base     = rtrim($cfg['storage'], '/');
    $baseReal = realpath($base);
    if ($baseReal === false) { http_response_code(500); exit('SP_STORAGE inválido (diretório não existe)'); }
    $baseReal = rtrim($baseReal, '/') . '/';

    $ext  = pathinfo($u['filename'], PATHINFO_EXTENSION);
    $candidates = [
        "$base/{$u['folder']}/$hash",
        "$base/{$u['folder']}/$hash" . ($ext ? ".$ext" : ''),
        "$base/$hash",
    ];
    $path = '';
    foreach ($candidates as $c) {
        $real = realpath($c);
        // containment real (com barra final), não apenas prefixo de string,
        // que poderia ser enganado por um diretório irmão com nome parecido.
        if ($real !== false && str_starts_with($real, $baseReal) && is_file($real)) {
            $path = $real; break;
        }
    }
    if ($path === '') {
        http_response_code(404);
        // dica para depuração de layout do storage (apenas internamente)
        exit('Arquivo não localizado no disco. Candidatos testados:' . PHP_EOL . implode(PHP_EOL, $candidates));
    }

    $mime = $u['mime'] ?: 'application/octet-stream';
    $safe = in_array($mime, SAFE_INLINE_MIMES, true);

    header('Content-Type: ' . ($safe ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: ' . ($safe ? 'inline' : 'attachment') . '; filename="' . rawurlencode($u['filename']) . '"');
    header('X-Content-Type-Options: nosniff');
    if (!$safe) header("Content-Security-Policy: sandbox; default-src 'none'");
    readfile($path);
}

/* =====================================================================
 * AUTOCOMPLETE de tags: ?tags=termo → JSON [{name, colour, usos}]
 * Busca por trecho no nome, ordena pelas mais usadas, limita a 15.
 * ===================================================================== */
function suggest_tags(PDO $db, string $term): void {
    header('Content-Type: application/json; charset=utf-8');
    $term = trim($term);
    if ($term === '') { echo '[]'; return; }
    $st = $db->prepare(
        "SELECT tt.name, tt.colour, COUNT(ttm.id) AS usos
         FROM ticket_tag tt
         LEFT JOIN ticket_tag_membership ttm ON ttm.tag_id = tt.id
         WHERE tt.name LIKE :t
         GROUP BY tt.id
         ORDER BY usos DESC, tt.name ASC
         LIMIT 15"
    );
    $st->bindValue(':t', '%' . $term . '%');
    $st->execute();
    echo json_encode($st->fetchAll(), JSON_UNESCAPED_UNICODE);
}

/* =====================================================================
 * BUSCA / LISTAGEM
 * ===================================================================== */
function render_search(PDO $db): void {
    $tab  = ($_GET['tab'] ?? 'kb') === 'tickets' ? 'tickets' : 'kb';
    $q    = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['p'] ?? 1));
    $off  = ($page - 1) * PER_PAGE;
    $like = '%' . $q . '%';

    header('Content-Type: text/html; charset=utf-8');
    layout_head('Consulta SupportPal');

    // Abas
    echo '<nav class="tabs">';
    foreach (['kb' => 'Base de Conhecimento', 'tickets' => 'Tickets'] as $k => $label) {
        $cls = $k === $tab ? ' class="on"' : '';
        echo '<a' . $cls . ' href="?tab=' . $k . '">' . $label . '</a>';
    }
    echo '<a class="logout" href="?logout=1">sair</a>';
    echo '</nav>';

    // Formulário de busca
    echo '<form class="search" method="get">';
    echo '<input type="hidden" name="tab" value="' . $tab . '">';
    echo '<input type="search" name="q" value="' . h($q) . '" autofocus placeholder="'
       . ($tab === 'kb' ? 'título ou texto do artigo…' : 'nº, assunto, nome ou e-mail do cliente…') . '">';
    if ($tab === 'kb') {
        $vis = $_GET['vis'] ?? 'all';
        echo '<select name="vis">';
        foreach (['all' => 'Todos', 'public' => 'Público', 'internal' => 'Interno'] as $vk => $vl) {
            $sel = $vk === $vis ? ' selected' : '';
            echo "<option value=\"$vk\"$sel>$vl</option>";
        }
        echo '</select>';
    } else {
        $deep = !empty($_GET['deep']);
        echo '<label class="chk"><input type="checkbox" name="deep" value="1"'
           . ($deep ? ' checked' : '') . '> buscar no corpo das mensagens</label>';
        // seletor de tags curadas + campo de autocomplete
        $curTag = (string)($_GET['tag'] ?? '');
        echo '<select name="tag" class="tagsel">';
        echo '<option value="">- tag -</option>';
        foreach (array_keys(CURATED_TAGS) as $label) {
            $sel = ($curTag === $label) ? ' selected' : '';
            echo '<option value="' . h($label) . '"' . $sel . '>' . h($label) . '</option>';
        }
        // se a tag atual não é uma curada (veio do autocomplete/clique), preserva como opção
        if ($curTag !== '' && !isset(CURATED_TAGS[$curTag])) {
            echo '<option value="' . h($curTag) . '" selected>' . h($curTag) . '</option>';
        }
        echo '</select>';
        echo '<input type="text" id="tagac" placeholder="ou digite uma tag…" autocomplete="off" '
           . 'value="' . (($curTag !== '' && !isset(CURATED_TAGS[$curTag])) ? h($curTag) : '') . '">';
        echo '<div id="tagac_list" class="tagac-list"></div>';

        // filtro: departamento (populado do banco)
        $curDep = (string)($_GET['dep'] ?? '');
        echo '<select name="dep"><option value="">- depto -</option>';
        foreach ($db->query("SELECT id, name FROM department ORDER BY name")->fetchAll() as $d) {
            $sel = ((string)$d['id'] === $curDep) ? ' selected' : '';
            echo '<option value="' . (int)$d['id'] . '"' . $sel . '>' . h($d['name']) . '</option>';
        }
        echo '</select>';

        // filtro: status (populado do banco)
        $curSt = (string)($_GET['st'] ?? '');
        echo '<select name="st"><option value="">- status -</option>';
        foreach ($db->query("SELECT id, name FROM ticket_status ORDER BY `order`, name")->fetchAll() as $s) {
            $sel = ((string)$s['id'] === $curSt) ? ' selected' : '';
            echo '<option value="' . (int)$s['id'] . '"' . $sel . '>' . h($s['name']) . '</option>';
        }
        echo '</select>';

        // filtro: período por última atualização
        $df = (string)($_GET['df'] ?? '');
        $dt = (string)($_GET['dt'] ?? '');
        echo '<label class="chk">de <input type="date" name="df" value="' . h($df) . '"></label>';
        echo '<label class="chk">até <input type="date" name="dt" value="' . h($dt) . '"></label>';
    }
    echo '<button>Buscar</button>';
    echo '</form>';
    if ($tab === 'tickets') echo tag_autocomplete_js();

    if ($tab === 'kb') {
        list_articles($db, $q, $like, $_GET['vis'] ?? 'all', $page, $off);
    } else {
        list_tickets($db, $q, $like, !empty($_GET['deep']), (string)($_GET['tag'] ?? ''),
                     (string)($_GET['dep'] ?? ''), (string)($_GET['st'] ?? ''),
                     (string)($_GET['df'] ?? ''), (string)($_GET['dt'] ?? ''), $page, $off);
    }

    layout_foot();
}

function list_articles(PDO $db, string $q, string $like, string $vis, int $page, int $off): void {
    // filtro de visibilidade via MAX(internal) dos tipos do artigo
    $having = '';
    if ($vis === 'public')   $having = 'HAVING any_internal = 0';
    if ($vis === 'internal') $having = 'HAVING any_internal = 1';

    $where = $q !== '' ? 'WHERE (a.title LIKE :q1 OR a.plain_text LIKE :q2)' : '';
    $sql = "SELECT a.id, a.title, a.excerpt, a.published, a.protected, a.updated_at,
                   GROUP_CONCAT(DISTINCT at.name ORDER BY at.name SEPARATOR ', ') AS types,
                   COALESCE(MAX(at.internal),0) AS any_internal
            FROM article a
            LEFT JOIN article_type_membership atm ON atm.article_id = a.id
            LEFT JOIN article_type at ON at.id = atm.type_id
            $where
            GROUP BY a.id
            $having
            ORDER BY a.updated_at DESC
            LIMIT " . PER_PAGE . " OFFSET " . (int)$off;
    $st = $db->prepare($sql);
    if ($q !== '') { $st->bindValue(':q1', $like); $st->bindValue(':q2', $like); }
    $st->execute();
    $rows = $st->fetchAll();

    echo '<div class="table-wrap"><table class="list"><thead><tr><th>#</th><th>Título</th><th>Tipos</th><th>Visib.</th><th>Status</th><th>Atualizado</th></tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="6" class="empty">Nenhum artigo encontrado.</td></tr>';
    foreach ($rows as $r) {
        $vlabel = $r['any_internal'] ? '<span class="badge int">interno</span>' : '<span class="badge pub">público</span>';
        $status = $r['published'] ? 'publicado' : '<span class="muted">rascunho</span>';
        echo '<tr>';
        echo '<td class="mono">' . (int)$r['id'] . '</td>';
        echo '<td><a href="?view=article&id=' . (int)$r['id'] . '">' . hl($r['title'], $q) . '</a>';
        if ($r['excerpt']) echo '<div class="excerpt">' . h($r['excerpt']) . '</div>';
        echo '</td>';
        echo '<td class="muted">' . h($r['types'] ?? '-') . '</td>';
        echo '<td>' . $vlabel . '</td>';
        echo '<td>' . $status . '</td>';
        echo '<td class="mono muted">' . dt((int)$r['updated_at']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    pager($page, count($rows), "?tab=kb&q=" . urlencode($q) . "&vis=" . urlencode($vis));
}

function list_tickets(PDO $db, string $q, string $like, bool $deep, string $tag,
                      string $dep, string $st, string $df, string $dt, int $page, int $off): void {
    $where = '';
    $binds = [];
    $conds = [];

    if ($q !== '') {
        // condições comuns: nº, assunto, nome (inclui nome completo) e e-mail do solicitante
        $cli = "t.number LIKE :q1
                OR t.subject LIKE :q2
                OR u.firstname LIKE :q3
                OR u.lastname LIKE :q4
                OR CONCAT_WS(' ', u.firstname, u.lastname) LIKE :q5
                OR u.email LIKE :q6
                OR EXISTS (SELECT 1 FROM user_email_address ea
                           WHERE ea.user_id = t.user_id AND ea.address LIKE :q7)";
        $binds[':q1'] = $binds[':q2'] = $binds[':q3'] = $binds[':q4'] =
        $binds[':q5'] = $binds[':q6'] = $binds[':q7'] = $like;

        if ($deep) {
            $cli .= " OR EXISTS (SELECT 1 FROM ticket_message m
                                 WHERE m.ticket_id = t.id AND m.text LIKE :q8)";
            $binds[':q8'] = $like;
        }
        $conds[] = "($cli)";
    }

    // filtro por tag: rótulo curado expande para as grafias reais; senão, literal
    if ($tag !== '') {
        $names = CURATED_TAGS[$tag] ?? [$tag];
        $ph = [];
        foreach ($names as $i => $n) { $k = ":tg$i"; $ph[] = $k; $binds[$k] = $n; }
        $conds[] = "EXISTS (SELECT 1 FROM ticket_tag_membership ttm
                            JOIN ticket_tag tt ON tt.id = ttm.tag_id
                            WHERE ttm.ticket_id = t.id AND tt.name IN (" . implode(',', $ph) . "))";
    }

    // filtro: departamento e status (IDs inteiros)
    if (ctype_digit($dep)) { $conds[] = 't.department_id = :dep'; $binds[':dep'] = (int)$dep; }
    if (ctype_digit($st))  { $conds[] = 't.status_id = :st';      $binds[':st']  = (int)$st; }

    // filtro: período por updated_at (campo é unix timestamp int)
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) {
        $conds[] = 't.updated_at >= :df'; $binds[':df'] = (int)strtotime($df . ' 00:00:00');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
        $conds[] = 't.updated_at <= :dt'; $binds[':dt'] = (int)strtotime($dt . ' 23:59:59');
    }

    if ($conds) $where = 'WHERE ' . implode(' AND ', $conds);

    $sql = "SELECT t.id, t.number, t.subject, t.updated_at, t.deleted_at, t.internal,
                   s.name AS status, s.colour AS s_col,
                   p.name AS priority,
                   d.name AS department,
                   u.firstname, u.lastname
            FROM ticket t
            LEFT JOIN ticket_status   s ON s.id = t.status_id
            LEFT JOIN ticket_priority p ON p.id = t.priority_id
            LEFT JOIN department      d ON d.id = t.department_id
            LEFT JOIN user            u ON u.id = t.user_id
            $where
            ORDER BY t.updated_at DESC
            LIMIT " . PER_PAGE . " OFFSET " . (int)$off;
    // nome diferente de $st (parâmetro = filtro de status) para não colidir
    // com ele. Bug pré-existente: reaproveitar "$st" aqui sobrescrevia o
    // filtro de status com o PDOStatement, quebrando o link do paginador
    // (urlencode() de um objeto) sempre que a listagem de tickets rodava.
    $stmt = $db->prepare($sql);
    foreach ($binds as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    echo '<div class="table-wrap"><table class="list"><thead><tr><th>Nº</th><th>Assunto</th><th>Solicitante</th><th>Depto</th><th>Status</th><th>Atualizado</th></tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="6" class="empty">Nenhum ticket encontrado.</td></tr>';
    foreach ($rows as $r) {
        $who = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? '')) ?: '-';
        $col = preg_match('/^#[0-9a-f]{6}$/i', (string)$r['s_col']) ? $r['s_col'] : '#888';
        echo '<tr' . ($r['deleted_at'] ? ' class="deleted"' : '') . '>';
        echo '<td class="mono"><a href="?view=ticket&id=' . (int)$r['id'] . '">' . h($r['number'] ?: ('#' . $r['id'])) . '</a></td>';
        echo '<td>' . hl($r['subject'], $q);
        if ($r['internal']) echo ' <span class="badge int">interno</span>';
        if ($r['deleted_at']) echo ' <span class="badge del">excluído</span>';
        echo '</td>';
        echo '<td class="muted">' . h($who) . '</td>';
        echo '<td class="muted">' . h($r['department'] ?? '-') . '</td>';
        echo '<td><span class="dot" style="background:' . h($col) . '"></span>' . h($r['status'] ?? '-') . '</td>';
        echo '<td class="mono muted">' . dt((int)$r['updated_at']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    pager($page, count($rows), "?tab=tickets&q=" . urlencode($q) . ($deep ? "&deep=1" : "")
        . ($tag !== '' ? "&tag=" . urlencode($tag) : "")
        . ($dep !== '' ? "&dep=" . urlencode($dep) : "")
        . ($st  !== '' ? "&st="  . urlencode($st)  : "")
        . ($df  !== '' ? "&df="  . urlencode($df)  : "")
        . ($dt  !== '' ? "&dt="  . urlencode($dt)  : ""));
}

function pager(int $page, int $count, string $base): void {
    echo '<div class="pager">';
    if ($page > 1)            echo '<a href="' . $base . '&p=' . ($page - 1) . '">← anterior</a>';
    echo '<span class="muted">página ' . $page . '</span>';
    if ($count === PER_PAGE)  echo '<a href="' . $base . '&p=' . ($page + 1) . '">próxima →</a>';
    echo '</div>';
}

/* =====================================================================
 * DETALHE: ARTIGO
 * ===================================================================== */
function render_article(PDO $db, int $id): void {
    $st = $db->prepare('SELECT * FROM article WHERE id = :id');
    $st->execute([':id' => $id]);
    $a = $st->fetch();

    header('Content-Type: text/html; charset=utf-8');
    layout_head('Artigo #' . $id);
    echo '<p class="back"><a href="?tab=kb">← voltar à busca</a></p>';

    if (!$a) { echo '<p class="empty">Artigo não encontrado.</p>'; layout_foot(); return; }

    // tipos / visibilidade
    $ts = $db->prepare('SELECT at.name, at.internal FROM article_type_membership atm
                        JOIN article_type at ON at.id = atm.type_id WHERE atm.article_id = :id');
    $ts->execute([':id' => $id]);
    $types = $ts->fetchAll();

    // categorias
    $cs = $db->prepare('SELECT c.name FROM article_cat_membership cm
                        JOIN article_category c ON c.id = cm.category_id WHERE cm.article_id = :id');
    $cs->execute([':id' => $id]);
    $cats = array_column($cs->fetchAll(), 'name');

    echo '<article class="doc">';
    echo '<h1>' . h($a['title']) . '</h1>';
    echo '<div class="meta">';
    echo '<span class="mono">#' . (int)$a['id'] . '</span>';
    echo $a['published'] ? '<span class="badge pub">publicado</span>' : '<span class="badge muted">rascunho</span>';
    foreach ($types as $t) {
        $cls = $t['internal'] ? 'int' : 'pub';
        echo '<span class="badge ' . $cls . '">' . h($t['name']) . '</span>';
    }
    if ($cats) echo '<span class="muted">cat: ' . h(implode(', ', $cats)) . '</span>';
    echo '<span class="muted">criado ' . dt((int)$a['created_at']) . ' · atualizado ' . dt((int)$a['updated_at']) . '</span>';
    echo '</div>';

    // anexos do artigo
    render_attachments($db, 'article', $id);

    // conteúdo (purified_text = HTML sanitizado que o SupportPal exibia)
    $html = $a['purified_text'] !== '' ? $a['purified_text'] : $a['text'];
    echo render_html_frame($html);
    echo '</article>';

    layout_foot();
}

/* =====================================================================
 * DETALHE: TICKET
 * ===================================================================== */
function render_ticket(PDO $db, array $cfg, int $id): void {
    $st = $db->prepare("SELECT t.*, s.name AS status, p.name AS priority, d.name AS department,
                               u.firstname, u.lastname, u.email
                        FROM ticket t
                        LEFT JOIN ticket_status   s ON s.id = t.status_id
                        LEFT JOIN ticket_priority p ON p.id = t.priority_id
                        LEFT JOIN department      d ON d.id = t.department_id
                        LEFT JOIN user            u ON u.id = t.user_id
                        WHERE t.id = :id");
    $st->execute([':id' => $id]);
    $t = $st->fetch();

    header('Content-Type: text/html; charset=utf-8');
    layout_head('Ticket #' . $id);
    echo '<p class="back"><a href="?tab=tickets">← voltar à busca</a></p>';

    if (!$t) { echo '<p class="empty">Ticket não encontrado.</p>'; layout_foot(); return; }

    $who = trim(($t['firstname'] ?? '') . ' ' . ($t['lastname'] ?? '')) ?: '-';
    echo '<header class="doc-head">';
    echo '<h1>' . h($t['subject']) . '</h1>';
    echo '<div class="meta">';
    echo '<span class="mono">' . h($t['number'] ?: ('#' . $id)) . '</span>';
    echo '<span class="badge">' . h($t['status'] ?? '-') . '</span>';
    echo '<span class="badge">' . h($t['priority'] ?? '-') . '</span>';
    echo '<span class="muted">' . h($t['department'] ?? '-') . '</span>';
    echo '<span class="muted">solicitante: ' . h($who) . ' &lt;' . h($t['email'] ?? '') . '&gt;</span>';
    echo '<span class="muted">aberto ' . dt((int)$t['created_at']) . '</span>';
    if ($t['deleted_at']) echo '<span class="badge del">excluído</span>';
    echo '</div>';

    // tags do ticket: pílulas clicáveis que filtram a busca por aquela tag
    $tg = $db->prepare("SELECT tt.name, tt.colour FROM ticket_tag_membership ttm
                        JOIN ticket_tag tt ON tt.id = ttm.tag_id
                        WHERE ttm.ticket_id = :id ORDER BY tt.name");
    $tg->execute([':id' => $id]);
    $tags = $tg->fetchAll();
    if ($tags) {
        echo '<div class="tags">';
        foreach ($tags as $tagRow) {
            $col = preg_match('/^#[0-9a-f]{3,6}$/i', (string)$tagRow['colour']) ? $tagRow['colour'] : '#888';
            echo '<a class="tagpill" style="border-color:' . h($col) . '" '
               . 'href="?tab=tickets&tag=' . urlencode($tagRow['name']) . '">'
               . '<span class="dot" style="background:' . h($col) . '"></span>'
               . h($tagRow['name']) . '</a>';
        }
        echo '</div>';
    }
    echo '</header>';

    // mensagens (respostas + notas), em ordem cronológica; ignora rascunhos
    $ms = $db->prepare("SELECT id, user_id, user_name, `by`, `type`, text, purified_text, created_at
                        FROM ticket_message
                        WHERE ticket_id = :id AND is_draft = 0
                        ORDER BY created_at ASC, id ASC");
    $ms->execute([':id' => $id]);
    $msgs = $ms->fetchAll();

    // anexos do ticket, agrupados por message_id
    $att = $db->prepare("SELECT ta.message_id, ta.original_name, u.hash, u.mime, u.size
                         FROM ticket_attachment ta JOIN upload u ON u.hash = ta.upload_hash
                         WHERE ta.ticket_id = :id");
    $att->execute([':id' => $id]);
    $byMsg = [];
    foreach ($att->fetchAll() as $row) $byMsg[(int)$row['message_id']][] = $row;

    echo '<div class="thread">';
    foreach ($msgs as $m) {
        $isNote = ((int)$m['type'] === MSG_TYPE_NOTE);
        $isOp   = ((int)$m['by'] === MSG_BY_OPERATOR);
        $cls = $isNote ? 'msg note' : ('msg ' . ($isOp ? 'op' : 'user'));
        echo '<div class="' . $cls . '">';
        echo '<div class="msg-head">';
        echo '<strong>' . h($m['user_name'] ?: '-') . '</strong>';
        echo '<span class="role">' . ($isOp ? 'operador' : 'cliente') . '</span>';
        if ($isNote) echo '<span class="badge note-b">nota interna</span>';
        echo '<span class="mono muted">' . dt((int)$m['created_at']) . '</span>';
        echo '</div>';
        $html = $m['purified_text'] !== '' ? $m['purified_text'] : $m['text'];
        echo render_html_frame($html);
        if (!empty($byMsg[(int)$m['id']])) {
            echo '<div class="attachments"><span class="muted">anexos:</span> ';
            foreach ($byMsg[(int)$m['id']] as $f) {
                echo '<a href="?media=' . h($f['hash']) . '" target="_blank">' . h($f['original_name'])
                   . ' <span class="muted">(' . kb((int)$f['size']) . ')</span></a> ';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div>';

    // diagnóstico opcional do mapeamento by/type
    if (!empty($_GET['diag'])) render_diag($db, $id);

    layout_foot();
}

function render_diag(PDO $db, int $id): void {
    $g = $db->query("SELECT `by`, `type`, COUNT(*) c FROM ticket_message GROUP BY `by`,`type` ORDER BY `by`,`type`")->fetchAll();
    echo '<div class="diag"><h3>Diagnóstico de mapeamento (global)</h3>';
    echo '<p class="muted">Confirme: atualmente <code>type=' . MSG_TYPE_NOTE . '</code> = nota, <code>by=' . MSG_BY_OPERATOR . '</code> = operador. Se a rotulagem acima parecer trocada, inverta as constantes no topo do arquivo.</p>';
    echo '<table class="list"><thead><tr><th>by</th><th>type</th><th>qtde</th></tr></thead><tbody>';
    foreach ($g as $r) echo '<tr><td class="mono">' . (int)$r['by'] . '</td><td class="mono">' . (int)$r['type'] . '</td><td class="mono">' . (int)$r['c'] . '</td></tr>';
    echo '</tbody></table></div>';
}

function render_attachments(PDO $db, string $kind, int $id): void {
    if ($kind !== 'article') return;
    $st = $db->prepare("SELECT a.original_name, u.hash, u.mime, u.size
                        FROM article_attachment a JOIN upload u ON u.hash = a.upload_hash
                        WHERE a.article_id = :id");
    $st->execute([':id' => $id]);
    $rows = $st->fetchAll();
    if (!$rows) return;
    echo '<div class="attachments"><span class="muted">anexos:</span> ';
    foreach ($rows as $f) {
        echo '<a href="?media=' . h($f['hash']) . '" target="_blank">' . h($f['original_name'])
           . ' <span class="muted">(' . kb((int)$f['size']) . ')</span></a> ';
    }
    echo '</div>';
}

function kb(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

/**
 * Reescreve URLs de download do SupportPal (domínio antigo, ex.:
 * https://SEU_DOMINIO_SUPPORTPAL/download/{HASH}?t=... ) para o
 * endpoint interno ?media={HASH}, servido pelo próprio viewer.
 * Cobre imagens inline (src) e links de anexo (href).
 */
function rewrite_media_urls(string $html): string {
    $self = $_SERVER['SCRIPT_NAME'] ?? '';
    return (string)preg_replace_callback(
        '~\b(src|href)\s*=\s*(["\'])([^"\']*/download/([A-Za-z0-9]{20,})[^"\']*)\2~i',
        function (array $m) use ($self) {
            return $m[1] . '=' . $m[2] . $self . '?media=' . $m[4] . $m[2];
        },
        $html
    );
}

/**
 * Renderiza HTML de terceiros com fidelidade, isolado num iframe sandbox.
 * - sem allow-scripts => qualquer <script> no conteúdo NÃO executa
 * - allow-same-origin => permite medir a altura e carregar mídia same-origin
 */
function render_html_frame(string $html): string {
    $html = rewrite_media_urls($html);
    $srcdoc = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base target="_blank">'
            . '<style>*{box-sizing:border-box}body{margin:0;padding:12px;font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;color:#1a1a1a;word-wrap:break-word;overflow-x:hidden}'
            . 'img{max-width:100%;height:auto}'
            // display:block + overflow-x:auto no próprio <table> permite rolagem
            // horizontal só da tabela (não da página) quando o conteúdo colado
            // (e-mail, planilha) tem mais colunas do que cabe na tela.
            . 'table{display:block;overflow-x:auto;max-width:100%;border-collapse:collapse}'
            . 'td,th{border:1px solid #ddd;padding:4px 8px}'
            . 'blockquote{border-left:3px solid #ccc;margin:0;padding-left:12px;color:#555}'
            . 'pre{white-space:pre-wrap;word-break:break-word;background:#f6f6f6;padding:8px;border-radius:4px}</style>'
            . $html;
    return '<iframe class="render" sandbox="allow-same-origin" srcdoc="' . h($srcdoc) . '" '
         . 'onload="spResizeIframe(this)"></iframe>';
}

/* =====================================================================
 * LAYOUT
 * ===================================================================== */
function layout_head(string $title): void {
    echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . ' | Consulta SupportPal</title>';
    $bodyClass = (($_COOKIE['sp_theme'] ?? '') === 'light') ? ' class="light"' : '';
    echo '<style>' . css() . '</style></head><body' . $bodyClass . '><div class="wrap">';
    echo '<div class="brand">arquivo&nbsp;morto · <strong>SupportPal</strong> <span class="muted">somente leitura</span>';
    echo '<button type="button" class="theme-btn" onclick="spToggleTheme()">tema</button>';
    echo '</div>';
    echo theme_js();
    echo iframe_resize_js();
}
function layout_foot(): void { echo '</div></body></html>'; }

/* JS mínimo: alterna a classe .light no body e grava a escolha em cookie (1 ano). */
function theme_js(): string {
    return '<script>function spToggleTheme(){'
         . 'var b=document.body,on=b.classList.toggle("light");'
         . 'document.cookie="sp_theme="+(on?"light":"dark")+";path=/;max-age=31536000;samesite=Lax";'
         . '}</script>';
}

/**
 * Ajusta a altura de um iframe de conteúdo (render_html_frame) para caber
 * sem barra de rolagem. Medir só no onload não basta: imagens dentro do
 * srcdoc costumam terminar de carregar DEPOIS do onload do iframe, então a
 * altura calculada naquele momento fica menor que o conteúdo final.
 * Com ResizeObserver, reajusta automaticamente sempre que o corpo interno
 * mudar de tamanho (imagem carregando, fonte carregando, etc.); sem
 * suporte a ResizeObserver, reconfere algumas vezes nos primeiros segundos.
 */
function iframe_resize_js(): string {
    return <<<'JS'
<script>
function spResizeIframe(ifr){
  function sync(){
    try{ ifr.style.height=(ifr.contentWindow.document.body.scrollHeight+24)+'px'; }catch(e){}
  }
  sync();
  try{
    var body=ifr.contentWindow.document.body;
    if(window.ResizeObserver){ new ResizeObserver(sync).observe(body); }
    else { [150,400,900,2000].forEach(function(t){ setTimeout(sync,t); }); }
  }catch(e){}
}
</script>
JS;
}

/* JS do autocomplete de tags: consulta ?tags=termo e preenche a lista.
 * Ao escolher uma sugestão, joga o nome no <select> (como opção) e submete. */
function tag_autocomplete_js(): string {
    return <<<'JS'
<script>
(function(){
  var inp=document.getElementById('tagac'), box=document.getElementById('tagac_list');
  if(!inp) return;
  var sel=document.querySelector('select.tagsel'), form=inp.closest('form'), t;
  function pick(name){
    var o=document.createElement('option'); o.value=name; o.textContent=name; o.selected=true;
    sel.appendChild(o); box.innerHTML=''; form.submit();
  }
  inp.addEventListener('input',function(){
    clearTimeout(t); var q=inp.value.trim();
    if(q.length<2){box.innerHTML='';return;}
    t=setTimeout(function(){
      fetch('?tags='+encodeURIComponent(q)).then(function(r){return r.json();}).then(function(rows){
        box.innerHTML='';
        rows.forEach(function(r){
          var d=document.createElement('div'); d.className='tagac-item';
          var c=(/^#[0-9a-f]{3,6}$/i.test(r.colour))?r.colour:'#888';
          d.innerHTML='<span class="dot" style="background:'+c+'"></span>'+
            r.name.replace(/[<>&]/g,'')+' <span class="muted">('+r.usos+')</span>';
          d.onclick=function(){pick(r.name);};
          box.appendChild(d);
        });
      });
    },200);
  });
  document.addEventListener('click',function(e){ if(!box.contains(e.target)&&e.target!==inp) box.innerHTML=''; });
})();
</script>
JS;
}

function render_login(string $err): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1"><title>Acesso</title>';
    $bodyClass = (($_COOKIE['sp_theme'] ?? '') === 'light') ? ' class="light"' : '';
    echo '<style>' . css() . '</style></head><body' . $bodyClass . '><div class="login">';
    echo '<form method="post"><h1>Consulta SupportPal</h1><p class="muted">acesso interno</p>';
    if ($err) echo '<p class="err">' . h($err) . '</p>';
    echo '<input type="password" name="password" placeholder="senha" autofocus required>';
    echo '<button>Entrar</button></form>';
    echo '<div class="login-foot">'
       . '<a href="' . h(APP_REPO_URL) . '/blob/main/CHANGELOG.md" target="_blank" rel="noopener">v' . h(APP_VERSION) . '</a>'
       . ' · <a href="' . h(APP_REPO_URL) . '" target="_blank" rel="noopener">GitHub</a>'
       . '</div>';
    echo '</div></body></html>';
}

/* CSS como função: funções são hoisted em PHP, então pode ser usada acima. */
function css(): string { return <<<CSS
:root{--bg:#0f1115;--panel:#171a21;--line:#262b35;--txt:#d7dbe2;--mut:#8a92a0;--acc:#e8a33d;--int:#c0563f;--pub:#3f8f5b;--hdr:#ffffff}
body.light{--bg:#f5f6f8;--panel:#ffffff;--line:#dde1e8;--txt:#1f242c;--mut:#6b7480;--acc:#b9781f;--int:#b34a35;--pub:#2f7d4c;--hdr:#11151b}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font:15px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace}
.wrap{max-width:1100px;margin:0 auto;padding:24px 20px 80px}
.brand{font-size:13px;letter-spacing:.04em;color:var(--mut);margin-bottom:18px;text-transform:uppercase;display:flex;align-items:center;gap:8px}
.brand strong{color:var(--acc)}
.theme-btn{margin-left:auto;background:var(--panel);border:1px solid var(--line);color:var(--mut);padding:4px 10px;border-radius:20px;font:inherit;font-size:12px;cursor:pointer;letter-spacing:0;text-transform:none}
.theme-btn:hover{color:var(--acc);border-color:var(--acc)}
a{color:var(--txt);text-decoration:none}
a:hover{color:var(--acc)}
.mono{font-family:ui-monospace,Menlo,monospace}
.muted{color:var(--mut);font-size:13px}
.tabs{display:flex;gap:2px;border-bottom:1px solid var(--line);margin-bottom:18px}
.tabs a{padding:9px 16px;color:var(--mut);font-size:14px;border-bottom:2px solid transparent}
.tabs a.on{color:var(--acc);border-color:var(--acc)}
.tabs .logout{margin-left:auto;color:var(--mut)}
.search{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;align-items:center}
.search input[type=search]{flex:1;min-width:240px}
input,select,button{background:var(--panel);border:1px solid var(--line);color:var(--txt);padding:9px 11px;border-radius:6px;font:inherit;font-size:14px}
button{background:var(--acc);color:#15171c;border-color:var(--acc);font-weight:600;cursor:pointer}
button:hover{filter:brightness(1.08)}
.chk{display:flex;align-items:center;gap:6px;color:var(--mut);font-size:13px}
.chk input{width:auto;padding:0}
.tagsel{max-width:170px}
#tagac{width:170px}
.tagac-list{position:relative;width:100%}
.tagac-item{position:relative;background:var(--panel);border:1px solid var(--line);border-top:0;padding:7px 10px;font-size:13px;cursor:pointer;display:flex;align-items:center;gap:7px}
.tagac-item:hover{background:var(--bg);color:var(--acc)}
.tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.tagpill{display:inline-flex;align-items:center;gap:6px;font-size:12px;padding:3px 10px;border:1px solid var(--line);border-radius:20px;color:var(--txt)}
.tagpill:hover{color:var(--acc)}
mark{background:rgba(232,163,61,.35);color:inherit;padding:0 1px;border-radius:2px}
.table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
table.list{width:100%;border-collapse:collapse;font-size:14px}
table.list th{text-align:left;color:var(--mut);font-weight:500;font-size:12px;text-transform:uppercase;letter-spacing:.04em;padding:8px 10px;border-bottom:1px solid var(--line)}
table.list td{padding:10px;border-bottom:1px solid var(--line);vertical-align:top}
table.list tr:hover td{background:var(--panel)}
tr.deleted td{opacity:.55}
.excerpt{color:var(--mut);font-size:12px;margin-top:3px;max-width:60ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.empty{color:var(--mut);text-align:center;padding:40px}
.badge{display:inline-block;font-size:11px;padding:2px 8px;border-radius:20px;border:1px solid var(--line);color:var(--mut);vertical-align:middle}
.badge.int{background:rgba(192,86,63,.15);color:#e0846c;border-color:transparent}
.badge.pub{background:rgba(63,143,91,.15);color:#7fc99a;border-color:transparent}
.badge.del{background:rgba(192,86,63,.2);color:#e0846c;border-color:transparent}
.badge.note-b{background:rgba(232,163,61,.15);color:var(--acc);border-color:transparent}
.dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:7px;vertical-align:middle}
.pager{display:flex;gap:18px;align-items:center;margin-top:20px;font-size:14px}
.back{margin-bottom:14px}
.doc h1,.doc-head h1{font-family:Georgia,serif;font-weight:600;font-size:26px;margin:0 0 12px;color:var(--hdr);line-height:1.25}
.meta{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid var(--line)}
.render{width:100%;border:0;background:#fff;border-radius:8px;display:block;min-height:60px}
.thread{display:flex;flex-direction:column;gap:16px}
.msg{border:1px solid var(--line);border-radius:10px;padding:14px;background:var(--panel)}
.msg.op{border-left:3px solid var(--acc)}
.msg.user{border-left:3px solid #4a78c0}
.msg.note{border-left:3px solid var(--acc);background:rgba(232,163,61,.06)}
.msg-head{display:flex;gap:10px;align-items:center;margin-bottom:10px;flex-wrap:wrap}
.msg-head .role{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.04em}
.attachments{margin-top:10px;font-size:13px;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.attachments a{color:var(--acc);text-decoration:underline}
.diag{margin-top:40px;padding-top:20px;border-top:1px dashed var(--line)}
.diag h3{color:var(--acc)}
.login{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;background:var(--bg);padding:20px;box-sizing:border-box}
.login form{background:var(--panel);border:1px solid var(--line);padding:32px;border-radius:12px;width:100%;max-width:300px;display:flex;flex-direction:column;gap:12px}
.login h1{font-size:18px;margin:0;color:var(--hdr)}
.login p{margin:0}
.login .err{color:#e0846c;font-size:13px}
.login-foot{margin-top:18px;font-size:12px;color:var(--mut);text-align:center}
.login-foot a{color:var(--mut);text-decoration:underline;text-underline-offset:2px}
.login-foot a:hover{color:var(--acc)}

/* Responsivo: telas estreitas (celular). O layout já é fluido por padrão
 * (%, flex-wrap, max-width em vez de largura fixa) e o iframe de conteúdo
 * já se ajusta sozinho (JS de resize); os ajustes abaixo são só refinamento
 * de espaçamento/tamanho para telas pequenas. */
@media (max-width:640px){
  .wrap{padding:16px 12px 60px}
  .brand{font-size:11px;flex-wrap:wrap}
  .search{gap:6px}
  .search input[type=search]{min-width:0}
  .tagsel,#tagac{width:100%;max-width:none}
  table.list th,table.list td{padding:7px 6px;font-size:13px}
  /* esconde as 2 colunas menos essenciais (3ª e 4ª: Tipos/Visib. na aba KB,
   * Solicitante/Depto na aba Tickets) pra evitar rolagem horizontal da
   * tabela em telas pequenas; o .table-wrap com overflow-x continua como
   * rede de segurança pra qualquer conteúdo que ainda não caiba */
  table.list th:nth-child(3), table.list td:nth-child(3),
  table.list th:nth-child(4), table.list td:nth-child(4) { display:none }
  .doc h1,.doc-head h1{font-size:20px}
  .meta{gap:6px}
  .pager{flex-wrap:wrap;gap:10px}
}
CSS;
}
