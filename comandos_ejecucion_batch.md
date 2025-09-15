SE EJECUTAN EN LA CONSOLA DE SSH

FASE 1 (Campos basiccos de WP)

wp eval-file schedule_core_users.php usuarios_fase1.csv role=subscriber

wp action-scheduler run --group=ana-import-core --batch-size=50 --batches=0

FASE 2 (Campos meta adicionales)

wp eval-file schedule_user_meta.php usuarios_fase2.csv

wp action-scheduler run --group=ana-import --batch-size=50 --batches=0

