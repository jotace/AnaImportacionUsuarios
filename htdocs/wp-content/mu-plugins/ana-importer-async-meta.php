<?php
/*
Plugin Name: ANA Importer Meta Hook (MU)
Description: Actualiza metadatos de usuarios existentes, encolados con Action Scheduler. Procesa payloads con estructura clara y lista blanca de meta_keys.
Author: Juan + ChatGPT
Version: 1.1.0
Must Use: true
*/

if (!defined('ABSPATH')) { exit; }

/** Evita doble registro del hook si existe más de un archivo MU cargado */
if (defined('ANA_IMPORT_META_HOOK_REGISTERED')) {
    return;
}
define('ANA_IMPORT_META_HOOK_REGISTERED', true);

/**
 * Lista blanca de meta_keys permitidas (exactas en la BD).
 * Sólo estas claves se actualizarán.
 */
function ana_meta_allowed_keys() {
    return [
        'fecha_de_nacimiento',
        'provincia_ecuador',
        'ciudad_ecuador',
        'numero_de_contacto',
        'estado_civil',
        'hijos_menores_de_18_anios',
        'edad_de_hijos_menores_de_18_anios',
        'nivel_estudios',
        'ciudad_capacitacion',
        'becas_disp_hijos',
        'sector_ciudad',
        'como_se_entero',
        'user_cv_url',
    ];
}

/**
 * Normaliza strings simples sin “cocinar” demasiado (evita alterar contenido).
 */
function ana_trim_normal($v) {
    if (is_string($v)) {
        // Elimina BOM si llegara a existir
        $v = preg_replace('/^\xEF\xBB\xBF/', '', $v);
        return trim($v);
    }
    return $v;
}

/**
 * Hook que procesa el payload para actualizar metadatos.
 * Se espera un array con estructura:
 *   [
 *     'user_id' => (int),
 *     'meta' => [ 'meta_key' => 'valor', ... ]
 *   ]
 */
add_action('ana_update_user_meta', function ($payload) {

    if (!is_array($payload)) { return; }

    // Permitimos batch: ['batch' => [ item, item, ... ]]
    if (isset($payload['batch']) && is_array($payload['batch'])) {
        foreach ($payload['batch'] as $item) {
            do_action('ana_update_user_meta', $item);
        }
        return;
    }

    // Item único
    $user_id = isset($payload['user_id']) ? intval($payload['user_id']) : 0;
    $meta    = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : null;

    if ($user_id <= 0 || empty($meta)) {
        return;
    }

    $allowed = array_flip(ana_meta_allowed_keys());

    foreach ($meta as $mk => $mv) {
        // Asegura que la key sea exactamente una de la lista blanca
        if (!isset($allowed[$mk])) {
            // Si quieres, loggea para auditoría:
            // error_log("ANA IMPORT META: meta_key no permitida: {$mk}");
            continue;
        }

        // Normaliza mínimamente el valor (trim + quita BOM si aplica)
        $val = ana_trim_normal($mv);

        // Guarda escalar tal cual; complejos serializados
        if (is_scalar($val) || is_null($val)) {
            update_user_meta($user_id, $mk, $val);
        } else {
            update_user_meta($user_id, $mk, maybe_serialize($val));
        }
    }

}, 10, 1);