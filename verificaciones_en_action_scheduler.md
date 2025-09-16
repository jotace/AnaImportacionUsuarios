## VERIFICACIONES ACTION SCHEDULER

### Ver listas de acciones 

```bash
<!-- Pendientes -->
wp action-scheduler action list --status=pending --per-page=20

<!-- Completas -->
wp action-scheduler action list --status=complete --per-page=20

<!-- Fallidas -->
wp action-scheduler action list --status=faled --per-page=20
```
### Contar las acciones

```bash
<!-- Pendientes -->
wp action-scheduler action list --status=pending --format=count

<!-- Completas -->
wp action-scheduler action list --status=complete --format=count

<!-- Fallidas -->
wp action-scheduler action list --status=failed --format=count
```

