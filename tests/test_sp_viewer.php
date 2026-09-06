<?php
/**
 * Verificacao das correcoes de seguranca do sp_viewer.php.
 *
 * Rode com:  php tests/test_sp_viewer.php
 *
 * Sem framework e sem dependencia, de proposito: o projeto e um arquivo so e
 * nao tinha suite. Nao da para incluir `sp_viewer.php` aqui, porque incluir
 * executa a aplicacao inteira (sessao, config, conexao, roteador), entao a
 * funcao pura e recortada do arquivo REAL por ancora. Assim o teste exercita o
 * codigo que vai para producao, e nao uma copia que pode envelhecer.
 */
declare(strict_types=1);

$ALVO = dirname(__DIR__) . '/sp_viewer.php';
$src  = file_get_contents($ALVO);
if ($src === false) { fwrite(STDERR, "nao consegui ler $ALVO\n"); exit(2); }

$falhas = 0;
function want(string $caso, $obtido, $esperado): void {
    global $falhas;
    if ($obtido === $esperado) { printf("  ok      %s\n", $caso); return; }
    $falhas++;
    printf("  FALHOU  %s\n            esperava %s\n            veio     %s\n",
        $caso, var_export($esperado, true), var_export($obtido, true));
}
function want_true(string $caso, bool $ok): void { want($caso, $ok, true); }

/* ── recorte por ancora: so a funcao like_term ────────────────────────── */
if (!preg_match('/^function like_term\(.*?\n\}/ms', $src, $m)) {
    fwrite(STDERR, "ABORTADO: nao achei like_term() em sp_viewer.php\n");
    exit(2);
}
eval($m[0]);

echo "── achado 3: curinga do LIKE escapado ──\n";
want('termo comum vira %termo%',            like_term('suporte'),  '%suporte%');
want('porcento do usuario deixa de ser curinga', like_term('%'),   '%\\%%');
want('sublinhado deixa de ser curinga',     like_term('a_b'),      '%a\\_b%');
want('contrabarra e escapada primeiro',     like_term('a\\b'),     '%a\\\\b%');
want('combinacao dos tres',                 like_term('\\%_'),     '%\\\\\\%\\_%');
want('termo vazio ainda casa tudo (por desenho)', like_term(''),   '%%');
// A ordem importa: escapar `%` antes da contrabarra produziria `\\%`, que e uma
// contrabarra literal seguida de curinga, ou seja, o curinga voltaria a valer.
want_true('a contrabarra e escapada ANTES dos curingas',
    like_term('\\%') === '%\\\\\\%%');

echo "\n── achado 3: os dois pontos de LIKE usam o helper ──\n";
want_true('busca principal usa like_term',  (bool)preg_match('/\$like\s*=\s*like_term\(\$q\)/', $src));
want_true('autocomplete usa like_term',     (bool)preg_match("/bindValue\(':t',\s*like_term\(\\\$term\)\)/", $src));
want('nenhum LIKE montado a mao fora do helper',
    preg_match_all("/'%'\s*\.\s*\\\$/", $src), 0);

echo "\n── achado 2: o 404 de midia nao vaza caminho do disco ──\n";
if (!preg_match('/if \(\$path === \'\'\) \{.*?\n    \}/ms', $src, $b)) {
    fwrite(STDERR, "ABORTADO: nao achei o bloco de 404 do serve_media\n");
    exit(2);
}
$bloco = $b[0];
want_true('o bloco existe e foi recortado',      $bloco !== '');
want('a resposta nao imprime os candidatos',     preg_match('/exit\([^)]*\$candidates/', $bloco), 0);
want('a resposta nao imprime SP_STORAGE',        preg_match('/exit\([^)]*\$base/', $bloco), 0);
want_true('os candidatos vao para o error_log',  (bool)preg_match('/error_log\(.*\$candidates/s', $bloco));
want_true('a mensagem ao usuario continua generica',
    (bool)preg_match("/exit\('Arquivo não localizado no disco\.'\)/", $bloco));

/* ── achado M3 (varredura 2026-09-05): limite de tentativas de login ──── */
if (!preg_match('/^const LOGIN_MAX_FALHAS.*?\n(?=\/\*\*)/ms', $src, $c)
    || !preg_match('/^function login_throttle\(.*?\n\}/ms', $src, $f)) {
    fwrite(STDERR, "ABORTADO: nao achei login_throttle() em sp_viewer.php\n");
    exit(2);
}
eval($c[0] . $f[0]);
$dir = sys_get_temp_dir() . '/sp_viewer_test_' . getmypid();
@mkdir($dir);
$t0  = 1_800_000_000;
echo "── achado M3: limite de tentativas de login ──\n";
want('IP novo esta liberado',                    login_throttle('10.0.0.1', null,  $t0, $dir), 0);
for ($i = 1; $i <= 4; $i++) { login_throttle('10.0.0.1', false, $t0 + $i, $dir); }
want('4 falhas ainda nao bloqueiam',             login_throttle('10.0.0.1', null,  $t0 + 5, $dir), 0);
login_throttle('10.0.0.1', false, $t0 + 5, $dir);
want('5a falha bloqueia por 15 min',             login_throttle('10.0.0.1', null,  $t0 + 6, $dir), 899);
want('outro IP nao e afetado',                   login_throttle('10.0.0.2', null,  $t0 + 6, $dir), 0);
want('bloqueio expira 15 min apos a ultima falha', login_throttle('10.0.0.1', null, $t0 + 5 + 901, $dir), 0);
login_throttle('10.0.0.1', false, $t0 + 2000, $dir);
login_throttle('10.0.0.1', true,  $t0 + 2001, $dir);
want('sucesso zera o contador',                  login_throttle('10.0.0.1', null,  $t0 + 2002, $dir), 0);
want_true('falha sem sucesso conta de novo do zero apos a janela',
    login_throttle('10.0.0.1', false, $t0 + 2003, $dir) === 0);
$bl = preg_match('/if \(empty\(\$_SESSION\[\'auth\'\]\)\) \{.*?render_login\(\$err\);/s', $src, $lb) ? $lb[0] : '';
want_true('o fluxo de login consulta o throttle antes do password_verify',
    strpos($bl, 'login_throttle($ip)') !== false && strpos($bl, 'login_throttle($ip)') < strpos($bl, 'password_verify'));
want_true('falha registra e sucesso zera',
    strpos($bl, 'login_throttle($ip, false)') !== false && strpos($bl, 'login_throttle($ip, true)') !== false);
want_true('bloqueio responde 429', strpos($bl, 'http_response_code(429)') !== false);
array_map('unlink', glob("$dir/sp_login_*") ?: []); @rmdir($dir);

echo $falhas === 0
    ? "\nOK, todas as verificacoes passaram\n"
    : "\n$falhas verificacao(oes) falharam\n";
exit($falhas === 0 ? 0 : 1);
