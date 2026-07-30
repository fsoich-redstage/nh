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
                               # resumen diario (una sola llamada a OpenAI por persona), consejo para
                               # mañana y un chart de macros del día
bin/send_meal_reminder.php    # script de cron (correr cada hora): pregunta con botones si te olvidaste
                               # de una comida, según tu hora habitual de esa comida
bin/send_monday_kickoff.php   # script de cron (correr lunes de 8 a 12): envión de arranque de
                               # semana para quien registró comidas la semana pasada
bin/check_instance_health.php # script de cron (cada 5-15 min): alerta si la instancia de Green API
                               # se desconectó, ya que ahí todos los demás crons fallan en silencio
bin/backfill_persona_contact_info.php # script manual, una sola vez: re-completa name/shortname/foto/
                               # pushname desde Green API para personas que ya existían
webhook.php                   # webhook de Green API (onboarding, comandos de texto, análisis de imagen);
                               # requiere ?token=<GREEN_API_WEBHOOK_TOKEN> en la URL configurada
index.php                     # front door del sitio (?identifier=XXXX): historial real o landing promocional
photos/                       # fotos servidas públicamente (mismo esquema que producción), gitignored
admin/index.php                # panel admin: listado de todos los usuarios + stats (protegido, HTTP Basic Auth)
admin/persona.php              # panel admin: detalle de un usuario (contacto completo + todas sus comidas/fotos)
src/Config.php                # loader de .env, sin fallback hardcodeado
src/autoload.php              # autoload PSR-4 manual (namespace NutriHelper\)
src/Http/GreenApiClient.php   # sendMessage / GetContactInfo / downloadFile / sendInteractiveButtons / sendListMessage
src/Http/OpenAiClient.php     # análisis nutricional vía Responses API
src/Http/AdminAuth.php        # guard de HTTP Basic Auth para admin/
src/Db/Database.php           # conexión PDO
src/Repository/               # PersonaRepository, NutritionRepository
src/Domain/                   # normalización de payload, ruteo de mensajes, parser de análisis, storage de imágenes, dedupe, scheduler de agua
src/Domain/DayChartRenderer.php # chart PNG (GD, sin dependencias) de macros del día para el resumen diario
src/View/LandingRenderer.php  # historial (cards/filtros/JS) + landing promocional — porteado 1:1 del index.php real
src/View/AdminRenderer.php    # listado de usuarios + detalle con foto/macros/consejo para el panel admin
storage/locks/                # dedupe de eventos de webhook (gitignored)
logs/                         # logs de la app (gitignored), rotan solos pasados ~5MB
```

## Setup

1. `cp .env.example .env` y completar:
   - `GREEN_API_URL`, `GREEN_API_INSTANCE_ID`, `GREEN_API_TOKEN`
   - `GREEN_API_WEBHOOK_TOKEN` → generar un secreto (ej. `openssl rand -hex 24`);
     el webhook rechaza cualquier request sin este token
   - `OPENAI_API_KEY`
   - `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`
   - `NUTRI_IMAGE_DIR` → ruta absoluta a `photos/` (debe ser servida
     públicamente por el webserver, igual que en producción: las fotos se ven
     por URL directa; también se usa para los charts del resumen diario)
   - `NUTRI_IMAGE_PUBLIC_PATH` (default `/photos`)
   - `NUTRI_LANDING_BASE_URL` (dominio público donde vive `index.php`)
   - `NUTRI_BOT_WHATSAPP_LINK` (link `https://wa.me/...` mostrado en la
     landing promocional)
   - `NUTRI_HEALTH_ALERT_WEBHOOK_URL` (opcional) → webhook de Slack/Discord/etc.
     notificado si la instancia de Green API se desconecta
2. Crear las tablas (ver esquema abajo).
3. Apuntar el servidor web (nginx/Apache/hosting compartido) a la raíz del
   proyecto como document root, con `index.php` como índice por defecto —
   `index.php` es el punto de entrada del sitio (`/` y `/?identifier=XXXX`).
4. Configurar el webhook de Green API para que apunte a
   `https://tu-dominio/webhook.php?token=<GREEN_API_WEBHOOK_TOKEN>` (notificaciones
   de mensajes entrantes, incluye `incomingMessageReceived` para texto/imagen/voto
   de lista/botón interactivo). **El `?token=` es obligatorio** — sin autenticación, cualquiera que
   conozca la URL podría inyectar mensajes falsos a nombre de cualquier número.
5. Programar `bin/check_instance_health.php` cada 5-15 minutos:
   `*/10 * * * * php /ruta/a/nutri-helper/bin/check_instance_health.php`.
   Alerta (log + webhook opcional) si la sesión de WhatsApp se desconectó, que es
   silenciosa para todos los demás crons.
6. Programar `bin/send_water_reminder.php` en cron **cada hora en punto**:
   `0 * * * * php /ruta/a/nutri-helper/bin/send_water_reminder.php`.
   El script decide internamente si corresponde enviar algo en esa hora.
7. Programar `bin/send_daily_summary.php` también cada hora (mismo patrón):
   `0 * * * * php /ruta/a/nutri-helper/bin/send_daily_summary.php`.
   Solo manda algo cuando la hora local coincide con `NUTRI_DAILY_SUMMARY_HOUR`
   (default 22hs), y como máximo una vez por día aunque el cron se dispare
   varias veces esa hora.
8. Programar `bin/send_meal_reminder.php` también cada hora:
   `0 * * * * php /ruta/a/nutri-helper/bin/send_meal_reminder.php`.
9. Programar `bin/send_monday_kickoff.php` para correr **solo los lunes, de
   8 a 12hs** (coincide con la ventana de desayuno, que es cuando se manda el
   mensaje): `0 8-12 * * 1 php /ruta/a/nutri-helper/bin/send_monday_kickoff.php`.

## Envión de arranque de semana (lunes)

`bin/send_monday_kickoff.php` está pensado para un cron que corre **solo los
lunes de 8 a 12hs** (`0 8-12 * * 1`) — igual mantiene internamente el chequeo
de "¿es lunes?" (huso `America/Argentina/Buenos_Aires`) como resguardo, por si
el cron se dispara en otro momento. Para cada persona activa que haya
registrado al menos una comida **la semana pasada** (lunes a domingo anterior
— `NutritionRepository::hadEntriesLastWeek`),
manda un único mensaje invitándola a seguir cargando sus comidas esta semana,
programado a la hora en la que esa persona suele registrar su desayuno
(mismo cálculo de promedio histórico que usa el recordatorio de comidas,
redondeado; si no tiene historial de desayuno, usa el default de
`MealWindows::defaultHour('DESAYUNO')`). No se manda a quien no tuvo actividad
la semana pasada.

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
   razonable (ej. 9hs para desayuno) si todavía no tiene historial, y corre
   el recordatorio **una hora después** de esa referencia, sin salirse de la
   ventana de la comida.
4. Cuando la hora actual coincide con esa hora objetivo desplazada, manda **un solo**
   mensaje de botones interactivos (`sendInteractiveButtons`) ese día para
   esa comida (dedupe diario por identifier+comida):
   *"¿Te olvidaste de mandar tu comida?"* con 3 botones:
   - **No comí** (`buttonId: no_comi`)
   - **Me lo salteé** (`buttonId: salteado`)
   - **Sin foto** (`buttonId: sin_foto`)

   Los textos de los botones son cortos y genéricos a propósito (Green API
   limita `buttonText` a 25 caracteres) — el nombre de la comida va en el
   cuerpo del mensaje, no en el botón. Al tocar uno, Green API notifica el
   webhook con `typeMessage: "templateButtonReplyMessage"`; se lee
   `messageData.templateButtonReplyMessage.selectedId` y se matchea contra
   esos 3 `buttonId` (ver `MessageRouter::MEAL_REMINDER_*`).

Si contesta que comió pero se olvidó de mandar la foto, el bot le pide que
cuente por texto qué comió (`persona.pending_text_meal`), y ese texto se
procesa igual que una foto: **una llamada a OpenAI sin imagen**
(`OpenAiClient::analyzeMealFromText`, mismo prompt que el de foto pero
aclarando que el consejo se basa en la descripción, no en una imagen) —
calcula calorías/proteínas/carbohidratos/grasas y el consejo, y lo guarda
con `foto = ''`. En `index.php` esas entradas se muestran igual que
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

## Historial público (`index.php`)

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

## Recordatorio de agua (lista de frecuencia)

Cuando el usuario escribe "agua" (o lo tappea desde el menú), el bot le manda
una lista interactiva de WhatsApp (`sendListMessage`) con filas del 3 al 12
("¿cuántas veces por día?") y marca `persona.pending_water_poll = 1`. Al
elegir una fila, Green API notifica el webhook con
`typeMessage: "listResponseMessage"`; se lee la fila elegida desde
`messageData.listResponseMessage.singleSelectReply` y se guarda en
`persona.water_frequency`. `pending_water_poll` se limpia apenas se procesa
la respuesta (o si mientras tanto se dispara cualquier otro comando), para
que una lista de agua nunca contestada no quede "esperando" un tap que en
realidad era para otra cosa.

`bin/send_water_reminder.php` está pensado para correr **una vez por hora**.
En cada corrida:
1. Si la hora actual (huso horario `America/Argentina/Buenos_Aires`) está
   fuera de la ventana 9-20hs, no hace nada.
2. Para cada persona con `water_frequency` seteado (3-12), calcula —de forma
   determinística, sin guardar un horario— en qué horas del día le
   corresponde un recordatorio (`WaterReminderScheduler`, que reparte N
   avisos lo más parejo posible dentro de la ventana de 12hs) y solo le
   envía el mensaje si la hora actual es una de esas.

Por ejemplo, frecuencia 3 → recordatorios a las 9, 13 y 17hs; frecuencia 6 →
9, 11, 13, 15, 17 y 19hs; frecuencia 12 → una vez por hora, de 9 a 20hs.

## Onboarding guiado (edad y peso por lista interactiva)

La primera vez que un número le escribe al bot (`persona` no existía), en vez
de un único mensaje de bienvenida ahora es un flujo de 3 pasos, reusando la
misma lógica de alta de `persona` que ya existía:

1. Se crea la fila en `persona` (con `onboarding_step = 'awaiting_age'`) y se
   manda un saludo corto + una lista interactiva (`sendListMessage`) con los
   rangos de edad.
2. Al elegir una fila (`typeMessage: "listResponseMessage"`, se lee
   `messageData.listResponseMessage.singleSelectReply`), se guarda
   `age_range`, se avanza a `onboarding_step = 'awaiting_weight'` y se manda
   la lista de rango de peso.
3. Al elegir esa, se guarda `weight_range`, `onboarding_step` pasa a
   `'done'`, y recién ahí se manda el mensaje de instrucciones (foto de las
   comidas, etc.).

Mientras `onboarding_step` no es `'done'`, cualquier mensaje que no sea la
selección esperada (texto, foto, lo que sea) se ignora y se le reenvía la
lista pendiente — no puede saltarse el paso. Los números que ya existían
antes de esta feature quedan con `onboarding_step = 'done'` por default de
columna, así que no se les interrumpe nada.

Igual que con el agua, cada lista se resuelve mirando
`persona.onboarding_step`: si no está en `'done'`, cualquier selección de
lista se interpreta como edad o peso según corresponda; recién cuando el
onboarding terminó, una selección de lista puede interpretarse como
frecuencia de agua (o un rowId del menú principal).

## Menú interactivo y comandos de texto

Cualquier mensaje de texto que no matchee un comando conocido (o directamente
"menu") dispara un menú interactivo (`sendListMessage` de Green API: un botón
que abre una lista de opciones tappeable) con "Ayuda", "Recordatorio de agua",
"Cargar comida atrasada" y "Borrar última comida". Si Green API rechaza el
`sendListMessage` (algunas combinaciones cliente/instancia no lo soportan), se
cae automáticamente a un mensaje de texto plano con las mismas opciones.

Comandos de texto soportados: `agua`, `ayuda`, `menu`, `borrar`, `cargar`.

- **`borrar`**: elimina el registro de comida más reciente cargado **hoy**
  para ese identifier (`NutritionRepository::deleteMostRecentEntryToday`) y
  confirma qué se borró. No hay confirmación de "¿estás seguro?" — pensado
  para corregir un error de carga inmediato, no como papelera de reciclaje.

## Cargar una comida de un día anterior (`cargar`)

Una foto o texto normal siempre se registra con la fecha/hora actual — para
una comida que te olvidaste de cargar en el momento, `cargar` arranca un flujo
de 3 pasos con `persona.pending_backdate_step`:

1. **`awaiting_day`**: se manda una lista interactiva "¿De qué día es la
   comida que querés cargar?" con 7 filas (Hoy, Ayer, Hace 2 días … Hace 6
   días — mismo horizonte que el historial semanal; el rowId es el offset en
   días). Al elegir una, se guarda la fecha elegida en
   `persona.pending_backdate_date` y se pasa a `awaiting_meal`.
2. **`awaiting_meal`**: se manda una lista "¿Qué comida fue?" (Desayuno /
   Almuerzo / Merienda / Cena; el rowId es la clave de `MealWindows`). Al
   elegir una, se guarda en `persona.pending_backdate_meal` y se pasa a
   `awaiting_content`.
3. **`awaiting_content`**: el próximo mensaje (foto o texto) se procesa igual
   que una carga normal — mismo análisis de OpenAI, mismo límite de 4 comidas
   por día (aplicado al día elegido, no a hoy) y el mismo chequeo de "esa
   comida ya está registrada ese día" — pero se guarda con una fecha/hora
   explícita en vez de `NOW()`: la fecha elegida, a la hora promedio histórica
   de esa persona para esa comida (`NutritionRepository::findAverageMealHour`,
   el mismo cálculo que usa `bin/send_meal_reminder.php`), o el default de
   `MealWindows::defaultHour()` si todavía no tiene historial — como la hora
   real en que comió no es un dato que se pregunta, "ahora" sería incorrecto
   para un registro retroactivo.

El flujo se puede interrumpir en cualquier punto simplemente ignorando la
lista/mensaje pendiente — no hay timeout ni opción de "cancelar" explícita,
pero iniciar `cargar` de nuevo (o cualquier otro comando) pisa el estado
anterior sin dejar el bot trabado.

## Datos de contacto en el onboarding (`persona.foto`, `pushname`)

`GreenApiClient::getContactInfo()` ya traía `name`, `shortName`, `pushname` y
`profilePicUrl` (el campo `avatar` de Green API), pero el onboarding tenía un
bug: guardaba el `pushname` en la columna `foto`, y el `profilePicUrl` (la
foto de perfil real) no se guardaba en ningún lado. Ahora:

- `persona.foto` guarda `profilePicUrl` (la URL de la foto de perfil de
  WhatsApp), como corresponde al nombre de la columna.
- `persona.pushname` (columna nueva) guarda el `pushname` — el nombre que la
  propia persona eligió mostrar, útil como fallback cuando `name`/`shortName`
  vienen vacíos.
- `PersonaRepository::getOrCreateIdentifier()` ahora recibe el array de
  contacto completo (`$contact`) en vez de parámetros posicionales sueltos,
  para no repetir este tipo de mezcla de campos a futuro.

Para las personas que ya se registraron antes de este fix (`foto` con un
pushname adentro en vez de una URL, y sin `pushname` guardado en ningún lado),
correr una vez:

```bash
php bin/backfill_persona_contact_info.php
```

Re-consulta `GetContactInfo` para cada persona existente y completa
`name`/`shortname`/`foto`/`pushname` con lo que Green API devuelva — solo
pisa un campo cuando Green API trae un valor no vacío para él, así que nunca
borra un dato bueno que ya tenías por una respuesta parcial. Es idempotente:
correrlo de nuevo no rompe nada, solo vuelve a aplicar los mismos datos.
`GetContactInfo` tiene rate limit por instancia, así que el script hace una
pausa corta (300ms) entre personas.

## Panel de administración (`admin/`)

Panel de solo lectura para vos: listado de todos los usuarios con sus stats
(`admin/index.php`) y, por cada uno, su info de contacto completa más
**todas** las comidas que cargó, con las fotos (`admin/persona.php?identifier=XXXX`).

**Setup:**

1. Generar un hash de contraseña:
   ```bash
   php -r "echo password_hash('tu-password-elegida', PASSWORD_DEFAULT);"
   ```
2. Completar en `.env`:
   ```
   ADMIN_USERNAME=fede
   ADMIN_PASSWORD_HASH=<el hash generado arriba>
   ```
3. Entrar a `https://tu-dominio/admin/` — el navegador va a pedir usuario/contraseña
   (HTTP Basic Auth vía `src/Http/AdminAuth.php`). Sin `ADMIN_USERNAME`/
   `ADMIN_PASSWORD_HASH` configurados, el panel devuelve 500 en vez de quedar
   abierto sin auth.

**Importante si el deploy es nginx + PHP-FPM** (no Apache/mod_php): PHP-FPM no
recibe el header `Authorization` por defecto, así que `PHP_AUTH_USER`/
`PHP_AUTH_PW` llegan vacíos y el panel rechaza *cualquier* credencial. Agregar
al bloque `location` que sirve PHP:
```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

**Qué expone:** número de teléfono real, nombre/shortname/pushname, foto de
perfil, edad/peso/frecuencia de agua declarados, y el historial completo de
comidas con sus fotos — de **todos** los usuarios a la vez. Es el único punto
del proyecto con ese nivel de acceso, así que:
- Nunca lo dejes servido por HTTP plano (solo HTTPS) — Basic Auth manda la
  contraseña en cada request, prácticamente en texto plano sin TLS.
- La contraseña vive solo como hash en `.env` (nunca en el repo); rotarla es
  generar un hash nuevo y pisar `ADMIN_PASSWORD_HASH`.

## Esquema SQL

Inferido del código original — ajustar tipos/tamaños si tu DB real difiere.

```sql
CREATE TABLE persona (
    number     BIGINT UNSIGNED NOT NULL,
    name       VARCHAR(191) NOT NULL DEFAULT '',
    shortname  VARCHAR(191) NOT NULL DEFAULT '',
    pushname   VARCHAR(191) NOT NULL DEFAULT '',   -- nombre elegido por la persona (Green API GetContactInfo)
    foto       VARCHAR(191) NOT NULL DEFAULT '',   -- profilePicUrl (Green API GetContactInfo), NO el pushname
    identifier VARCHAR(16)  NOT NULL,
    tipo       VARCHAR(32)  NOT NULL DEFAULT 'default',
    campo1     TINYINT(1)   NOT NULL DEFAULT 0,
    campo2     TINYINT(1)   NOT NULL DEFAULT 0,
    water_frequency SMALLINT UNSIGNED NULL DEFAULT NULL, -- NULL/0 = recordatorio de agua apagado; 3-12 = veces por día
    age_range       VARCHAR(16) NULL DEFAULT NULL,        -- ej. "26-35" (ver MessageRouter::AGE_RANGE_OPTIONS)
    weight_range    VARCHAR(16) NULL DEFAULT NULL,        -- ej. "70-80kg" (ver MessageRouter::WEIGHT_RANGE_OPTIONS)
    onboarding_step VARCHAR(24) NOT NULL DEFAULT 'done',   -- 'awaiting_age' | 'awaiting_weight' | 'done'
    pending_meal_reminder VARCHAR(16) NULL DEFAULT NULL,   -- comida cuyo recordatorio "¿te olvidaste?" (botones) está pendiente de respuesta
    pending_water_poll    TINYINT(1)  NOT NULL DEFAULT 0,   -- 1 mientras la lista de frecuencia de agua está pendiente de respuesta
    pending_text_meal     VARCHAR(16) NULL DEFAULT NULL,   -- comida que va a describir por texto (eligió "comí pero me olvidé")
    pending_backdate_step VARCHAR(24) NULL DEFAULT NULL,   -- 'awaiting_day' | 'awaiting_meal' | 'awaiting_content' (flujo "cargar")
    pending_backdate_date DATE        NULL DEFAULT NULL,   -- día elegido en el flujo "cargar", mientras está en curso
    pending_backdate_meal VARCHAR(16) NULL DEFAULT NULL,   -- comida elegida en el flujo "cargar", mientras está en curso
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

ALTER TABLE persona
    ADD COLUMN pending_backdate_step VARCHAR(24) NULL DEFAULT NULL,
    ADD COLUMN pending_backdate_date DATE NULL DEFAULT NULL,
    ADD COLUMN pending_backdate_meal VARCHAR(16) NULL DEFAULT NULL;

ALTER TABLE persona
    ADD COLUMN pushname VARCHAR(191) NOT NULL DEFAULT '';
-- Después de agregar esta columna, correr php bin/backfill_persona_contact_info.php
-- una vez para completarla (y corregir `foto`) para las personas ya existentes.

ALTER TABLE nutri
    ADD COLUMN consejo_actual TEXT NULL,
    ADD COLUMN comida VARCHAR(16) NULL DEFAULT NULL;

ALTER TABLE persona
    ADD COLUMN pending_water_poll TINYINT(1) NOT NULL DEFAULT 0;
-- Necesaria para la migración de encuestas (sendPoll) a listas/botones
-- interactivos: a diferencia del resto de los flujos "pendientes", una
-- respuesta de la lista de frecuencia de agua no trae más dato que un
-- número suelto, así que sin esta bandera no habría forma de distinguirla
-- de cualquier otro tap de lista.
```

## Seguridad — pendiente antes de producción

El proyecto anterior (en `~/Desktop/nutri` y archivos sueltos) tenía
hardcodeados en texto plano: el token de Green API, la password de MySQL y una
API key de OpenAI. **Esas credenciales quedaron expuestas y hay que rotarlas**
(generar nuevas en Green API, OpenAI y MySQL) antes de considerar este
proyecto listo para producción. Este repo nuevo no tiene ningún fallback
hardcodeado — si falta una variable en `.env`, tira una excepción en vez de
usar un valor por defecto, así que rotarlas después es solo cambiar `.env`.

Ya resuelto en este repo:
- **Autenticación del webhook**: `webhook.php` exige `?token=` matcheando
  `GREEN_API_WEBHOOK_TOKEN` (comparación `hash_equals`, sin fallback). Antes
  cualquiera que descubriera la URL podía inyectar mensajes/fotos/votos falsos
  a nombre de cualquier `chatId`.
- **Chats grupales ignorados**: un `chatId` `@g.us` se descarta explícitamente
  antes de tocar `persona`/`nutri` (antes se procesaba como si fuera un número
  1:1, corrompiendo el registro de esa "persona").
- **Log del webhook acotado**: cada línea se trunca a ~4000 caracteres y el
  archivo rota (se renombra a `.log.1`) al superar ~5MB, en vez de crecer sin
  límite con datos de usuarios en texto plano.
- **Input de usuario saneado antes de ir al prompt de OpenAI**: la
  descripción/caption se recorta a 300 caracteres y se envuelve como dato
  citado, no como instrucción, para reducir el riesgo de que alguien intente
  hacer prompt injection contra su propio análisis nutricional.

Aun pendiente:
- Rate limiting en `webhook.php` (hoy nada impide un flood de requests
  autenticadas con un token filtrado, más allá del dedupe por idMessage).
- HTTPS/TLS termination y hardening del webserver quedan fuera de este repo
  (responsabilidad del deploy).

## Fuera de alcance de este refactor

- Generación de imágenes con OpenAI a partir de texto (`magia.php` en el
  proyecto anterior) — no está conectado al flujo de WhatsApp, quedó afuera.
- Deploy a producción — este repo es el código nuevo; el deploy es un paso
  posterior.
