<?php
/**
 * schedule_update_user_meta.php
 *
 * Encola actualizaciones de meta para usuarios existentes, haciendo match por:
 *  - username (user_login)    -> match=username  [DEFAULT]
 *  - email (user_email)       -> match=email
 *
 * Requiere: Action Scheduler activo.
 *
 * Uso (ejemplos, UNA sola línea):
 *   wp eval-file schedule_update_user_meta.php "/ruta/usuarios_fase2.csv" group=ana-import-meta match=username delimiter=',' step=1 now=1
 *   wp eval-file schedule_update_user_meta.php "/ruta/usuarios_fase2.csv" group=ana-import-meta match=email idcol=username delimiter=',' step=1 now=1
 *
 * Parámetros opcionales:
 *   group=ana-import-meta    Grupo AS (default: ana-import-meta)
 *   match=username|email     Método de identificación (default: username)
 *   idcol=<encabezado>       Fuerza la columna identificadora (por nombre de encabezado tal como aparece en CSV)
 *   delimiter=','|';'|'\t'   Delimitador CSV (default: ',')
 *   limit=100                Encolar solo N filas (para pruebas)
 *   step=1                   Segundos de separación entre acciones programadas (default: 0)
 *   now=1                    Ejecutar inmediatamente (default: 0 => +60s)
 */

if (!defined('WP_CLI')) {
    echo "Este script se ejecuta con WP-CLI.\n";
    exit(1);
}

// Dummy para que WP-CLI no falle al no registrar comando (algunos hosts lo requieren)
\WP_CLI::add_command('ana-dummy', function(){});

// ------- Parseo de argumentos CLI -------
$args = $_SERVER['argv'];
array_shift($args); // 'wp'
array_shift($args); // 'eval-file'
array_shift($args); // nombre de este archivo

if (empty($args)) {
    WP_CLI::error("Uso: wp eval-file schedule_update_user_meta.php /ruta/meta.csv group=ana-import-meta [match=username|email] [idcol=ENCABEZADO] [delimiter=','] [limit=100] [step=1] [now=1]");
}

$csv_path = $args[0];
array_shift($args);

$params = [
    'group'     => 'ana-import-meta',
    'match'     => 'username',   // o 'email'
    'delimiter' => ',',
    'limit'     => null,
    'step'      => 0,
    'now'       => 0,
    'idcol'     => null,
];

// Simple k=v
foreach ($args as $arg) {
    if (strpos($arg, '=') !== false) {
        list($k, $v) = array_map('trim', explode('=', $arg, 2));
        $k = strtolower($k);
        if (in_array($k, ['limit','step','now'], true)) {
            $params[$k] = (int)$v;
        } else {
            // quita comillas si vinieran
            $params[$k] = trim($v, "'\"");
        }
    }
}

// ------- Helpers -------
function ana_csv_normalize_header($h) {
    // Quita BOM al inicio si existiera
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    $h = strtolower(trim($h));
    // reemplazos de tildes y diéresis
    $trans = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n',
        'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u',
    ];
    $h = strtr($h, $trans);
    // cualquier cosa no alfanumérica -> _
    $h = preg_replace('/[^a-z0-9]+/','_', $h);
    // colapsa múltiples _
    $h = preg_replace('/_+/', '_', $h);
    // limpia extremos
    $h = trim($h, '_');
    return $h;
}

/**
 * Mapa explícito: meta_key => [posibles encabezados en CSV]
 * La PRIMERA coincidencia presente se usa.
 */
function ana_allowed_meta_map() {
    return [
        'fecha_de_nacimiento'               => ['fecha_de_nacimiento'],
        'provincia_ecuador'                 => ['provincia_ecuador','provincia'],
        'ciudad_ecuador'                    => ['ciudad_ecuador','ciudad'],
        'numero_de_contacto'                => ['numero_de_contacto','telefono','celular','numero_contacto'],
        'estado_civil'                      => ['estado_civil'],
        'hijos_menores_de_18_anios'         => ['hijos_menores_de_18_anios','hijos_menores_de_18_anos'],
        'edad_de_hijos_menores_de_18_anios' => ['edad_de_hijos_menores_de_18_anios','edad_hijos_menores_de_18'],
        'nivel_estudios'                    => ['nivel_estudios','nivel_de_estudios'],
        'ciudad_capacitacion'               => ['ciudad_capacitacion'],
        'becas_disp_hijos'                  => ['becas_disp_hijos','becas_disponibles_hijos'],
        'sector_ciudad'                     => ['sector_ciudad','sector'],
        'como_se_entero'                    => ['como_se_entero','como_se_entero_del_programa'],
        'user_cv_url'                       => ['user_cv_url','cv_url','curriculum_url'],
    ];
}

// ------- Validaciones iniciales -------
$group     = $params['group'];
$match_by  = ($params['match'] === 'email') ? 'email' : 'username';
$delimiter = $params['delimiter'];
$limit     = $params['limit'] !== null ? (int)$params['limit'] : null;
$step      = max(0, (int)$params['step']);
$start_now = !empty($params['now']) ? 1 : 0;
$idcol_in  = $params['idcol'];

if (!file_exists($csv_path)) {
    WP_CLI::error("No existe el archivo CSV: {$csv_path}");
}
$fh = fopen($csv_path, 'r');
if (!$fh) {
    WP_CLI::error("No se pudo abrir el CSV: {$csv_path}");
}

// Lee encabezados
$header = fgetcsv($fh, 0, $delimiter);
if (!$header) {
    fclose($fh);
    WP_CLI::error("El CSV está vacío o el delimitador '{$delimiter}' es incorrecto.");
}

// Indexa encabezados normalizados
$idx = [];
foreach ($header as $i => $h) {
    $norm = ana_csv_normalize_header($h);
    if ($norm !== '') {
        $idx[$norm] = $i;
    }
}

// ------- Localizar columnas de identificación -------

// Permitir idcol explícito (se usa tanto para username como para email, según match_by)
$idcol = null;
if (!empty($idcol_in)) {
    $idcol_norm = ana_csv_normalize_header($idcol_in);
    if (isset($idx[$idcol_norm])) {
        $idcol = $idx[$idcol_norm];
    } else {
        fclose($fh);
        WP_CLI::error("idcol='{$idcol_in}' (normalizado: '{$idcol_norm}') no existe en encabezados.");
    }
}

// Ampliamos candidatos para username y para email
$cands_user = ['user_login','username','login','user','user_name','nombre_de_usuario','usuario'];
$cands_mail = ['user_email','email','correo','correo_electronico'];

$username_col = null;
$email_col    = null;

// Si match=username y se forzó idcol, úsalo como username_col
if ($match_by === 'username' && $idcol !== null) {
    $username_col = $idcol;
} else {
    foreach ($cands_user as $cand) {
        $norm = ana_csv_normalize_header($cand);
        if (isset($idx[$norm])) { $username_col = $idx[$norm]; break; }
    }
}

// Siempre intentamos localizar email por si se usa match=email
if ($match_by === 'email' && $idcol !== null) {
    // Si se forzó idcol, úsalo como columna de email
    $email_col = $idcol;
} else {
    foreach ($cands_mail as $cand) {
        $norm = ana_csv_normalize_header($cand);
        if (isset($idx[$norm])) { $email_col = $idx[$norm]; break; }
    }
}

// Validaciones de presencia
if ($match_by === 'username' && $username_col === null) {
    fclose($fh);
    WP_CLI::error("No se encontró columna de username (prueba con idcol=... o revisa encabezados normalizados).");
}
if ($match_by === 'email' && $email_col === null) {
    fclose($fh);
    WP_CLI::error("No se encontró columna de email (prueba con idcol=... o revisa encabezados normalizados).");
}

// ------- Mapa meta permitido -------
$map = ana_allowed_meta_map();

// ------- Encolado -------
$scheduled  = 0;
$not_found  = 0;
$offset     = 0;
$base_ts    = time();

while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {

    // --- Resolver usuario según match_by ---
    $user = false;

    if ($match_by === 'username') {
        $identifier = isset($row[$username_col]) ? trim((string)$row[$username_col]) : '';
        if ($identifier === '') { $not_found++; continue; }

        // 1) Intento normal por login
        $user = get_user_by('login', $identifier);

        // 2) Fallback: si el "username" luce como email y no se encontró, intenta por email
        if (!$user && is_email($identifier)) {
            $user = get_user_by('email', $identifier);
        }

    } else { // match=email
        $email = isset($row[$email_col]) ? trim((string)$row[$email_col]) : '';
        if ($email === '' || !is_email($email)) { $not_found++; continue; }
        $user = get_user_by('email', $email);
    }

    if (!$user) { $not_found++; continue; }

    // --- Construir meta ESTRICTAMENTE con lista blanca + sinónimos ---
    $meta = [];
    foreach ($map as $meta_key => $csv_headers) {
        $value = null;
        foreach ($csv_headers as $hCand) {
            $norm = ana_csv_normalize_header($hCand);
            if (isset($idx[$norm])) {
                $i = $idx[$norm];
                $value = isset($row[$i]) ? $row[$i] : null;
                break; // usamos la primera coincidencia disponible
            }
        }
        if ($value !== null && $value !== '') {
            // Quita BOM si aplica + trim
            $value = preg_replace('/^\xEF\xBB\xBF/', '', (string)$value);
            $value = trim($value);
            $meta[$meta_key] = $value;
        }
    }

    if (empty($meta)) {
        // Nada que actualizar en esta fila
        continue;
    }

    $payload = [
        'user_id' => (int)$user->ID,
        'meta'    => $meta,
    ];

    // Tiempo de ejecución (inmediato o con offset)
    $ts = $start_now ? ($base_ts + $offset) : ($base_ts + 60 + $offset);
    $offset += $step;

    // Encola una acción única
    $ok = as_schedule_single_action($ts, 'ana_update_user_meta', [$payload], $group);
    if ($ok) { $scheduled++; }

    if ($limit !== null && $scheduled >= $limit) { break; }
}

fclose($fh);

// ------- Reporte -------
WP_CLI::success("Se encolaron {$scheduled} actualizaciones de meta en '{$group}'. No encontrados: {$not_found}.");
