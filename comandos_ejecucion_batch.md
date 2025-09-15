SE EJECUTAN EN LA CONSOLA DE SSH

FASE 1 (Campos basiccos de WP)

wp eval-file schedule_core_users.php usuarios_fase1.csv role=subscriber

wp action-scheduler run --group=ana-import-core --batch-size=50 --batches=0

FASE 2 (Campos meta adicionales)

wp eval-file schedule_user_meta.php usuarios_fase2.csv

wp action-scheduler run --group=ana-import --batch-size=50 --batches=0

## ACTUALIZACIÓN LUEGO DE OPTIMIZACIÓN DE SCRIPT DE CARGA FASE 1

### FASE 1 IMPORTACIÓN DE USUARIOS - USO RECOMENDADO

### Con posicionales (tu WP-CLI los acepta) + ENV como respaldo
```bash
ROLE=subscriber wp eval-file schedule_core_users.php "./usuarios_fase1.csv" role=subscriber group=ana-import-core now=1
```

### Luego ejecutar
```bash
wp action-scheduler run --group=ana-import-core --batch-size=200 --batches=0
```
### Cron
Aquí se recomienda lanzar un Cron del sistema para cargas grandes, pero consultando la documentación de Pressable, no se pueden ejecutar crons del sistema en estos servicios de hosting a menos que tengamos un VPN administrado en totalidad por nosotros.

La alternativa es lanzar el WP-Cron de Wordpress en una sola línea de WP-CLI(no es lo optimo pero si no hay cron de sistema es lo siguiente recomendado)

```bash
* * * * * /usr/bin/env wp --path=/home/151341147/htdocs action-scheduler run --group=ana-import-core --batch-size=200 --batches=1 >> /home/151341147/as_runner.log 2>&1
```