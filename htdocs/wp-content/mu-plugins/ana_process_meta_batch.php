<?php
/*
Plugin Name: ANA Importer Meta Hook
Description: Callback para procesar metadatos de usuarios en lotes individuales.
*/

if ( ! defined( 'ANA_IMPORT_BASE_PATH' ) ) {
    define( 'ANA_IMPORT_BASE_PATH', '/home/151341147/htdocs' );
}

add_action( 'ana_process_meta_batch', function( $batch, $unique ) {
    // $batch es un array de items; cada item: ['user_id' => int, 'meta' => array]
    if ( ! is_array( $batch ) ) return;

    foreach ( $batch as $item ) {
        $uid  = isset( $item['user_id'] ) ? (int) $item['user_id'] : 0;
        $meta = isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : [];

        if ( $uid <= 0 ) {
            error_log(
                date('c') . " Meta: user_id inválido\n",
                3,
                ANA_IMPORT_BASE_PATH . '/logs/ana-import-errors.log'
            );
            continue;
        }

        foreach ( $meta as $key => $value ) {
            if ( $value === '' || $value === null ) continue; // omitir vacíos
            try {
                update_user_meta( $uid, $key, $value );
            } catch ( Exception $e ) {
                error_log(
                    date('c') . " Error meta {$key} de {$uid}: " . $e->getMessage() . "\n",
                    3,
                    ANA_IMPORT_BASE_PATH . '/logs/ana-import-errors.log'
                );
                // Reprogramar sólo este item si falla
                $token = bin2hex(random_bytes(4)) . '-' . microtime(true);
                as_schedule_single_action(
                    time(),
                    'ana_process_meta_batch',
                    [ [ [ 'user_id' => $uid, 'meta' => [ $key => $value ] ] ], $token ],
                    'ana-import'
                );
            }
        }
    }
}, 10, 2);
