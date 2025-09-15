<?php
/*
Plugin Name: ANA Importer Core Hook (MU)
Description: Callback robusto para procesar usuarios encolados con Action Scheduler (acepta payload individual, lote indexado o ['batch'=>[...]]) y evita doble registro.
Author: Juan + ChatGPT
Version: 1.3.0
Must Use: true
*/

if ( ! defined('ABSPATH') ) {
    exit;
}

/** Evita doble registro del hook si existe más de un archivo MU cargado */
if ( defined('ANA_IMPORT_CORE_HOOK_REGISTERED') ) {
    return;
}
define('ANA_IMPORT_CORE_HOOK_REGISTERED', true);

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
 * Directorio de logs: preferimos WP_CONTENT_DIR/ana-logs (más portable)
 * Fallback: <wp-root>/logs
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

/** Utilidad: ¿array indexado 0..n-1? */
if ( ! function_exists('ana_is_indexed_list') ) {
    function ana_is_indexed_list( $arr ) {
        if ( ! is_array( $arr ) ) return false;
        $keys = array_keys( $arr );
        return $keys === range( 0, count( $arr ) - 1 );
    }
}

/**
 * Normaliza el payload recibido por el hook para soportar:
 *   1) ['batch' => [ [user...], [user...] ]]
 *   2) [ [user...], [user...] ]               (lote indexado)
 *   3) [ 'user_login'=>..., 'user_email'=>... ] (un solo usuario)
 */
if ( ! function_exists('ana_import_normalize_payload') ) {
    function ana_import_normalize_payload( $payload ) {
        // 1) Envoltorio batch
        if ( is_array($payload) && isset($payload['batch']) && is_array($payload['batch']) ) {
            return $payload['batch'];
        }
        // 2) Un único usuario (array asociativo)
        if ( is_array($payload) && isset($payload['user_login']) && isset($payload['user_email']) ) {
            return [ $payload ];
        }
        // 3) Lote indexado
        if ( is_array($payload) && ana_is_indexed_list($payload) ) {
            return $payload;
        }
        // Nada utilizable
        return [];
    }
}

/** Sanitización de usuario + valores por defecto seguros */
if ( ! function_exists('ana_sanitize_user_array') ) {
    function ana_sanitize_user_array( array $user ) : array {
        $login = isset($user['user_login']) ? (string)$user['user_login'] : '';
        $email = isset($user['user_email']) ? (string)$user['user_email'] : '';

        if ( function_exists('sanitize_user') ) {
            $login = sanitize_user( $login, true );
        } else {
            $login = trim( $login );
        }

        if ( function_exists('sanitize_email') ) {
            $email = sanitize_email( $email );
        } else {
            $email = trim( $email );
        }

        $role = isset($user['role']) && $user['role'] !== '' ? (string)$user['role'] : 'subscriber';
        if ( function_exists('sanitize_key') ) {
            $role = sanitize_key( $role );
        }

        $out = [
            'user_login' => $login,
            'user_email' => $email,
            'role'       => $role,
        ];

        // Campos opcionales comunes
        foreach ( ['first_name','last_name','display_name','user_nicename','user_url','nickname','description'] as $k ) {
            if ( isset($user[$k]) && $user[$k] !== '' ) {
                $out[$k] = (string)$user[$k];
            }
        }

        // user_pass:
        // - Si viene, se usa.
        // - Si no viene, define cadena vacía para evitar warning "Undefined array key 'user_pass'" en Core.
        if ( array_key_exists('user_pass', $user) && $user['user_pass'] !== '' ) {
            $out['user_pass'] = (string)$user['user_pass'];
        } else {
            $out['user_pass'] = ''; // WP generará uno aleatorio en altas nuevas
        }

        return $out;
    }
}

/**
 * Hook principal del procesador
 * Firma esperada desde el encolador:
 *   as_schedule_single_action( time(), 'ana_process_core_batch', [ $user ], 'ana-import-core' );
 *   as_schedule_single_action( time(), 'ana_process_core_batch', [ [ ... ], [ ... ] ], 'ana-import-core' );
 *   as_schedule_single_action( time(), 'ana_process_core_batch', [ [ 'batch' => [ ... ] ] ], 'ana-import-core' );
 */
add_action( 'ana_process_core_batch', function( $payload ) {

    $users = ana_import_normalize_payload( $payload );
    if ( empty( $users ) ) {
        error_log( '['.date('c')."] [ANA IMPORT] Payload vacío o no reconocible para ana_process_core_batch\n", 3, ANA_IMPORT_LOG_DIR . '/ana-core-errors.log' );
        return;
    }

    // Cache de roles válidos
    $valid_roles = [];
    if ( function_exists('wp_roles') ) {
        $valid_roles = array_keys( wp_roles()->roles );
    }

    foreach ( $users as $user ) {
        if ( ! is_array( $user ) ) {
            // Evita "Cannot access offset of type string on string"
            continue;
        }

        $u = ana_sanitize_user_array( $user );

        // Requeridos mínimos
        if ( $u['user_login'] === '' || $u['user_email'] === '' ) {
            continue;
        }

        // Email válido
        if ( function_exists('is_email') && ! is_email( $u['user_email'] ) ) {
            continue;
        }

        // Rol válido o fallback
        if ( ! empty($valid_roles) && ! in_array( $u['role'], $valid_roles, true ) ) {
            $u['role'] = 'subscriber';
        }

        // Evitar duplicados
        if ( ( function_exists('email_exists') && email_exists( $u['user_email'] ) ) ||
             ( function_exists('username_exists') && username_exists( $u['user_login'] ) ) ) {
            continue;
        }

        // Inserción
        $user_id = wp_insert_user( $u );

        if ( is_wp_error( $user_id ) ) {
            $msg = '['.date('c').'] [ANA IMPORT] Error al crear '.$u['user_login'].': '.$user_id->get_error_message()."\n";
            error_log( $msg, 3, ANA_IMPORT_LOG_DIR . '/ana-core-errors.log' );
            continue;
        }

        // Meta opcional
        if ( isset($user['meta']) && is_array($user['meta']) ) {
            foreach ( $user['meta'] as $mk => $mv ) {
                if ( is_scalar($mv) || is_null($mv) ) {
                    update_user_meta( $user_id, $mk, $mv );
                } else {
                    update_user_meta( $user_id, $mk, maybe_serialize($mv) );
                }
            }
        }
    }

}, 10, 1 );