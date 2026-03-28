Checklist para implementar un feature nuevo en wa-cloud-panel.

ANTES de escribir código:
1. Corre `php artisan test` y confirma que todo pasa verde.
2. Revisa `docs/calendario-entregas.md` — ¿este feature pertenece a la etapa actual?
3. Revisa `.claude/rules/seguridad.md` — ¿el feature cumple todas las reglas?
4. Revisa `.claude/rules/convenciones-api.md` — si toca API, ¿sigue el formato?

DURANTE el desarrollo:
5. Crea el test Feature ANTES del código (o al mismo tiempo).
6. Si el feature toca WhatsAppClient, agrega un test Unit con mock de la API de Meta.
7. Nunca modificar tests existentes para que pasen — si fallan, el feature tiene un bug.

AL TERMINAR:
8. Corre `php artisan test` de nuevo — todo debe pasar verde.
9. Actualiza el checklist de progreso en `docs/calendario-entregas.md`.
10. Commit con mensaje descriptivo en español.
