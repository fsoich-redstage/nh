# Nutri Helper

Bot de WhatsApp sobre Green API: recibe fotos de comidas, las analiza con OpenAI
(calorías/proteínas/carbohidratos/grasas), guarda el historial por usuario, y
ofrece un recordatorio de agua opt-in. PHP puro, sin framework ni dependencias
de Composer.

## Estructura

```
bin/send_water_reminder.php   # script de cron: recordatorio de agua a personas con setting=1
public/webhook.php            # webhook de Green API (onboarding, comandos de texto, análisis de imagen)
public/landing.php            # historial público por identifier (?identifier=XXXX)
src/Config.php                # loader de .env, sin fallback hardcodeado
src/autoload.php              # autoload PSR-4 manual (namespace NutriHelper\)
src/Http/GreenApiClient.php   # sendMessage / GetContactInfo / downloadFile
src/Http/OpenAiClient.php     # análisis nutricional vía Responses API
src/Db/Database.php           # conexión PDO
src/Repository/               # PersonaRepository, NutritionRepository
src/Domain/                   # normalización de payload, ruteo de mensajes, parser de análisis, storage de imágenes, dedupe
src/View/LandingRenderer.php  # HTML del historial
storage/images/               # fotos guardadas (gitignored)
storage/locks/                # dedupe de eventos de webhook (gitignored)
logs/                         # logs de la app (gitignored)
```

## Setup

1. `cp .env.example .env` y completar:
   - `GREEN_API_URL`, `GREEN_API_INSTANCE_ID`, `GREEN_API_TOKEN`
   - `OPENAI_API_KEY`
   - `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`
   - `NUTRI_IMAGE_DIR` (ruta absoluta con permisos de escritura)
   - `NUTRI_LANDING_BASE_URL` (dominio público donde vive `public/landing.php`)
2. Crear las tablas (ver esquema abajo).
3. Apuntar el servidor web a `public/` como document root, o exponer
   `public/webhook.php` y `public/landing.php` detrás de tu proxy actual.
4. Configurar el webhook de Green API para que apunte a
   `https://tu-dominio/webhook.php` (notificaciones de mensajes entrantes).
5. Programar `bin/send_water_reminder.php` en cron (ej. cada hora) con
   `php /ruta/a/nutri-helper/bin/send_water_reminder.php`.

## Esquema SQL

Inferido del código original — ajustar tipos/tamaños si tu DB real difiere.

```sql
CREATE TABLE persona (
    number     BIGINT UNSIGNED NOT NULL,
    name       VARCHAR(191) NOT NULL DEFAULT '',
    shortname  VARCHAR(191) NOT NULL DEFAULT '',
    foto       VARCHAR(191) NOT NULL DEFAULT '',
    identifier VARCHAR(16)  NOT NULL,
    tipo       VARCHAR(32)  NOT NULL DEFAULT 'default',
    campo1     TINYINT(1)   NOT NULL DEFAULT 0,
    campo2     TINYINT(1)   NOT NULL DEFAULT 0,
    setting    TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (number),
    UNIQUE KEY uniq_identifier (identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE nutri (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    foto                 VARCHAR(191) NOT NULL,
    descripcion          TEXT NOT NULL,
    datetime             DATETIME NOT NULL,
    identifier           VARCHAR(16) NOT NULL,
    calorias             INT NOT NULL DEFAULT 0,
    proteinas            INT NOT NULL DEFAULT 0,
    grasas               INT NOT NULL DEFAULT 0,
    carbohidratos        INT NOT NULL DEFAULT 0,
    calorias_label       VARCHAR(64) NOT NULL DEFAULT '',
    proteinas_label      VARCHAR(64) NOT NULL DEFAULT '',
    grasas_label         VARCHAR(64) NOT NULL DEFAULT '',
    carbohidratos_label  VARCHAR(64) NOT NULL DEFAULT '',
    source               VARCHAR(32) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_identifier_datetime (identifier, datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Seguridad — pendiente antes de producción

El proyecto anterior (en `~/Desktop/nutri` y archivos sueltos) tenía
hardcodeados en texto plano: el token de Green API, la password de MySQL y una
API key de OpenAI. **Esas credenciales quedaron expuestas y hay que rotarlas**
(generar nuevas en Green API, OpenAI y MySQL) antes de considerar este
proyecto listo para producción. Este repo nuevo no tiene ningún fallback
hardcodeado — si falta una variable en `.env`, tira una excepción en vez de
usar un valor por defecto, así que rotarlas después es solo cambiar `.env`.

## Fuera de alcance de este refactor

- Generación de imágenes con OpenAI a partir de texto (`magia.php` en el
  proyecto anterior) — no está conectado al flujo de WhatsApp, quedó afuera.
- Deploy a producción — este repo es el código nuevo; el deploy es un paso
  posterior.
