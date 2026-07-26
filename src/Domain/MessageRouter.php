<?php
declare(strict_types=1);

namespace NutriHelper\Domain;

use NutriHelper\Http\GreenApiClient;
use NutriHelper\Http\OpenAiClient;
use NutriHelper\Repository\NutritionRepository;
use NutriHelper\Repository\PersonaRepository;

/**
 * Decides what to do with an incoming message: onboard a new number (a short
 * guided flow — age range, then weight range, both via WhatsApp polls — before
 * anything else is available), run the meal-photo analysis, resolve a missed
 * -meal reminder poll and the optional text-only follow-up it can trigger,
 * trigger/resolve the water-reminder frequency poll, answer "ayuda", or fall
 * back to the default instructions message.
 */
final class MessageRouter
{
    private const MAX_MEALS_PER_DAY = 4;

    private const AGE_RANGE_OPTIONS = ['18-25', '26-35', '36-45', '46-55', '56-65', '65+'];
    private const WEIGHT_RANGE_OPTIONS = ['<60kg', '60-70kg', '70-80kg', '80-90kg', '90-100kg', '>100kg'];

    /**
     * "cargar" flow, step 1 (BACKDATE_AWAITING_DAY): poll option label ->
     * days-ago offset, oldest week covered by the Monday nudge/history page.
     */
    private const BACKDATE_DAY_OPTIONS = [
        'Hoy'          => 0,
        'Ayer'         => 1,
        'Hace 2 días'  => 2,
        'Hace 3 días'  => 3,
        'Hace 4 días'  => 4,
        'Hace 5 días'  => 5,
        'Hace 6 días'  => 6,
    ];

    /**
     * "cargar" flow, step 2 (BACKDATE_AWAITING_MEAL): poll option label ->
     * MealWindows meal-type key.
     */
    private const BACKDATE_MEAL_OPTIONS = [
        'Desayuno' => 'DESAYUNO',
        'Almuerzo' => 'ALMUERZO',
        'Merienda' => 'MERIENDA',
        'Cena'     => 'CENA',
    ];

    public function __construct(
        private readonly GreenApiClient $greenApi,
        private readonly OpenAiClient $openAi,
        private readonly PersonaRepository $personas,
        private readonly NutritionRepository $nutrition,
        private readonly NutritionAnalysisParser $parser,
        private readonly ImageStore $images,
        private readonly string $landingBaseUrl
    ) {
    }

    public function route(IncomingMessage $message): void
    {
        $existsResult = $this->personas->exists($message->chatId);

        if ($existsResult === null) {
            // Invalid chatId — nothing sane to do.
            return;
        }

        if ($existsResult === false) {
            $this->startOnboarding($message->chatId);
            return;
        }

        $onboardingStep = $this->personas->getOnboardingStep($message->chatId);

        if ($message->type === 'poll_vote') {
            if ($onboardingStep !== PersonaRepository::ONBOARDING_DONE) {
                $this->handleOnboardingPollVote($message, $onboardingStep);
                return;
            }

            $pendingMealReminder = $this->personas->getPendingMealReminder($message->chatId);
            if ($pendingMealReminder !== null) {
                $this->handleMissedMealPollVote($message, $pendingMealReminder);
                return;
            }

            $backdateStep = $this->personas->getPendingBackdateStep($message->chatId);
            if ($backdateStep === PersonaRepository::BACKDATE_AWAITING_DAY) {
                $this->handleBackdateDayPollVote($message);
                return;
            }
            if ($backdateStep === PersonaRepository::BACKDATE_AWAITING_MEAL) {
                $this->handleBackdateMealPollVote($message);
                return;
            }

            $this->handleWaterPollVote($message);
            return;
        }

        if ($onboardingStep !== PersonaRepository::ONBOARDING_DONE) {
            // Ignore whatever they sent and gently steer them back to the
            // pending onboarding question instead of processing it as a
            // normal command/photo.
            $this->resendOnboardingStep($message->chatId, $onboardingStep);
            return;
        }

        if ($message->type === 'text') {
            $pendingTextMeal = $this->personas->getPendingTextMeal($message->chatId);
            if ($pendingTextMeal !== null) {
                $this->handleTextMealEntry($message, $pendingTextMeal);
                return;
            }
        }

        if (
            ($message->type === 'text' || $message->type === 'image')
            && $this->personas->getPendingBackdateStep($message->chatId) === PersonaRepository::BACKDATE_AWAITING_CONTENT
        ) {
            $this->handleBackdateContentEntry($message);
            return;
        }

        if ($message->type === 'image') {
            $this->handleImage($message);
            return;
        }

        if ($message->type === 'text') {
            $this->handleText($message);
            return;
        }

        // Other message types (e.g. status updates) are ignored.
    }

    private function startOnboarding(string $chatId): void
    {
        $contact = $this->greenApi->getContactInfo($chatId) ?? [];

        $this->personas->getOrCreateIdentifier($chatId, $contact);

        $greeting = ($contact['shortName'] ?? '') !== '' ? $contact['shortName'] : ($contact['name'] ?? '');

        $this->greenApi->sendMessage(
            $chatId,
            '¡Bienvenido a NutriHelper' . ($greeting !== '' ? ' ' . $greeting : '') . '! 🙌 '
            . 'Antes de arrancar quiero conocerte un poco mejor, son solo dos preguntas rápidas.'
        );

        $this->greenApi->sendPoll($chatId, '🎂 ¿En qué rango de edad estás?', self::AGE_RANGE_OPTIONS, false);
    }

    private function handleOnboardingPollVote(IncomingMessage $message, string $step): void
    {
        if ($message->body === '') {
            // Empty vote (e.g. retracted selection) — nothing to persist yet.
            return;
        }

        if ($step === PersonaRepository::ONBOARDING_AWAITING_AGE) {
            if (!in_array($message->body, self::AGE_RANGE_OPTIONS, true)) {
                return;
            }

            $this->personas->setAgeRange($message->chatId, $message->body);
            $this->personas->setOnboardingStep($message->chatId, PersonaRepository::ONBOARDING_AWAITING_WEIGHT);

            $this->greenApi->sendPoll(
                $message->chatId,
                '💪 ¡Genial! ¿Y tu rango de peso aproximado?',
                self::WEIGHT_RANGE_OPTIONS,
                false
            );
            return;
        }

        if ($step === PersonaRepository::ONBOARDING_AWAITING_WEIGHT) {
            if (!in_array($message->body, self::WEIGHT_RANGE_OPTIONS, true)) {
                return;
            }

            $this->personas->setWeightRange($message->chatId, $message->body);
            $this->personas->setOnboardingStep($message->chatId, PersonaRepository::ONBOARDING_DONE);

            $this->greenApi->sendMessage($message->chatId, implode("\n", [
                '✅ ¡Listo, ya está todo configurado!',
                'Mandame una foto de tus 4 comidas del día y te devuelvo los datos clave.',
                'Escribí "menu" para ver todas las opciones (ayuda, agua, borrar).',
            ]));
        }
    }

    private function resendOnboardingStep(string $chatId, string $step): void
    {
        if ($step === PersonaRepository::ONBOARDING_AWAITING_AGE) {
            $this->greenApi->sendPoll($chatId, '🎂 ¿En qué rango de edad estás?', self::AGE_RANGE_OPTIONS, false);
            return;
        }

        if ($step === PersonaRepository::ONBOARDING_AWAITING_WEIGHT) {
            $this->greenApi->sendPoll(
                $chatId,
                '💪 ¿Y tu rango de peso aproximado?',
                self::WEIGHT_RANGE_OPTIONS,
                false
            );
        }
    }

    private function handleText(IncomingMessage $message): void
    {
        $body = mb_strtolower(trim($message->body), 'UTF-8');

        if ($body === 'agua') {
            $this->handleWaterPollTrigger($message->chatId);
            return;
        }

        if ($body === 'borrar') {
            $this->handleDeleteLastMeal($message->chatId);
            return;
        }

        if ($body === 'cargar') {
            $this->handleBackdateTrigger($message->chatId);
            return;
        }

        if ($body === 'ayuda') {
            $this->greenApi->sendMessage($message->chatId, implode("\n", [
                '📸 Consejo Nutri Helper:',
                '1. Asegurate de que la foto esté bien iluminada.',
                '2. Completa la descripcion.',
                '3. Enviá la imagen para un completo calculo nutricional.',
            ]));
            return;
        }

        if ($body === 'menu') {
            $this->sendMainMenu($message->chatId);
            return;
        }

        $this->sendMainMenu($message->chatId, '¡Hola! Soy Nutri Helper.');
    }

    /**
     * Interactive list menu (WhatsApp list UI) with a plain-text fallback for
     * instance/client combinations that reject sendListMessage.
     */
    private function sendMainMenu(string $chatId, string $intro = ''): void
    {
        $header = $intro !== '' ? $intro . ' ' : '';
        $message = $header . 'Mandame una foto de tus comidas para analizarlas, o elegí una opción:';

        $sections = [[
            'title' => 'Nutri Helper',
            'rows'  => [
                ['rowId' => 'ayuda',  'title' => '📸 Ayuda',              'description' => 'Tips para sacarle provecho al bot'],
                ['rowId' => 'agua',   'title' => '💧 Recordatorio de agua', 'description' => 'Elegí cuántas veces por día'],
                ['rowId' => 'cargar', 'title' => '🗓️ Cargar comida atrasada', 'description' => 'Registrá una comida de otro día'],
                ['rowId' => 'borrar', 'title' => '🗑️ Borrar última comida', 'description' => 'Deshace el último registro de hoy'],
            ],
        ]];

        $result = $this->greenApi->sendListMessage($chatId, $message, 'Ver opciones', $sections);

        if ($result['status'] >= 400) {
            $this->greenApi->sendMessage($chatId, implode("\n", [
                $message,
                '',
                'Escribí "ayuda", "agua", "cargar", "borrar" o "menu".',
            ]));
        }
    }

    private function handleDeleteLastMeal(string $chatId): void
    {
        $identifier = $this->personas->lookupIdentifier(PersonaRepository::normalizePhone($chatId));
        if ($identifier === null) {
            return;
        }

        $deleted = $this->nutrition->deleteMostRecentEntryToday($identifier);
        if ($deleted === null) {
            $this->greenApi->sendMessage($chatId, 'No encontré ninguna comida registrada hoy para borrar.');
            return;
        }

        $this->greenApi->sendMessage(
            $chatId,
            '🗑️ Listo, borré tu último registro de hoy: "' . $deleted['descripcion'] . '".'
        );
    }

    /**
     * Starts the "cargar" flow: a comida logged today only supports "now" as
     * its timestamp, so this lets someone register a meal they forgot to log
     * on a previous day — up to a week back, matching the history/weekly-nudge
     * horizon elsewhere in the bot.
     */
    private function handleBackdateTrigger(string $chatId): void
    {
        $this->personas->setPendingBackdate($chatId, PersonaRepository::BACKDATE_AWAITING_DAY);

        $this->greenApi->sendPoll(
            $chatId,
            '🗓️ ¿De qué día es la comida que querés cargar?',
            array_keys(self::BACKDATE_DAY_OPTIONS),
            false
        );
    }

    private function handleBackdateDayPollVote(IncomingMessage $message): void
    {
        if ($message->body === '' || !array_key_exists($message->body, self::BACKDATE_DAY_OPTIONS)) {
            return;
        }

        $offsetDays = self::BACKDATE_DAY_OPTIONS[$message->body];
        $tzLocal = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $date = (new \DateTime('today', $tzLocal))->modify("-{$offsetDays} days")->format('Y-m-d');

        $this->personas->setPendingBackdate($message->chatId, PersonaRepository::BACKDATE_AWAITING_MEAL, $date);

        $this->greenApi->sendPoll(
            $message->chatId,
            '🍽️ ¿Qué comida fue?',
            array_keys(self::BACKDATE_MEAL_OPTIONS),
            false
        );
    }

    private function handleBackdateMealPollVote(IncomingMessage $message): void
    {
        if ($message->body === '' || !array_key_exists($message->body, self::BACKDATE_MEAL_OPTIONS)) {
            return;
        }

        $date = $this->personas->getPendingBackdateDate($message->chatId);
        if ($date === null) {
            // Shouldn't happen (step implies a date was already stored), but
            // don't leave the user stuck if it does.
            $this->personas->setPendingBackdate($message->chatId, null);
            return;
        }

        $mealType = self::BACKDATE_MEAL_OPTIONS[$message->body];
        $this->personas->setPendingBackdate($message->chatId, PersonaRepository::BACKDATE_AWAITING_CONTENT, $date, $mealType);

        $this->greenApi->sendMessage(
            $message->chatId,
            '✍️ Dale, mandame la foto o contame por texto qué comiste ese día.'
        );
    }

    /**
     * The photo or text sent right after completing the day+meal polls above
     * — stored with an explicit past datetime instead of NOW(). The hour is
     * this identifier's historical average for that meal type (same logic
     * bin/send_meal_reminder.php uses to time its reminders), since the
     * actual time it happened isn't something we asked for and "now" would
     * be wrong for a backdated entry.
     */
    private function handleBackdateContentEntry(IncomingMessage $message): void
    {
        $chatId = $message->chatId;
        $date = $this->personas->getPendingBackdateDate($chatId);
        $mealType = $this->personas->getPendingBackdateMeal($chatId);
        $this->personas->setPendingBackdate($chatId, null);

        if ($date === null || $mealType === null) {
            return;
        }

        $identifier = $this->personas->ensurePerson($this->greenApi, $chatId);

        if ($this->nutrition->countEntriesForIdentifierOnDate($identifier, $date) >= self::MAX_MEALS_PER_DAY) {
            $this->greenApi->sendMessage(
                $chatId,
                '⚠️ Ese día ya tiene el máximo de ' . self::MAX_MEALS_PER_DAY . ' comidas registradas.'
            );
            return;
        }

        if ($this->nutrition->hasMealTypeOnDate($identifier, $mealType, $date)) {
            $this->greenApi->sendMessage(
                $chatId,
                '⚠️ Ya tenías ' . mb_strtolower($mealType, 'UTF-8') . ' registrada para ese día.'
            );
            return;
        }

        $averageHour = $this->nutrition->findAverageMealHour($identifier, $mealType);
        $targetHour = $averageHour !== null
            ? max(MealWindows::startHour($mealType), min(MealWindows::endHour($mealType) - 1, (int)round($averageHour)))
            : MealWindows::defaultHour($mealType);

        $tzLocal = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $localDatetime = new \DateTime($date, $tzLocal);
        $localDatetime->setTime($targetHour, 0);
        $datetimeUtc = (clone $localDatetime)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $nextMealPhrase = MealWindows::nextMealPhrase($mealType);
        $dateLabel = $localDatetime->format('d/m');

        if ($message->type === 'image') {
            $downloadUrl = $message->downloadUrl;
            if ($downloadUrl === '') {
                $downloadUrl = $this->greenApi->downloadFileUrl($chatId, $message->idMessage);
            }
            if ($downloadUrl === '') {
                $this->greenApi->sendMessage($chatId, 'No pude procesar tu imagen para guardarla.');
                return;
            }

            try {
                $imageBytes = @file_get_contents($downloadUrl);
                if ($imageBytes === false) {
                    throw new \RuntimeException('No pude descargar la imagen.');
                }
                $base64 = base64_encode($imageBytes);

                $analysisText = '';
                try {
                    $analysisText = $this->openAi->analyzeMeal($message->body, $base64, $mealType, $nextMealPhrase);
                } catch (\Throwable) {
                    // Continue with zeros/empty analysis rather than failing the whole flow.
                }

                $fileKey = $this->images->store($identifier, $base64);

                $this->finalizeMealEntry(
                    $chatId,
                    $identifier,
                    $mealType,
                    $fileKey,
                    $message->body,
                    $analysisText,
                    $datetimeUtc,
                    $dateLabel
                );
            } catch (\Throwable $e) {
                error_log('Nutri Helper: fallo procesando imagen atrasada: ' . $e->getMessage());
                $this->greenApi->sendMessage($chatId, 'No pude procesar tu imagen para guardarla.');
            }
            return;
        }

        $description = trim($message->body);
        if ($description === '') {
            $this->greenApi->sendMessage($chatId, 'No entendí qué comiste, ¿me lo contás de nuevo?');
            return;
        }

        try {
            $analysisText = $this->openAi->analyzeMealFromText($description, $mealType, $nextMealPhrase);
        } catch (\Throwable $e) {
            error_log('Nutri Helper: fallo analizando comida atrasada por texto: ' . $e->getMessage());
            $this->greenApi->sendMessage($chatId, 'No pude procesar esa descripción, intentá de nuevo.');
            return;
        }

        $this->finalizeMealEntry($chatId, $identifier, $mealType, '', $description, $analysisText, $datetimeUtc, $dateLabel);
    }

    private function handleWaterPollTrigger(string $chatId): void
    {
        $options = array_map(
            static fn (int $n) => (string)$n,
            range(WaterReminderScheduler::MIN_FREQUENCY, WaterReminderScheduler::MAX_FREQUENCY)
        );

        $this->greenApi->sendPoll(
            $chatId,
            '💧 ¿Cuántas veces por día querés que te recuerde tomar agua? (entre las 8 y las 20 hs)',
            $options,
            false
        );
    }

    private function handleWaterPollVote(IncomingMessage $message): void
    {
        if ($message->body === '') {
            // Empty vote (e.g. retracted selection) — nothing to persist.
            return;
        }

        $frequency = (int)trim($message->body);
        if (!WaterReminderScheduler::isValidFrequency($frequency)) {
            return;
        }

        $stored = $this->personas->setWaterFrequency($message->chatId, $frequency);
        if ($stored === null) {
            return;
        }

        $this->greenApi->sendMessage(
            $message->chatId,
            "💧 Listo, te voy a recordar tomar agua {$stored} veces por día entre las 8 y las 20 hs."
        );
    }

    private function handleImage(IncomingMessage $message): void
    {
        $identifier = $this->personas->ensurePerson($this->greenApi, $message->chatId);

        if ($this->nutrition->countTodayForIdentifier($identifier) >= self::MAX_MEALS_PER_DAY) {
            $this->greenApi->sendMessage(
                $message->chatId,
                '⚠️ Ya registraste el máximo de ' . self::MAX_MEALS_PER_DAY . ' comidas hoy. '
                . 'No se pueden insertar más registros por día.'
            );
            return;
        }

        $downloadUrl = $message->downloadUrl;
        if ($downloadUrl === '') {
            $downloadUrl = $this->greenApi->downloadFileUrl($message->chatId, $message->idMessage);
        }

        if ($downloadUrl === '') {
            $this->greenApi->sendMessage($message->chatId, 'No pude procesar tu imagen para guardarla.');
            return;
        }

        $tzLocal = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $mealType = MealWindows::classifyHour((int)(new \DateTime('now', $tzLocal))->format('H'));
        $nextMealPhrase = MealWindows::nextMealPhrase($mealType);

        try {
            $imageBytes = @file_get_contents($downloadUrl);
            if ($imageBytes === false) {
                throw new \RuntimeException('No pude descargar la imagen.');
            }
            $base64 = base64_encode($imageBytes);

            $analysisText = '';
            try {
                $analysisText = $this->openAi->analyzeMeal($message->body, $base64, $mealType, $nextMealPhrase);
            } catch (\Throwable) {
                // Continue with zeros/empty analysis rather than failing the whole flow.
            }

            $fileKey = $this->images->store($identifier, $base64);

            $this->finalizeMealEntry($message->chatId, $identifier, $mealType, $fileKey, $message->body, $analysisText);
        } catch (\Throwable $e) {
            error_log('Nutri Helper: fallo procesando imagen: ' . $e->getMessage());
            $this->greenApi->sendMessage($message->chatId, 'No pude procesar tu imagen para guardarla.');
        }
    }

    /**
     * Sent by bin/send_meal_reminder.php when someone answers "comí pero me
     * olvidé de mandar la foto" — they're expected to reply next with a plain
     * text description of what they ate.
     */
    private function handleMissedMealPollVote(IncomingMessage $message, string $mealType): void
    {
        if ($message->body === '') {
            return;
        }

        $this->personas->setPendingMealReminder($message->chatId, null);

        if ($message->body === 'Comí, me olvidé de mandar la foto') {
            $this->personas->setPendingTextMeal($message->chatId, $mealType);
            $this->greenApi->sendMessage(
                $message->chatId,
                '✍️ Contame por texto qué comiste en ' . MealWindows::skipOptionLabel($mealType)
                . ' y te calculo los datos igual, sin foto.'
            );
            return;
        }

        if ($message->body === 'No comí' || $message->body === MealWindows::skipOptionLabel($mealType)) {
            $this->greenApi->sendMessage($message->chatId, '¡Todo bien! Cuando comas algo mandame la foto o el texto.');
        }
    }

    /**
     * Processes a meal reported purely as text (no photo) — same analysis
     * and storage as a photo, but with foto='' so the history page shows it
     * without an image.
     */
    private function handleTextMealEntry(IncomingMessage $message, string $mealType): void
    {
        $identifier = $this->personas->ensurePerson($this->greenApi, $message->chatId);
        $this->personas->setPendingTextMeal($message->chatId, null);

        if ($this->nutrition->countTodayForIdentifier($identifier) >= self::MAX_MEALS_PER_DAY) {
            $this->greenApi->sendMessage(
                $message->chatId,
                '⚠️ Ya registraste el máximo de ' . self::MAX_MEALS_PER_DAY . ' comidas hoy. '
                . 'No se pueden insertar más registros por día.'
            );
            return;
        }

        $description = trim($message->body);
        if ($description === '') {
            $this->greenApi->sendMessage($message->chatId, 'No entendí qué comiste, ¿me lo contás de nuevo?');
            return;
        }

        $nextMealPhrase = MealWindows::nextMealPhrase($mealType);

        try {
            $analysisText = $this->openAi->analyzeMealFromText($description, $mealType, $nextMealPhrase);
        } catch (\Throwable $e) {
            error_log('Nutri Helper: fallo analizando comida por texto: ' . $e->getMessage());
            $this->greenApi->sendMessage($message->chatId, 'No pude procesar esa descripción, intentá de nuevo.');
            return;
        }

        $this->finalizeMealEntry($message->chatId, $identifier, $mealType, '', $description, $analysisText);
    }

    /**
     * Shared by handleImage(), handleTextMealEntry() and
     * handleBackdateContentEntry(): parses the model's analysis, stores the
     * nutri record (foto='' for text-only entries), and sends the reply with
     * note/advice/macros/history link.
     *
     * @param ?string $datetimeUtc When set (backdated entries), stored instead
     *                             of NOW() — see NutritionRepository::insert().
     * @param ?string $dateLabel   'd/m' label shown in the reply when backdated.
     */
    private function finalizeMealEntry(
        string $chatId,
        string $identifier,
        string $mealType,
        string $foto,
        string $userText,
        string $analysisText,
        ?string $datetimeUtc = null,
        ?string $dateLabel = null
    ): void {
        $nutritionValues = $analysisText !== '' ? $this->parser->extract($analysisText) : null;

        $calories = (int)($nutritionValues['calories']['value'] ?? 0);
        $protein = (int)($nutritionValues['protein']['value'] ?? 0);
        $carbs = (int)($nutritionValues['carbs']['value'] ?? 0);
        $fat = (int)($nutritionValues['fat']['value'] ?? 0);

        $calLabel = $nutritionValues['calories']['label'] ?? ($calories . ' kcal');
        $proLabel = $nutritionValues['protein']['label'] ?? ($protein . ' g');
        $carbLabel = $nutritionValues['carbs']['label'] ?? ($carbs . ' g');
        $fatLabel = $nutritionValues['fat']['label'] ?? ($fat . ' g');

        $noteFromAnalysis = $analysisText !== '' ? $this->parser->extractNote($analysisText) : '';
        $userText = trim($userText);
        $description = $userText !== '' ? $userText : ($noteFromAnalysis !== '' ? $noteFromAnalysis : 'Comida sin descripción');
        $noteForMessage = $noteFromAnalysis !== '' ? $noteFromAnalysis : $userText;

        $advices = $analysisText !== '' ? $this->parser->extractAdvices($analysisText) : ['actual' => '', 'proxima' => ''];

        $this->nutrition->insert([
            'foto'                => $foto,
            'descripcion'         => $description,
            'identifier'          => $identifier,
            'calorias'            => $calories,
            'proteinas'           => $protein,
            'grasas'              => $fat,
            'carbohidratos'       => $carbs,
            'calorias_label'      => (string)$calLabel,
            'proteinas_label'     => (string)$proLabel,
            'grasas_label'        => (string)$fatLabel,
            'carbohidratos_label' => (string)$carbLabel,
            'source'              => 'nutri-helper',
            'consejo_actual'      => $advices['actual'],
            'comida'              => $mealType,
            'datetime'            => $datetimeUtc ?? '',
        ]);

        $reply = [];
        if ($dateLabel !== null) {
            $reply[] = '📅 Registrado para el ' . $dateLabel . ' (' . mb_strtolower($mealType, 'UTF-8') . ').';
        }
        if ($noteForMessage !== '') {
            $reply[] = $noteForMessage;
        }
        if ($advices['actual'] !== '') {
            $reply[] = 'Consejo actual: ' . $advices['actual'];
        }
        if ($advices['proxima'] !== '') {
            $reply[] = 'Consejo próxima comida: ' . $advices['proxima'];
        }
        $reply[] = '';
        $reply[] = "Calorías: {$calories} kcal\nProteínas: {$protein} g\nCarbohidratos: {$carbs} g\nGrasas: {$fat} g";
        $reply[] = 'Tu historial: ' . rtrim($this->landingBaseUrl, '/') . '/?identifier=' . $identifier;

        $this->greenApi->sendMessage($chatId, implode("\n", array_filter($reply, static fn ($v) => $v !== null)));
    }
}
