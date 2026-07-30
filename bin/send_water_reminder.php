<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use NutriHelper\Config;
use NutriHelper\Db\Database;
use NutriHelper\Domain\WaterReminderScheduler;
use NutriHelper\Http\GreenApiClient;
use NutriHelper\Repository\PersonaRepository;

Config::load(__DIR__ . '/../.env');

// Meant to run once per hour via cron; only actually sends anything during
// each person's scheduled hour(s) inside the 9-20hs window.
$currentHour = (int)(new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('G');

if ($currentHour < WaterReminderScheduler::WINDOW_START_HOUR || $currentHour > WaterReminderScheduler::WINDOW_END_HOUR) {
    echo json_encode(['ok' => true, 'sent' => 0, 'reason' => 'fuera del horario 9-20hs'], JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit;
}

const FRASES = [
    '¡Toma agua y seguí brillando!', 'Un vaso de agua y a seguir.', 'La hidratación es poder.',
    'Un sorbo para tu bienestar.', 'Refrescá tu cuerpo, ¡agua ya!', 'El agua es tu mejor amiga.',
    '¡Hora de hidratarse!', 'Tu cuerpo pide agua, escúchalo.', 'Un trago y más energía.',
    'Tu piel te agradecerá ese vaso.', 'Agua: tu motor natural.', '¡Brindá con agua por tu salud!',
    'La sed es solo el principio.', 'Recargá pilas con agua fresca.', 'La magia está en hidratarte.',
    '¡Bebé agua antes que la sed llegue!', 'Refrescá tu mente con agua.', 'Un vaso de agua, una pausa sana.',
    'El secreto: ¡mucha agua!', 'El agua te renueva.', '¡Sacia tu sed ahora!',
    'Pequeños sorbos, grandes beneficios.', 'El agua equilibra todo.', 'No esperes: hidrátate ya.',
    'Un vaso de agua: simple y vital.', 'El agua siempre es buena idea.', 'Tu cuerpo ama el agua.',
    'Un descanso, un vaso.', '¡Toma agua, cuida tu salud!', 'La frescura está en el agua.',
    'Hidratarte es quererte.', 'Tomar agua, el mejor hábito.', 'Un vaso de agua y respirá.',
    'La pureza que necesitás.', 'El agua es vida, bebela.', '¡Tomá un vaso, te lo merecés!',
    'Siempre es buen momento para agua.', 'Agua: energía natural.', '¡Rehidratate ahora!',
    'Nada como el agua fría.', 'Un vaso y sentís la diferencia.', 'Agua clara, mente clara.',
    'Un break con agua es salud.', '¡Un sorbo cada hora!', 'El agua recarga tu ánimo.',
    'Tomá agua y sonreí.', 'Más agua, más bienestar.', 'Tu cuerpo es 70% agua, cuídalo.',
    'Hidratate, tu salud lo pide.', 'El agua limpia y revitaliza.', 'Tu mejor aliado: agua.',
    'El agua te pone en marcha.', '¡Un vaso antes de seguir!', 'La vida fluye, igual que el agua.',
    'Un vaso y seguís fuerte.', 'Nada supera al agua pura.', '¡Un trago para tu energía!',
    'Agua fresca = mente despierta.', 'Pequeños sorbos, gran impacto.', '¡Toma agua, tu cuerpo lo pide!',
    'Un vaso a tiempo, salud segura.', 'La hidratación no se negocia.', 'Hidratate sin excusas.',
    'El agua equilibra tu día.', 'Un vaso, mil beneficios.', 'El secreto del enfoque: agua.',
    'Hidratación: tu superpoder.', 'Un vaso antes del café.', '¡No olvides tu botella!',
    'El agua da claridad.', 'Bebé agua, viví mejor.', '¡Tu cuerpo celebra el agua!',
    'Agua primero, todo lo demás después.', 'La mejor pausa: agua.', '¡Hora del vaso mágico!',
    'Sorbitos que suman salud.', 'El agua calma y revitaliza.', '¡El agua es tu combustible!',
    'Tomá agua, mantenete fresco.', '¡Un vaso de agua ahora mismo!', 'La mejor inversión: hidratarte.',
    'El agua: tu gran aliada.', 'No hay excusas para no hidratarse.', 'Un sorbo y todo mejora.',
    '¡Agua, simple y poderosa!', 'El agua refresca tu alma.', 'Tomá agua, cuidá tu cuerpo.',
    'Un vaso y ganás energía.', '¡Hidratate y seguí!', 'El agua nutre cada célula.',
];

const EMOJIS = ['💧', '💦', '🌊', '🚰', '🫗', '🥤', '🧊', '⛲'];

function pickIndex(int $maxExclusive): int
{
    return random_int(0, $maxExclusive - 1);
}

function normalizeName(?string $name): string
{
    $name = trim((string)$name);
    return $name === '' ? '' : mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
}

function formatMessage(string $frase, string $nombre, string $emoji): string
{
    if ($nombre === '') {
        return rtrim($frase) . ' ' . $emoji;
    }

    if ((bool)random_int(0, 1)) {
        return '¡' . $nombre . '! ' . rtrim($frase) . ' ' . $emoji;
    }

    return rtrim($frase, " \t\n\r\0\x0B.,¡!¿?") . ', ' . $nombre . '. ' . $emoji;
}

$lastEmojiFile = sys_get_temp_dir() . '/nutri_helper_ultimo_emoji_hidratacion.txt';
$lastEmoji = is_file($lastEmojiFile) ? trim((string)@file_get_contents($lastEmojiFile)) : '';

$frase = FRASES[pickIndex(count(FRASES))];

$emoji = '';
for ($i = 0; $i < 10; $i++) {
    $candidate = EMOJIS[pickIndex(count(EMOJIS))];
    if ($candidate !== $lastEmoji) {
        $emoji = $candidate;
        break;
    }
}
if ($emoji === '') {
    $emoji = EMOJIS[pickIndex(count(EMOJIS))];
}
@file_put_contents($lastEmojiFile, $emoji);

$greenApi = new GreenApiClient(Config::greenApi());
$personas = new PersonaRepository(Database::connect(Config::database()));

$recipients = array_filter(
    $personas->findWaterReminderRecipients(),
    static fn (array $r) => WaterReminderScheduler::isScheduledHour((int)$r['water_frequency'], $currentHour)
);

$results = [];
foreach ($recipients as $recipient) {
    $number = PersonaRepository::normalizePhone((string)$recipient['number']);
    if ($number === '') {
        continue;
    }
    $chatId = str_ends_with($number, '@c.us') ? $number : $number . '@c.us';
    $nombre = normalizeName((string)($recipient['shortname'] ?? ''));
    $mensaje = formatMessage($frase, $nombre, $emoji);

    $result = $greenApi->sendMessage($chatId, $mensaje);

    error_log(sprintf(
        '[AGUA] %s | to=%s | status=%s | error=%s | message="%s"',
        date('c'),
        $chatId,
        (string)$result['status'],
        $result['error'] ?? 'none',
        $mensaje
    ));

    $results[] = ['to' => $chatId, 'status' => $result['status'], 'error' => $result['error'], 'message' => $mensaje];
}

echo json_encode(['ok' => true, 'sent' => count($results), 'results' => $results], JSON_UNESCAPED_UNICODE), PHP_EOL;
