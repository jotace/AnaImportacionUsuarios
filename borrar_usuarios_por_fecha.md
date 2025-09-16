### BORRAR USUARIOS POR FECHA

´´´bash
START_UTC='2025-09-15 00:00:00'
END_UTC='2025-09-15 23:59:00'
PFX=$(wp db prefix)

<!-- 1 Ver el histograma por minuto (te ayuda a validar la ventana) -->
wp db query "SELECT DATE_FORMAT(user_registered,'%Y-%m-%d %H:%i') AS minute, COUNT(*) c
             FROM ${PFX}users
             WHERE user_registered >= '${START_UTC}' AND user_registered < '${END_UTC}'
             GROUP BY minute ORDER BY minute;" 
<!-- 2 Obtener los IDs y contarlos -->
IDS=$(wp db query "SELECT ID FROM ${PFX}users
                   WHERE user_registered >= '${START_UTC}' AND user_registered < '${END_UTC}';" --skip-column-names)
echo "$IDS" | wc -w

<!-- 3 Borrar SIN reasignar (seguro y rápido) -->
wp --yes --skip-plugins --skip-themes user delete $IDS
´´´