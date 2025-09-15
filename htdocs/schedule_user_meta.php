<?php
// schedule_user_meta.php
// Uso: wp eval-file schedule_user_meta.php usuarios_fase2.csv
// Requisitos del CSV: columna "ID" + cualquier número de columnas meta.
// Cada fila se programa como una acción individual.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    WP_CLI::error( 'Este script debe ejecutarse via WP-CLI.' );
}

$args_pos = isset( $args ) ? $args : [];
if ( count( $args_pos ) < 1 ) {
    WP_CLI::error( 'Indica el CSV: wp eval-file schedule_user_meta.php <csv>' );
}

$csv_path = $args_pos[0];
if ( ! file_exists( $csv_path ) ) {
    WP_CLI::error( "No se encuentra el CSV: $csv_path" );
}

$fh     = fopen( $csv_path, 'r' );
$header = fgetcsv( $fh );
if ( ! $header ) {
    fclose( $fh );
    WP_CLI::error( 'No se pudo leer la cabecera del CSV.' );
}
$idx = array_flip( $header );
if ( ! isset( $idx['ID'] ) ) {
    fclose( $fh );
    WP_CLI::error( 'El CSV debe contener la columna ID.' );
}

$log_file  = '/home/151341147/htdocs/logs/ana-meta-enqueue.log';
$scheduled = 0;

// Leer fila por fila y encolar acción por usuario
while ( ( $row = fgetcsv( $fh ) ) !== false ) {
    $uid = (int) $row[ $idx['ID'] ];
    if ( $uid <= 0 ) {
        error_log( date('c') . " Fila con ID inválido\n", 3, $log_file );
        continue;
    }

    // Construir metadatos a partir de todas las columnas excepto ID
    $meta = [];
    foreach ( $row as $col_num => $value ) {
        if ( $col_num === $idx['ID'] ) continue;
        $key = $header[ $col_num ];
        if ( $key === '' ) continue;
        if ( $value === '' || $value === null ) continue; // omitir vacíos
        $meta[ $key ] = $value;
    }

    // Si no hay metas útiles, omitimos (nada que actualizar)
    if ( empty( $meta ) ) {
        continue;
    }

    // Token único para evitar deduplicación aunque usemos el mismo timestamp
    $unique = bin2hex( random_bytes(4) ) . '-' . microtime(true);

    // Encolar una acción "due" ahora, con args únicos
    $action_id = as_schedule_single_action(
        time(),
        'ana_process_meta_batch',
        [ [ [ 'user_id' => $uid, 'meta' => $meta ] ], $unique ],
        'ana-import'
    );

    if ( $action_id ) {
        $scheduled++;
        error_log(
            date('c') . " Programada meta acción ID {$action_id} para user {$uid}\n",
            3,
            $log_file
        );
    } else {
        WP_CLI::warning( "No se pudo programar la acción de metadatos para user {$uid}" );
        error_log(
            date('c') . " ERROR al programar metadatos para user {$uid}\n",
            3,
            $log_file
        );
    }
}
fclose( $fh );

WP_CLI::success( "Se encolaron {$scheduled} acciones de metadatos (una por usuario)." );