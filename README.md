# Nutri Helper

Bot de WhatsApp sobre Green API: recibe fotos de comidas, las analiza con OpenAI
(calorías/proteínas/carbohidratos/grasas), guarda el historial por usuario, y
ofrece un recordatorio de agua opt-in. PHP puro, sin framework ni dependencias
de Composer.

## Estructura

```
bin/send_water_reminder.php   # script de cron (correr cada hora): envía el recordatorio de agua
                               # a quienes tengan water_frequency seteado, solo en su hora programada
bin/send_daily_summary.php    # script de cron (correr cada hora): a la hora configurada, manda el
                               # resumen diario (una sola llamada a OpenAI por persona) y consejo para mañana
bin/send_meal_reminder.php    # script de cron (correr cada hora): pregunta por poll si te olvidaste
                               # de una comida, según tu hora habitual de esa comida
public/webhook.php            # webhook de Green API (onboarding, comandos de texto, análisis de imagen)
public/index.php              # front door del sitio (?identifier=XXXX): historial real o landing promocional
public/photos/                # fotos servidas públicamente (mismo esquema que producción), gitignored
src/Config.php                # loader de .env, sin fallback hardcodeado
src/autoload.php              # autoload PSR-4 manual (namespace NutriHelper\)
src/Http/GreenApiClient.php   # sendMessage / GetContactInfo / downloadFile / sendPoll
src/Http/OpenAiClient.php     # análisis nutricional vía Responses API
src/Db/Database.php           # conexión PDO
src/Repository/               # PersonaRepository, NutritionRepository
src/Domain/                   # normalización de payload, ruteo de mensajes, parser de análisis, storage de imágenes, dedupe, scheduler de agua
src/View/LandingRenderer.php  # historial (cards/filtros/JS) + landing promocional — porteado 1:1 del index.php real
storage/locks/                # dedupe de eventos de webhook (gitignored)
logs/                         # logs de la app (gitignored)
```

## Setup

1. `cp .env.example .env` y completar:
   - `GREEN_API_URL`, `GREEN_API_INSTANCE_ID`, `GREEN_API_TOKEN`
   - `OPENAI_API_KEY`
   - `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`
   - `NUTRI_IMAGE_DIR` → ruta absoluta a `public/photos` (debe ser servida
     públicamente por el webserver, igual que en producción: las fotos se ven
     por URL directa)
   - `NUTRI_IMAGE_PUBLIC_PATH` (default `/photos`)
   - `NUTRI_LANDING_BASE_URL` (dominio público donde vive `public/index.php`)
   - `NUTRI_BOT_WHATSAPP_LINK` (link `https://wa.me/...` mostrado en la
     landing promocional)
2. Crear las tablas (ver esquema abajo).
3. Apuntar el servidor web (nginx/Apache) a `public/` como document root,
   con `index.php` como índice por defecto — `public/index.php` es el punto
   de entrada del sitio (`/` y `/?identifier=XXXX`).
4. Configurar el webhook de Green API para que apunte a
   `https://tu-dominio/webhook.php` (notificaciones de mensajes entrantes,
   incluye `incomingMessageReceived` para texto/imagen/voto de encuesta).
5. Programar `bin/send_water_reminder.php` en cron **cada hora en punto**:
   `0 * * * * php /ruta/a/nutri-helper/bin/send_water_reminder.php`.
   El script decide internamente si corresponde enviar algo en esa hora.
6. Programar `bin/send_daily_summary.php` también cada hora (mismo patrón):
   `0 * * * * php /ruta/a/nutri-helper/bin/send_daily_summary.php`.
   Solo manda algo cuando la hora local coincide con `NUTRI_DAILY_SUMMARY_HOUR`
   (default 22hs), y como máximo una vez por día aunque el cron se dispare
   varias veces esa hora.
7. Programar `bin/send_meal_reminder.php` también cada hora:
   `0 * * * * php /ruta/a/nutri-helper/bin/send_meal_reminder.php`.

## Recordatorio de comida olvidada

Cada hora, `bin/send_meal_reminder.php` recorre las 4 comidas (desayuno,
almuerzo, merienda, cena) de cada persona activa **que haya registrado al
menos una comida AYER** (`NutritionRepository::hadEntriesYesterday`) — si no
usó el bot el día anterior, no se lo persigue con recordatorios. Para cada
comida de las personas que sí pasan ese filtro:

1. Si la hora actual está fuera de la ventana propia de esa comida
   (desayuno 8-12hs, almuerzo 12-15hs, merienda 15-19hs, cena 19-24hs), no
   hace nada — nunca pregunta por el desayuno a la noche, por ejemplo.
2. Si ya registró esa comida hoy (columna `nutri.comida`, no inferida por
   hora — así una comida cargada tarde por texto no se confunde con otra),
   no pregunta.
3. Si no, calcula la "hora objetivo" para esa persona y esa comida:
   el promedio histórico de a qué hora la registra (solo con fotos, últimos
   30 registros — `NutritionRepository::findAverageMealHour`), o un default
   razonable (ej. 9hs para desayuno) si todavía no tiene historial.
4. Cuando la hora actual coincide con esa hora objetivo, manda **un solo**
   poll ese día para esa comida (dedupe diario por identifier+comida):
   *"¿Te olvidaste de mandar tu comida?"* con 3 opciones:
   - **No comí**
   - **Me salteé este/a \<comida\>** (con concordancia de género correcta)
   - **Comí, me olvidé de mandar la foto**

Si contesta que comió pero se olvidó de mandar la foto, el bot le pide que
cuente por texto qué comió (`persona.pending_text_meal`), y ese texto se
procesa igual que una foto: **una llamada a OpenAI sin imagen**
(`OpenAiClient::analyzeMealFromText`, mismo prompt que el de foto pero
aclarando que el consejo se basa en la descripción, no en una imagen) —
calcula calorías/proteínas/carbohidratos/grasas y el consejo, y lo guarda
con `foto = ''`. En `public/index.php` esas entradas se muestran igual que
las demás (con su tema de color por comida, usando `nutri.comida` en vez de
inferir por hora) pero sin `<img>`, con un aviso de "cargado por texto".

## Resumen de fin de día

A la hora configurada (`NUTRI_DAILY_SUMMARY_HOUR`), para cada persona que
registró al menos una comida hoy:
1. Se traen las comidas de hoy (`nutri_today` por identifier), incluyendo el
   `consejo_actual` que se le dio en el momento de cada una (por eso ahora se
   guarda ese consejo en la tabla `nutri`, antes solo se enviaba por WhatsApp
   y se perdía).
2. Se calculan los totales del día en PHP (no se le pide a OpenAI que sume).
3. **Una única llamada a OpenAI** (`OpenAiClient::analyzeDaySummary`, mismo
   estilo de prompt que el de "consejo próxima comida" por foto, pero a nivel
   día completo) devuelve un resumen del día y un consejo concreto para
   mañana — no comida por comida, sino la jornada en conjunto.
4. Se arma un solo mensaje de WhatsApp: resumen + totales (calculados por
   nosotros, no por el modelo) + consejo para mañana.

## Historial público (`public/index.php`)

Porteado 1:1 del `index.php` real de producción (mismo HTML/CSS/JS: filtros
por comida, "últimas 2 semanas", orden asc/desc, separadores de día,
scroll-snap, contador de registros), pero ahora usando los repositorios PDO
del proyecto en vez de mysqli suelto.

Con `?identifier=XXXX`:
- Si el identifier no existe, o existe pero no tiene comidas registradas
  todavía → landing promocional con botón "Ir al bot" (`NUTRI_BOT_WHATSAPP_LINK`).
- Si tiene comidas → historial completo con cards, una por comida, con foto,
  descripción y macros.

Sin `identifier` → landing promocional directamente (mismo comportamiento
que el sitio real).

## Recordatorio de agua (encuesta de frecuencia)

Cuando el usuario escribe "agua", el bot le manda una encuesta de WhatsApp
(`sendPoll`) con opciones del 3 al 12 ("¿cuántas veces por día?"). Al votar,
Green API notifica el webhook con `typeMessage: "pollUpdateMessage"`; se lee
la opción elegida desde `messageData.pollMessageData.votes[].optionName`
(matcheando `optionVoters` contra el chatId) y se guarda en
`persona.water_frequency`.

`bin/send_water_reminder.php` está pensado para correr **una vez por hora**.
En cada corrida:
1. Si la hora actual (huso horario `America/Argentina/Buenos_Aires`) está
   fuera de la ventana 8-20hs, no hace nada.
2. Para cada persona con `water_frequency` seteado (3-12), calcula —de forma
   determinística, sin guardar un horario— en qué horas del día le
   corresponde un recordatorio (`WaterReminderScheduler`, que reparte N
   avisos lo más parejo posible dentro de la ventana de 12hs) y solo le
   envía el mensaje si la hora actual es una de esas.

Por ejemplo, frecuencia 3 → recordatorios a las 8, 12 y 16hs; frecuencia 6 →
8, 10, 12, 14, 16 y 18hs; frecuencia 12 → una vez por hora, de 8 a 19hs.

## Onboarding guiado (edad y peso por encuesta)

La primera vez que un número le escribe al bot (`persona` no existía), en vez
de un único mensaje de bienvenida ahora es un flujo de 3 pasos, reusando la
misma lógica de alta de `persona` que ya existía:

1. Se crea la fila en `persona` (con `onboarding_step = 'awaiting_age'`) y se
   manda un saludo corto + la encuesta de rango de edad.
2. Al votar, se guarda `age_range`, se avanza a `onboarding_step =
   'awaiting_weight'` y se manda la encuesta de rango de peso.
3. Al votar esa, se guarda `weight_range`, `onboarding_step` pasa a `'done'`,
   y recién ahí se manda el mensaje de instrucciones (foto de las comidas, etc.).

Mientras `onboarding_step` no es `'done'`, cualquier mensaje que no sea el
voto esperado (texto, foto, lo que sea) se ignora y se le reenvía la encuesta
pendiente — no puede saltarse el paso. Los números que ya existían antes de
esta feature quedan con `onboarding_step = 'done'` por default de columna, así
que no se les interrumpe nada.

Igual que con el agua, cada encuesta se resuelve mirando
`persona.onboarding_step`: si no está en `'done'`, cualquier voto de encuesta
se interpreta como edad o peso según corresponda; recién cuando el onboarding
terminó, un voto de encuesta se interpreta como frecuencia de agua.

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
    water_frequency SMALLINT UNSIGNED NULL DEFAULT NULL, -- NULL/0 = recordatorio de agua apagado; 3-12 = veces por día
    age_range       VARCHAR(16) NULL DEFAULT NULL,        -- ej. "26-35" (ver MessageRouter::AGE_RANGE_OPTIONS)
    weight_range    VARCHAR(16) NULL DEFAULT NULL,        -- ej. "70-80kg" (ver MessageRouter::WEIGHT_RANGE_OPTIONS)
    onboarding_step VARCHAR(24) NOT NULL DEFAULT 'done',   -- 'awaiting_age' | 'awaiting_weight' | 'done'
    pending_meal_reminder VARCHAR(16) NULL DEFAULT NULL,   -- comida cuyo poll "¿te olvidaste?" está pendiente de respuesta
    pending_text_meal     VARCHAR(16) NULL DEFAULT NULL,   -- comida que va a describir por texto (eligió "comí pero me olvidé")
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
    consejo_actual       TEXT NULL,                        -- consejo dado en el momento de esa comida; insumo del resumen diario
    comida               VARCHAR(16) NULL DEFAULT NULL,     -- DESAYUNO|ALMUERZO|MERIENDA|CENA; explícito (no inferido por hora)
    PRIMARY KEY (id),
    KEY idx_identifier_datetime (identifier, datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Si ya tenías una tabla `persona` con la columna booleana `setting` (versión
anterior), migrala así:

```sql
ALTER TABLE persona CHANGE setting water_frequency SMALLINT UNSIGNED NULL DEFAULT NULL;
UPDATE persona SET water_frequency = NULL WHERE water_frequency = 0;

ALTER TABLE persona
    ADD COLUMN age_range VARCHAR(16) NULL DEFAULT NULL,
    ADD COLUMN weight_range VARCHAR(16) NULL DEFAULT NULL,
    ADD COLUMN onboarding_step VARCHAR(24) NOT NULL DEFAULT 'done';
-- El default 'done' es a propósito: las personas que ya existían no deben
-- quedar interrumpidas pidiéndoles edad/peso retroactivamente.

ALTER TABLE persona
    ADD COLUMN pending_meal_reminder VARCHAR(16) NULL DEFAULT NULL,
    ADD COLUMN pending_text_meal VARCHAR(16) NULL DEFAULT NULL;

ALTER TABLE nutri
    ADD COLUMN consejo_actual TEXT NULL,
    ADD COLUMN comida VARCHAR(16) NULL DEFAULT NULL;
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
