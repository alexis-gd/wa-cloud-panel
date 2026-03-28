# WA Cloud Panel

Sistema de envío masivo de WhatsApp para empresa de préstamos (México).
Objetivo: 200,000 contactos/mes vía WhatsApp Cloud API oficial de Meta.

## Arrancar el servidor de desarrollo

```bash
cd c:/xampp/htdocs/wa-cloud-panel
php artisan serve
```

Panel disponible en: http://127.0.0.1:8000

> **Nota:** No usar Apache/XAMPP para este proyecto. XAMPP tiene un bug de
> redirect loops con mod_rewrite que impide que Laravel funcione correctamente.
> Siempre usar `php artisan serve`.

## Limpiar caché después de cambiar .env o config

```bash
cd c:/xampp/htdocs/wa-cloud-panel
php artisan config:clear
```

## Documentación del proyecto

Ver [docs/arquitectura-referencia.md](docs/arquitectura-referencia.md) para:
- Estructura de carpetas y archivos
- Rutas API disponibles
- Decisiones de arquitectura
- Comandos artisan de referencia

"Lee el CLAUDE.md y los archivos en .claude/rules/ para entender el contexto"