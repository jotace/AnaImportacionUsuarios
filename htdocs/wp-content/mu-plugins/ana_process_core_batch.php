<?php
/*
Plugin Name: ANA Importer Core Hook
Description: Callback para procesar lotes de creación de usuarios.
*/

if ( ! defined( 'ANA_IMPORT_BASE_PATH' ) ) {
    define( 'ANA_IMPORT_BASE_PATH', '/home/151341147/htdocs' );
}

add_action( 'ana_process_core_batch', function( $batch ) {
    // Verificar que $batch es un array
    if ( ! is_array( $batch ) ) {
        return;
    }

    foreach ( $batch as $user ) {
        // Evitar duplicados por email o nombre de usuario
        if ( email_exists( $user['user_email'] ) || username_exists( $user['user_login'] ) ) {
            continue;
        }

        $user_id = wp_insert_user( $user );
        if ( is_wp_error( $user_id ) ) {
            // Registrar el error en un archivo de log
            $mensaje  = date( 'c' ) . ' Error al crear ' . $user['user_login'] . ': ' .
                        $user_id->get_error_message() . PHP_EOL;
            $log_file = ANA_IMPORT_BASE_PATH . '/logs/ana-core-errors.log';
            error_log( $mensaje, 3, $log_file );
        }
    }
}, 10, 1 );