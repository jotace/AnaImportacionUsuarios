<?php
/**
 * schedule_update_user_meta.php
 * Encola actualizaciones de meta para usuarios existentes,
 * haciendo match por username/user_login (default) o por email (match=email).
 *
 * Uso básico:
 *   wp eval-file schedule_update_user_meta.php /ruta/meta.csv group=ana-import-meta now=1
 *
 * Opcionales:
 *   match=email          (en lugar de username)
 *   delimiter=';'        (por defecto ',')
 *   limit=100            (solo encola N filas para prueba)
 *   step=1               (escalona 1s entre acciones; por defecto 0)
 */

if ( ! defined('WP_CLI') || ! WP_CLI ) {
    if ( class_exists('WP_CLI') ) { WP_CLI::error('Ejecutar vía WP-CLI.'); }
    exit(1);
}

$args       = isset($args) ? (array)$args : [];
$assoc_args = isset($assoc_args) ? (array)$assoc_args : [];

/** Resolver CSV (primer posicional no k=v) */
$csv_path = null;
foreach ( $args as $tok ) {
    if ( strpos($tok, '=') === false && strncmp($tok, '--', 2) !== 0 ) {
        $csv_path = $tok; break;
    }
}
if ( empty($csv_path) ) { WP_CLI::error("Falta path del CSV"); }

$real = @realpath($csv_path);
if ( $real && is_readable($real) ) { $csv_path = $real; }
elseif ( ! is_readable($csv_path) ) { WP_CLI::error("No puedo leer CSV: {$csv_path}"); }

/** Posicionales k=v en una pasada */
$kv_tokens = [];
foreach ( $args as $tok ) { if ( strpos($tok, '=') !== false ) $kv_tokens[] = $tok; }
$pos = [];
if ( $kv_tokens ) { parse_str(implode('&', $kv_tokens), $pos); }

$group = !empty($pos['group']) ? (string)$pos['group'] : 'ana-import-meta';
$enqueue_now = isset($pos['now']) && (string)$pos['now'] === '1';
$delimiter = !empty($pos['delimiter']) ? (string)$pos['delimiter'] : ',';
$limit = (isset($pos['limit']) && ctype_digit((string)$pos['limit'])) ? (int)$pos['limit'] : null;
$match_by = !empty($pos['match']) ? strtolower((string)$pos['match']) : 'username'; // 'username' | 'email'
$step   = (isset($pos['step']) && is_numeric($pos['step'])) ? max(0, (int)$pos['step']) : 0;

$fh = @fopen($csv_path, 'r');
if ( ! $fh ) { WP_CLI::error("No puedo abrir CSV: {$csv_path}"); }

$headers = fgetcsv($fh, 0, $delimiter);
if ( $headers === false ) { fclose($fh); WP_CLI::error('CSV vacío/ilegible.'); }

// Normalizar headers
$normalize = static function($s){ return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$s))); };
$headers = array_map($normalize, $headers);
$idx = array_flip($headers);

// Resolver columna de match
$username_col = null;
$email_col = null;

foreach ( ['user_login','username','login','user'] as $cand ) {
    if ( isset($idx[$cand]) ) { $username_col = $idx[$cand]; break; }
}
if ( isset($idx['user_email']) ) { $email_col = $idx['user_email']; }
if ( isset($idx['email']) && $email_col === null ) { $email_col = $idx['email']; }

if ( $match_by === 'username' && $username_col === null ) {
    fclose($fh);
    WP_CLI::error("No se encontró columna username/user_login/login en el CSV.");
}
if ( $match_by === 'email' && $email_col === null ) {
    fclose($fh);
    WP_CLI::error("No se encontró columna user_email/email en el CSV.");
}

// Meta keys = todas las columnas salvo las usadas para match
$skip_cols = [];
if ( $username_col !== null ) $skip_cols[] = $username_col;
if ( $email_col !== null )    $skip_cols[] = $email_col;

$meta_cols = [];
foreach ( $headers as $i => $name ) {
    if ( in_array($i, $skip_cols, true) ) continue;
    $meta_cols[] = $i;
}

WP_CLI::log("CSV: {$csv_path}");
WP_CLI::log("Group: {$group}" . ($enqueue_now ? " | now=1" : ($step>0 ? " | step={$step}s" : "")));
WP_CLI::log("Match by: {$match_by}");

$hook = 'ana_process_user_meta_batch';
$scheduled = 0;
$line = 1;
$offset = 0;

while ( ($row = fgetcsv($fh, 0, $delimiter)) !== false ) {
    $line++;

    if ( count($row) < count($headers) ) {
        $row = array_pad($row, count($headers), '');
    }

    $ident = '';
    $payload = [
        // id opcionalmente lo podemos rellenar si queremos forzar por ID
        // 'user_id' => 0,
    ];

    if ( $match_by === 'username' ) {
        $ident = trim((string)$row[$username_col]);
        if ( $ident === '' ) { continue; }
        $payload['user_login'] = $ident;
    } else { // email
        $ident = trim((string)$row[$email_col]);
        if ( $ident === '' ) { continue; }
        $payload['user_email'] = $ident;
    }

    // Construir meta desde columnas restantes (omitimos vacíos)
    $meta = [];
    foreach ( $meta_cols as $i ) {
        $key = $headers[$i];
        $val = isset($row[$i]) ? trim((string)$row[$i]) : '';
        if ( $val === '' ) continue;
        $meta[$key] = $val;
    }
    if ( empty($meta) ) { continue; }

    $payload['meta'] = $meta;

    // Timestamp
    $ts = time();
    if ( !$enqueue_now && $step > 0 ) {
        $ts = $ts + $offset;
        $offset += $step;
    }

    $ok = as_schedule_single_action($ts, $hook, [ $payload ], $group);
    if ( $ok ) { $scheduled++; }

    if ( $limit !== null && $scheduled >= $limit ) { break; }
}
fclose($fh);

if ( $scheduled > 0 ) {
    WP_CLI::success("Se encolaron {$scheduled} actualizaciones de meta en '{$group}'.");
} else {
    WP_CLI::warning("No se encoló ninguna actualización. Revisa el CSV (username/email y columnas de meta con valores).");
}
