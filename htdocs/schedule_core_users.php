<?php
/**
 * schedule_core_users.php
 * Encola usuarios desde un CSV a Action Scheduler.
 * - role por ENV (ROLE) o posicional 'role=subscriber'
 * - group por posicional 'group=ana-import-core' (default)
 * - now=1 encola inmediato (sin offsets futuros)
 * - Mapeo tolerante de columnas: user_login/username/login, user_email/email/correo, etc.
 */

/* Correr solo vía WP-CLI */
if ( ! defined('WP_CLI') || ! WP_CLI ) {
    if ( class_exists('WP_CLI') ) {
        WP_CLI::error('Este script debe ejecutarse vía WP-CLI.');
    }
    exit(1);
}

/** Entradas de WP-CLI */
$args       = isset($args) ? (array)$args : [];
$assoc_args = isset($assoc_args) ? (array)$assoc_args : [];

/** ---------- Resolver CSV (primer posicional no key=value) ---------- */
$csv_path = null;
foreach ( $args as $tok ) {
    // Primer token que no sea "--opcion" ni "k=v"
    if ( strpos($tok, '=') === false && strncmp($tok, '--', 2) !== 0 ) {
        $csv_path = $tok;
        break;
    }
}
if ( empty($csv_path) ) {
    WP_CLI::error("Falta el path del CSV. Uso: wp eval-file schedule_core_users.php /ruta/archivo.csv role=subscriber");
}

$real = @realpath($csv_path);
if ( $real && is_readable($real) ) {
    $csv_path = $real;
} elseif ( ! is_readable($csv_path) ) {
    WP_CLI::error("No puedo leer el CSV en: {$csv_path}");
}

/** ---------- Parseo de key=value posicionales (rápido en C con parse_str) ---------- */
$kv_tokens = [];
foreach ( $args as $tok ) {
    if ( strpos($tok, '=') !== false ) {
        $kv_tokens[] = $tok;
    }
}
$pos_opts = [];
if ( $kv_tokens ) {
    parse_str(implode('&', $kv_tokens), $pos_opts);
}

/** ---------- Role: prioridad assoc_args > posicional > ENV > default ---------- */
$role = 'subscriber';
if ( isset($assoc_args['role']) && $assoc_args['role'] !== '' ) {
    $role = $assoc_args['role'];
} elseif ( isset($pos_opts['role']) && $pos_opts['role'] !== '' ) {
    $role = $pos_opts['role'];
} elseif ( getenv('ROLE') ) {
    $role = getenv('ROLE');
}

/** ---------- Group: posicional 'group=...' o default ---------- */
$group = isset($pos_opts['group']) && $pos_opts['group'] !== '' ? $pos_opts['group'] : 'ana-import-core';

/** ---------- Encolar ahora (sin offsets) si 'now=1' ---------- */
$enqueue_now = isset($pos_opts['now']) && (string)$pos_opts['now'] === '1';

/** ---------- Delimitador opcional ---------- */
$delimiter = ',';
if ( isset($pos_opts['delimiter']) && $pos_opts['delimiter'] !== '' ) {
    $delimiter = (string) $pos_opts['delimiter'];
}

/** ---------- (Opcional) límite de filas para pruebas: limit=NN ---------- */
$limit = null;
if ( isset($pos_opts['limit']) && ctype_digit((string)$pos_opts['limit']) ) {
    $limit = (int) $pos_opts['limit'];
}

/** ---------- (Opcional) separador temporal entre acciones: step=segundos ---------- */
$step   = 0;
$offset = 0;
if ( isset($pos_opts['step']) && is_numeric($pos_opts['step']) ) {
    $step = max(0, (int) $pos_opts['step']);
}

/** ---------- Utilidad: trim BOM del primer encabezado ---------- */
$strip_bom = static function($s) {
    if ( is_string($s) ) {
        return preg_replace('/^\xEF\xBB\xBF/', '', $s);
    }
    return $s;
};

/** ---------- Abrir CSV y leer encabezados ---------- */
$fh = @fopen($csv_path, 'r');
if ( ! $fh ) {
    WP_CLI::error("No puedo abrir el CSV: {$csv_path}");
}

$headers = fgetcsv($fh, 0, $delimiter);
if ( $headers === false ) {
    fclose($fh);
    WP_CLI::error('CSV vacío o ilegible.');
}

/** Normalizar encabezados: retirar BOM y bajar a lowercase para mapeo */
$headers = array_map($strip_bom, $headers);
$lower_headers = array_map('strtolower', array_map('trim', $headers));
$index_map = array_flip($lower_headers); // e.g., ['user_login'=>0, 'email'=>1, ...]

/** Helper para obtener campo por alias de forma tolerante */
$pick = static function(array $row, array $index_map, array $aliases) {
    foreach ( $aliases as $alias ) {
        $key = strtolower($alias);
        if ( isset($index_map[$key]) ) {
            $i = $index_map[$key];
            return isset($row[$i]) ? trim((string)$row[$i]) : '';
        }
    }
    return '';
};

/** Logging inicial */
WP_CLI::log("CSV:  {$csv_path}");
WP_CLI::log("Role: " . (string)$role);
WP_CLI::log("Group: {$group}" . ($enqueue_now ? " | now=1" : ($step > 0 ? " | step={$step}s" : "")));

/** ---------- Iterar filas ---------- */
$hook      = 'ana_process_core_batch';
$scheduled = 0;
$line      = 1; // ya leímos encabezado

while ( ($row = fgetcsv($fh, 0, $delimiter)) !== false ) {
    $line++;

    // Si la fila tiene menos columnas que headers, rellenar con cadenas vacías
    if ( count($row) < count($headers) ) {
        $row = array_pad($row, count($headers), '');
    }

    // Construir usuario con mapeo tolerante
    $user = [
        'user_login' => $pick($row, $index_map, ['user_login','username','login','user','user_nicename']),
        'user_email' => $pick($row, $index_map, ['user_email','email','correo','mail']),
        'first_name' => $pick($row, $index_map, ['first_name','firstname','nombre']),
        'last_name'  => $pick($row, $index_map, ['last_name','lastname','apellido','apellidos']),
        'role'       => $role ?: 'subscriber',
    ];

    // Campos opcionales comunes si están presentes
    $display_name = $pick($row, $index_map, ['display_name','displayname','nombre_mostrar']);
    if ( $display_name !== '' ) $user['display_name'] = $display_name;

    $user_url = $pick($row, $index_map, ['user_url','url','website']);
    if ( $user_url !== '' ) $user['user_url'] = $user_url;

    $nickname = $pick($row, $index_map, ['nickname','nick','alias']);
    if ( $nickname !== '' ) $user['nickname'] = $nickname;

    // Si el CSV trae password explícito
    $user_pass = $pick($row, $index_map, ['user_pass','password','pass']);
    if ( $user_pass !== '' ) $user['user_pass'] = $user_pass;

    // Validación mínima de fila
    if ( $user['user_login'] === '' || $user['user_email'] === '' ) {
        // Fila incompleta: saltar
        continue;
    }

    // Timestamp de ejecución
    $ts = time();
    if ( !$enqueue_now && $step > 0 ) {
        $ts = $ts + $offset;
        $offset += $step;
    }

    // Encolar acción (payload = un usuario)
    $action_id = as_schedule_single_action( $ts, $hook, [ $user ], $group );

    if ( $action_id ) {
        $scheduled++;
    } else {
        // Si falla el encolado, registra un warning, pero sigue
        WP_CLI::warning("No se pudo encolar la fila {$line} (login={$user['user_login']})");
    }

    // Límite opcional para pruebas
    if ( $limit !== null && $scheduled >= $limit ) {
        break;
    }
}

fclose($fh);

/** ---------- Resumen ---------- */
if ( $scheduled > 0 ) {
    WP_CLI::success("Se encolaron {$scheduled} acciones en el grupo '{$group}' con hook '{$hook}'.");
} else {
    WP_CLI::warning("No se encoló ninguna acción. Revisa: cabeceras del CSV, valores requeridos (user_login/user_email), y parámetros (role/group/now/step).");
}
