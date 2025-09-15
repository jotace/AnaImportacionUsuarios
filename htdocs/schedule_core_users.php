<?php
// schedule_core_users.php
// Uso: wp eval-file schedule_core_users.php usuarios_fase1.csv role=subscriber

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    WP_CLI::error( 'Este script debe ejecutarse via WP-CLI.' );
}

$args_pos = isset( $args ) ? $args : [];
if ( count( $args_pos ) < 1 ) {
    WP_CLI::error( 'Indica el CSV: wp eval-file schedule_core_users.php <csv> [role=subscriber]' );
}

$csv_path = $args_pos[0];
$role     = 'subscriber';

// key=value opcionales
for ( $i = 1; $i < count( $args_pos ); $i++ ) {
    if ( strpos( $args_pos[$i], '=' ) !== false ) {
        list( $k, $v ) = array_map( 'trim', explode( '=', $args_pos[$i], 2 ) );
        if ( $k === 'role' ) {
            $role = $v;
        }
    }
}

if ( ! file_exists( $csv_path ) ) {
    WP_CLI::error( "No se encuentra el CSV: $csv_path" );
}

$fh     = fopen( $csv_path, 'r' );
$header = fgetcsv( $fh );
$idx    = array_flip( $header );

// columnas obligatorias
foreach ( ['username','email','first_name','last_name','user_pass'] as $col ) {
    if ( ! isset( $idx[ $col ] ) ) {
        fclose( $fh );
        WP_CLI::error( "El CSV debe incluir la columna $col" );
    }
}

$log_file  = '/home/151341147/htdocs/logs/ana-core-enqueue.log';
$start_ts  = time();
$offset    = 0;
$scheduled = 0;

while ( ( $row = fgetcsv( $fh ) ) !== false ) {
    $user = [
        'user_login' => $row[ $idx['username'] ],
        'user_email' => $row[ $idx['email'] ],
        'first_name' => $row[ $idx['first_name'] ],
        'last_name'  => $row[ $idx['last_name'] ],
        'user_pass'  => $row[ $idx['user_pass'] ],
        'role'       => $role,
    ];

    // Un usuario por acción; timestamp único por acción (evita deduplicación)
    $ts = $start_ts + $offset;
    $action_id = as_schedule_single_action(
        $ts,
        'ana_process_core_batch',
        [ [ $user ] ],
        'ana-import-core'
    );

    if ( $action_id ) {
        $scheduled++;
        // log opcional por cada acción programada
        error_log(
            date('c') . " Programada acción ID {$action_id} para {$user['user_login']} @ {$ts}\n",
            3,
            $log_file
        );
    } else {
        WP_CLI::warning( "No se pudo programar la acción para {$user['user_login']}" );
        error_log(
            date('c') . " ERROR al programar a {$user['user_login']}\n",
            3,
            $log_file
        );
    }

    $offset++; // +1s por acción
}
fclose( $fh );

WP_CLI::success( "Se encolaron {$scheduled} usuarios en acciones individuales (timestamps únicos)." );