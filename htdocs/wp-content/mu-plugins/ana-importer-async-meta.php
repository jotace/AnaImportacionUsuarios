<?php
/*
Plugin Name: ANA Importer Meta Hook (MU)
Description: Actualiza metadatos de usuarios existentes encolados con Action Scheduler (acepta payload individual, lote indexado o ['batch'=>[...]]) y evita doble registro.
Author: Juan + ChatGPT
Version: 1.0.0
Must Use: true
*/

if ( ! defined('ABSPATH') ) {
    exit;
}

/** Evita doble registro del hook si existe más de un archivo MU cargado */
if ( defined('ANA_IMPORT_META_HOOK_REGISTERED') ) {
    return;
}
define('ANA_IMPORT_META_HOOK_REGISTERED', true);

/**
 * Base path limpio (usa ABSPATH y normaliza barra final)
 */
if ( ! defined( 'ANA_IMPORT_BASE_PATH' ) ) {
    if ( function_exists('untrailingslashit') ) {
        define( 'ANA_IMPORT_BASE_PATH', untrailingslashit( ABSPATH ) );
    } else {
        define( 'ANA_IMPORT_BASE_PATH', rtrim( ABSPATH, "/\\" ) );
    }
}

/**
 * Directorio de logs:
 * - Reusamos ANA_IMPORT_LOG_DIR si ya existe (definido por otro MU)
 * - Si no existe, lo definimos aquí
 */
if ( ! defined( 'ANA_IMPORT_LOG_DIR' ) ) {
    if ( defined('WP_CONTENT_DIR') ) {
        define( 'ANA_IMPORT_LOG_DIR', rtrim( WP_CONTENT_DIR, "/\\" ) . '/ana-logs' );
    } else {
        define( 'ANA_IMPORT_LOG_DIR', ANA_IMPORT_BASE_PATH . '/logs' );
    }
}
if ( ! is_dir( ANA_IMPORT_LOG_DIR ) ) {
    if ( function_exists('wp_mkdir_p') ) {
        wp_mkdir_p( ANA_IMPORT_LOG_DIR );
    } else {
        @mkdir( ANA_IMPORT_LOG_DIR, 0755, true );
    }
}

/** Utilidad: ¿array indexado 0..n-1? (solo definimos si no existe ya) */
if ( ! function_exists('ana_is_indexed_list') ) {
    function ana_is_indexed_list( $arr ) {
        if ( ! is_array( $arr ) ) return false;
        $keys = array_keys( $arr );
        return $keys === range( 0, count( $arr ) - 1 );
    }
}

/**
 * Normalizador del payload (meta) — soporta:
 *   1) ['batch' => [ [item...], [item...] ]]
 *   2) [ [item...], [item...] ]                (lote indexado)
 *   3) [ 'user_login'=>..., 'meta'=>[...] ]    (ítem único)
 * Cada ítem debe incluir algún identificador: user_id | user_login | user_email
 */
if ( ! function_exists('ana_meta_normalize_payload') ) {
    function ana_meta_normalize_payload( $payload ) {
        if ( is_array($payload) && isset($payload['batch']) && is_array($payload['batch']) ) {
            return $payload['batch'];
        }
        if ( is_array($payload) && ana_is_indexed_list($payload) ) {
            return $payload;
        }
        if ( is_array($payload) && ( isset($payload['user_id']) || isset($payload['user_login']) || isset($payload['user_email']) ) ) {
            return [ $payload ];
        }
        return [];
    }
}

/** Hook principal: procesa actualizaciones de meta para usuarios existentes */
add_action( 'ana_process_user_meta_batch', function( $payload ) {

    $items = ana_meta_normalize_payload( $payload );
    if ( empty( $items ) ) {
        error_log( '['.date('c')."] [ANA META] Payload vacío o no reconocible para ana_process_user_meta_batch\n", 3, ANA_IMPORT_LOG_DIR . '/ana-meta-errors.log' );
        return;
    }

    foreach ( $items as $item ) {
        if ( ! is_array( $item ) ) { continue; }

        // Resolver usuario existente por prioridad: user_id > user_login > user_email
        $user_id = 0;

        if ( isset($item['user_id']) && is_numeric($item['user_id']) ) {
            $u = get_user_by('id', (int)$item['user_id']);
            if ( $u && isset($u->ID) ) { $user_id = (int)$u->ID; }
        }

        if ( $user_id === 0 && ! empty($item['user_login']) ) {
            $u = get_user_by('login', (string)$item['user_login']);
            if ( $u && isset($u->ID) ) { $user_id = (int)$u->ID; }
        }

        if ( $user_id === 0 && ! empty($item['user_email']) ) {
            $u = get_user_by('email', (string)$item['user_email']);
            if ( $u && isset($u->ID) ) { $user_id = (int)$u->ID; }
        }

        if ( $user_id === 0 ) {
            $who = [];
            foreach ( ['user_id','user_login','user_email'] as $k ) {
                if ( isset($item[$k]) && $item[$k] !== '' ) $who[] = $k.'='.$item[$k];
            }
            $who = $who ? implode(', ', $who) : 'sin identificador';
            error_log( '['.date('c')."] [ANA META] Usuario no encontrado ({$who})\n", 3, ANA_IMPORT_LOG_DIR . '/ana-meta-errors.log' );
            continue;
        }

        // Debe venir meta como array asociativo
        if ( ! isset($item['meta']) || ! is_array($item['meta']) || empty($item['meta']) ) {
            // Nada que actualizar; omitir silenciosamente
            continue;
        }

        // Actualización de cada meta key/value
        foreach ( $item['meta'] as $mk => $mv ) {
            // Evitar keys vacías
            if ( $mk === '' ) { continue; }

            // Guardamos valores escalares tal cual; complejos serializados
            if ( is_scalar($mv) || is_null($mv) ) {
                update_user_meta( $user_id, $mk, $mv );
            } else {
                update_user_meta( $user_id, $mk, maybe_serialize($mv) );
            }
        }

    }

}, 10, 1 );