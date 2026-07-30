<?php
declare(strict_types=1);

namespace NutriHelper\Domain;

use NutriHelper\Http\GreenApiClient;
use NutriHelper\Http\OpenAiClient;
use NutriHelper\Repository\NutritionRepository;
use NutriHelper\Repository\PersonaRepository;

/**
 * Decides what to do with an incoming message: onboard a new number (a short
 * guided flow — age range, then weight range, both via WhatsApp interactive
 * buttons — before anything else is available), run the meal-photo analysis,
 * resolve a missed-meal reminder (interactive buttons) and the optional
 * text-only follow-up it can trigger, trigger/resolve the water-reminder
 * frequency list, answer "ayuda", or fall back to the default instructions
 * message.
 */
final class MessageRouter
{
    private const MAX_MEALS_PER_DAY = 4;

    private const AGE_RANGE_OPTIONS = ['18-35', '36-55', '56+'];
    private const WEIGHT_RANGE_OPTIONS = ['<70kg', '70-90kg', '90kg+'];
    private const WATER_FREQUENCY_OPTIONS = [4, 6, 8];

    /**
     * "cargar" flow, step 1 (BACKDATE_AWAITING_DAY): days-ago offset ->
     * button label. The offset itself (as a string) is the button id.
     */
    private const BACKDATE_DAY_LABELS = [
        0 => 'Hoy',
        1 => 'Ayer',
        2 => 'Hace 2 días',
    ];

    /**
     * "cargar" flow, step 2 (BACKDATE_AWAITING_MEAL): MealWindows meal-type
     * key -> button label. The meal-type key itself is the button id.
     */
    private const BACKDATE_MEAL_LABELS = [
        'DESAYUNO' => 'Desayuno',
        'ALMUERZO' => 'Almuerzo',
        'MERIENDA' => 'Merienda',
        'CENA'     => 'Cena',
    ];

    /**
     * rowIds for the missed-meal reminder menu. The prompt itself mentions
     * which meal we're talking about; these ids stay meal-agnostic so the
     * same parser works for desayuno/almuerzo/merienda/cena.
     */
    public const MEAL_REMINDER_NO_COMI = 'no_comi';
    public const MEAL_REMINDER_DELAYED = 'me_retrase';
    public const MEAL_REMINDER_CAPTURE_NOW = 'cargar_ahora';
    public const MEAL_REMINDER_SALTEADO = 'salteado';

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

        if ($onboardingStep !== PersonaRepository::ONBOARDING_DONE) {
            if ($message->type === 'list_reply' || $message->type === 'button_reply' || $message->type === 'text') {
                $this->handleOnboardingReply($message, $onboardingStep);
            } else {
                // Ignore whatever they sent and gently steer them back to the
                // pending onboarding question instead of processing it as a
                // normal command/photo.
                $this->resendOnboardingStep($message->chatId, $onboardingStep);
            }
            return;
        }

        $pendingMealReminder = $this->personas->getPendingMealReminder($message->chatId);
        if ($pendingMealReminder !== null) {
            if ($message->type === 'list_reply' || $message->type === 'button_reply' || $message->type === 'text') {
                $this->handleMissedMealReply($message, $pendingMealReminder);
                return;
            }

            if ($message->type !== 'image') {
                return;
            }

            // A direct photo should still be processed as the current meal
            // window; don't let an old reminder swallow it.
            $this->personas->setPendingMealReminder($message->chatId, null);
        }

        $backdateStep = $this->personas->getPendingBackdateStep($message->chatId);

        if ($backdateStep === PersonaRepository::BACKDATE_AWAITING_DAY) {
            if ($message->type === 'list_reply' || $message->type === 'button_reply' || $message->type === 'text') {
                $this->handleBackdateDayReply($message);
            }
            return;
        }

        if ($backdateStep === PersonaRepository::BACKDATE_AWAITING_MEAL) {
            if ($message->type === 'list_reply' || $message->type === 'button_reply' || $message->type === 'text') {
                $this->handleBackdateMealReply($message);
            }
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
            && $backdateStep === PersonaRepository::BACKDATE_AWAITING_CONTENT
        ) {
            $this->handleBackdateContentEntry($message);
            return;
        }

        if ($message->type === 'list_reply' || $message->type === 'button_reply') {
            if ($this->personas->getPendingWaterPoll($message->chatId) || $this->parseWaterFrequencyReply($message->body) !== null) {
                $this->handleWaterFrequencyReply($message);
                return;
            }

            $normalizedReply = $this->normalizeReplyCommand($message->body);
            if (in_array($normalizedReply, ['agua', 'borrar', 'cargar', 'ayuda', 'menu'], true)) {
                $this->dispatchCommand($message->chatId, $normalizedReply);
            }
            return;
        }

        if ($message->type === 'text') {
            if ($this->personas->getPendingWaterPoll($message->chatId) || $this->parseWaterFrequencyReply($message->body) !== null) {
                $this->handleWaterFrequencyReply($message);
                return;
            }
        }

        if ($message->type === 'image') {
            $this->handleImage($message);
            return;
        }

        if ($message->type === 'text') {
            $this->handleText($message);
            return;
        }

        // Other message types (button replies with no pending flow, status updates, etc.) are ignored.
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

        $this->sendAgeRangeList($chatId);
    }

    private function sendAgeRangeList(string $chatId): void
    {
        $this->sendButtonsWithFallback(
            $chatId,
            '🎂 ¿En qué rango de edad estás?',
            $this->buildOptionButtons(self::AGE_RANGE_OPTIONS),
            'Respondé con una de estas opciones: 18-35, 36-55, 56+.'
        );
    }

    private function sendWeightRangeList(string $chatId): void
    {
        $this->sendButtonsWithFallback(
            $chatId,
            '💪 ¿Y tu rango de peso aproximado?',
            $this->buildOptionButtons(self::WEIGHT_RANGE_OPTIONS),
            'Respondé con una de estas opciones: <70kg, 70-90kg, 90kg+.'
        );
    }

    /**
     * @param string[] $options
     * @return array<int,array{id:string,text:string}>
     */
    private function buildOptionButtons(array $options): array
    {
        return array_map(static fn (string $option) => ['id' => $option, 'text' => $option], $options);
    }

    /**
     * @param array<int,array{id:string,text:string}> $buttons
     */
    private function sendButtonsWithFallback(string $chatId, string $body, array $buttons, string $fallbackMessage): void
    {
        $result = $this->greenApi->sendInteractiveButtons($chatId, $body, $buttons);

        if ($result['status'] >= 400) {
            $this->greenApi->sendMessage($chatId, $body . "\n\n" . $fallbackMessage);
        }
    }

    /**
     * @param array<int,array{title:string,rows:array<int,array{title:string,description?:string,rowId:string}>}> $sections
     */
    private function sendListWithFallback(string $chatId, string $body, string $buttonText, array $sections, string $fallbackMessage): void
    {
        $result = $this->greenApi->sendListMessage($chatId, $body, $buttonText, $sections);

        if ($result['status'] >= 400) {
            $this->greenApi->sendMessage($chatId, $body . "\n\n" . $fallbackMessage);
        }
    }

    private function handleOnboardingReply(IncomingMessage $message, string $step): void
    {
        if ($message->body === '') {
            return;
        }

        if ($step === PersonaRepository::ONBOARDING_AWAITING_AGE) {
            if (!in_array($message->body, self::AGE_RANGE_OPTIONS, true)) {
                return;
            }

            $this->personas->setAgeRange($message->chatId, $message->body);
            $this->personas->setOnboardingStep($message->chatId, PersonaRepository::ONBOARDING_AWAITING_WEIGHT);

            $this->sendWeightRangeList($message->chatId);
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
            $this->sendAgeRangeList($chatId);
            return;
        }

        if ($step === PersonaRepository::ONBOARDING_AWAITING_WEIGHT) {
            $this->sendWeightRangeList($chatId);
        }
    }

    private function handleText(IncomingMessage $message): void
    {
        $this->dispatchCommand($message->chatId, mb_strtolower(trim($message->body), 'UTF-8'));
    }

    /**
     * Shared by handleText() (typed commands) and route()'s main-menu
     * interactive-reply branch (sendMainMenu()'s button ids are these same
     * command words, so a tap and a typed command end up here identically).
     */
    private function dispatchCommand(string $chatId, string $command): void
    {
        // A stray, never-answered water-frequency list from earlier doesn't
        // get to hijack the *next* list_reply once the person has moved on
        // to a different command.
        $this->personas->setPendingWaterPoll($chatId, false);

        if ($command === 'agua') {
            $this->handleWaterFrequencyTrigger($chatId);
            return;
        }

        if ($command === 'borrar') {
            $this->handleDeleteLastMeal($chatId);
            return;
        }

        if ($command === 'cargar') {
            $this->handleBackdateTrigger($chatId);
            return;
        }

        if ($command === 'ayuda') {
            $this->greenApi->sendMessage($chatId, implode("\n", [
                '📸 Consejo Nutri Helper:',
                '1. Asegurate de que la foto esté bien iluminada.',
                '2. Completa la descripcion.',
                '3. Enviá la imagen para un completo calculo nutricional.',
            ]));
            return;
        }

        if ($command === 'menu') {
            $this->sendMainMenu($chatId);
            return;
        }

        $this->sendMainMenu($chatId, '¡Hola! Soy Nutri Helper.');
    }

    /**
     * Interactive button menu with a plain-text fallback for instance/client
     * combinations that reject button replies.
     */
    private function sendMainMenu(string $chatId, string $intro = ''): void
    {
        $header = $intro !== '' ? $intro . ' ' : '';
        $message = $header . 'Mandame una foto de tus comidas para analizarlas, o elegí una opción:';

        $this->sendButtonsWithFallback(
            $chatId,
            $message,
            [
                ['id' => 'ayuda', 'text' => 'Ayuda'],
                ['id' => 'agua', 'text' => 'Agua'],
                ['id' => 'cargar', 'text' => 'Cargar'],
            ],
            'Escribí "ayuda", "agua", "cargar", "borrar" o "menu".'
        );
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

        $buttons = [];
        foreach (self::BACKDATE_DAY_LABELS as $offset => $label) {
            $buttons[] = ['id' => (string)$offset, 'text' => $label];
        }

        $this->sendButtonsWithFallback(
            $chatId,
            '🗓️ ¿De qué día es la comida que querés cargar?',
            $buttons,
            'Respondé con: Hoy, Ayer o Hace 2 días.'
        );
    }

    private function handleBackdateDayReply(IncomingMessage $message): void
    {
        $rawBody = trim($message->body);
        if ($rawBody === '') {
            return;
        }

        if (ctype_digit($rawBody) && array_key_exists((int)$rawBody, self::BACKDATE_DAY_LABELS)) {
            $offsetDays = (int)$rawBody;
        } else {
            $normalizedBody = $this->normalizeReplyCommand($rawBody);
            $offsetDays = array_search($normalizedBody, array_map(
                fn (string $label): string => $this->normalizeReplyCommand($label),
                self::BACKDATE_DAY_LABELS
            ), true);

            if ($offsetDays === false) {
                return;
            }
        }

        $tzLocal = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $date = (new \DateTime('today', $tzLocal))->modify("-{$offsetDays} days")->format('Y-m-d');
        $availableMeals = $this->findAvailableBackdateMeals($message->chatId, $date);

        if ($availableMeals === []) {
            $this->personas->setPendingBackdate($message->chatId, null);
            $this->greenApi->sendMessage(
                $message->chatId,
                '⚠️ Ese día ya tiene las ' . self::MAX_MEALS_PER_DAY . ' comidas registradas.'
            );
            return;
        }

        $this->personas->setPendingBackdate($message->chatId, PersonaRepository::BACKDATE_AWAITING_MEAL, $date);
        $this->sendBackdateMealOptions($message->chatId, $availableMeals);
    }

    private function handleBackdateMealReply(IncomingMessage $message): void
    {
        $mealType = mb_strtoupper(trim($message->body), 'UTF-8');

        $date = $this->personas->getPendingBackdateDate($message->chatId);
        if ($date === null) {
            // Shouldn't happen (step implies a date was already stored), but
            // don't leave the user stuck if it does.
            $this->personas->setPendingBackdate($message->chatId, null);
            return;
        }

        $availableMeals = $this->findAvailableBackdateMeals($message->chatId, $date);
        if ($availableMeals === []) {
            $this->personas->setPendingBackdate($message->chatId, null);
            $this->greenApi->sendMessage(
                $message->chatId,
                '⚠️ Ese día ya tiene las ' . self::MAX_MEALS_PER_DAY . ' comidas registradas.'
            );
            return;
        }

        if ($mealType === '' || !in_array($mealType, $availableMeals, true)) {
            $this->sendBackdateMealOptions($message->chatId, $availableMeals);
            return;
        }

        $this->startBackdateCaptureFlow($message->chatId, $date, $mealType);
    }

    private function startBackdateCaptureFlow(string $chatId, string $date, string $mealType, string $intro = ''): void
    {
        $this->personas->setPendingBackdate($chatId, PersonaRepository::BACKDATE_AWAITING_CONTENT, $date, $mealType);

        $mealLabel = $this->mealTypeLabelLower($mealType);
        $body = '📲 Mandame la foto o contame por texto tu ' . $mealLabel . ($this->isTodayLocalDate($date) ? ' de hoy' : ' de ese día') . '.';
        if ($intro !== '') {
            $body = $intro . "\n\n" . $body;
        }

        $this->greenApi->sendMessage(
            $chatId,
            $body . ' Si querés, en la foto también podés sumar una descripción.'
        );
    }

    /**
     * @param string[] $mealTypes
     */
    private function sendBackdateMealOptions(string $chatId, array $mealTypes): void
    {
        $buttons = [];
        foreach ($mealTypes as $mealType) {
            $label = self::BACKDATE_MEAL_LABELS[$mealType] ?? $mealType;
            $buttons[] = ['id' => $mealType, 'text' => $label];
        }

        $this->sendButtonsWithFallback(
            $chatId,
            '🍽️ ¿Qué comida fue?',
            $buttons,
            'Respondé con: ' . $this->formatBackdateMealFallback($mealTypes) . '.'
        );
    }

    /**
     * @return string[]
     */
    private function findAvailableBackdateMeals(string $chatId, string $date): array
    {
        $identifier = $this->personas->lookupIdentifier(PersonaRepository::normalizePhone($chatId));
        if ($identifier === null) {
            return [];
        }

        $loggedMealTypes = array_fill_keys($this->nutrition->fetchMealTypesOnDate($identifier, $date), true);
        $availableMeals = [];

        foreach (MealWindows::all() as $mealType) {
            if (isset(self::BACKDATE_MEAL_LABELS[$mealType]) && !isset($loggedMealTypes[$mealType])) {
                $availableMeals[] = $mealType;
            }
        }

        if (count($availableMeals) === self::MAX_MEALS_PER_DAY) {
            $availableMeals = array_values(array_filter(
                $availableMeals,
                static fn (string $mealType): bool => $mealType !== 'CENA'
            ));
        }

        return $availableMeals;
    }

    /**
     * @param string[] $mealTypes
     */
    private function formatBackdateMealFallback(array $mealTypes): string
    {
        $labels = array_map(
            static fn (string $mealType): string => self::BACKDATE_MEAL_LABELS[$mealType] ?? $mealType,
            $mealTypes
        );

        $lastLabel = array_pop($labels);
        if ($lastLabel === null) {
            return '';
        }

        if ($labels === []) {
            return $lastLabel;
        }

        return implode(', ', $labels) . ' o ' . $lastLabel;
    }

    /**
     * The photo or text sent right after completing the day+meal lists above
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

        if ($date === null || $mealType === null) {
            $this->personas->setPendingBackdate($chatId, null);
            return;
        }

        $identifier = $this->personas->ensurePerson($this->greenApi, $chatId);

        if ($this->nutrition->countEntriesForIdentifierOnDate($identifier, $date) >= self::MAX_MEALS_PER_DAY) {
            $this->personas->setPendingBackdate($chatId, null);
            $this->greenApi->sendMessage(
                $chatId,
                '⚠️ Ese día ya tiene el máximo de ' . self::MAX_MEALS_PER_DAY . ' comidas registradas.'
            );
            return;
        }

        if ($this->nutrition->hasMealTypeOnDate($identifier, $mealType, $date)) {
            $this->personas->setPendingBackdate($chatId, null);
            $this->greenApi->sendMessage(
                $chatId,
                '⚠️ Ya tenías ' . mb_strtolower($mealType, 'UTF-8') . ' registrada para ese día.'
            );
            return;
        }

        $tzLocal = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $localDatetime = $this->resolveBackdateLocalDatetime($date, $mealType, $identifier, $tzLocal);
        $datetimeUtc = (clone $localDatetime)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $nextMealPhrase = MealWindows::nextMealPhrase($mealType);
        $dateLabel = $this->isTodayLocalDate($date) ? null : $localDatetime->format('d/m');

        if ($message->type === 'image') {
            $downloadUrl = $message->downloadUrl;
            if ($downloadUrl === '') {
                $downloadUrl = $this->greenApi->downloadFileUrl($chatId, $message->idMessage);
            }
            if ($downloadUrl === '') {
                $this->greenApi->sendMessage($chatId, 'No pude procesar tu imagen. Probá mandarla de nuevo.');
                return;
            }

            try {
                $imageBytes = @file_get_contents($downloadUrl);
                if ($imageBytes === false) {
                    throw new \RuntimeException('No pude descargar la imagen.');
                }
                $base64 = base64_encode($imageBytes);

                try {
                    $analysisText = $this->openAi->analyzeMeal($message->body, $base64, $mealType, $nextMealPhrase);
                } catch (\Throwable $e) {
                    error_log('Nutri Helper: fallo analizando comida atrasada por imagen: ' . $e->getMessage());
                    $this->greenApi->sendMessage($chatId, 'No pude analizar esa foto en este momento. Probá mandarla de nuevo en un rato.');
                    return;
                }

                $fileKey = $this->images->store($identifier, $base64);
                $this->personas->setPendingBackdate($chatId, null);

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
                $this->greenApi->sendMessage($chatId, 'No pude guardar esa imagen. Probá mandarla de nuevo.');
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

        $this->personas->setPendingBackdate($chatId, null);
        $this->finalizeMealEntry($chatId, $identifier, $mealType, '', $description, $analysisText, $datetimeUtc, $dateLabel);
    }

    private function handleWaterFrequencyTrigger(string $chatId): void
    {
        $buttons = [];
        foreach (self::WATER_FREQUENCY_OPTIONS as $frequency) {
            $buttons[] = ['id' => (string)$frequency, 'text' => "{$frequency} veces"];
        }

        $this->sendButtonsWithFallback(
            $chatId,
            '💧 ¿Cuántas veces por día querés que te recuerde tomar agua? (entre las 9 y las 20 hs)',
            $buttons,
            'Respondé con: 4, 6 u 8.'
        );

        $this->personas->setPendingWaterPoll($chatId, true);
    }

    private function handleWaterFrequencyReply(IncomingMessage $message): void
    {
        // Consumed either way — a stray reply shouldn't leave this flag
        // dangling to misfire against whatever list_reply comes next.
        $this->personas->setPendingWaterPoll($message->chatId, false);

        $frequency = $this->parseWaterFrequencyReply($message->body);
        if ($frequency === null) {
            return;
        }

        if (!WaterReminderScheduler::isValidFrequency($frequency)) {
            return;
        }

        $stored = $this->personas->setWaterFrequency($message->chatId, $frequency);
        if ($stored === null) {
            return;
        }

        $this->greenApi->sendMessage(
            $message->chatId,
            "💧 Listo, te voy a recordar tomar agua {$stored} veces por día entre las 9 y las 20 hs."
        );
    }

    private function normalizeReplyCommand(string $body): string
    {
        $normalized = mb_strtolower(trim($body), 'UTF-8');
        $normalized = strtr($normalized, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
        ]);

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }

    private function mealTypeLabelLower(string $mealType): string
    {
        return mb_strtolower(self::BACKDATE_MEAL_LABELS[$mealType] ?? $mealType, 'UTF-8');
    }

    private function resolveBackdateLocalDatetime(string $date, string $mealType, string $identifier, \DateTimeZone $tzLocal): \DateTime
    {
        $today = (new \DateTime('today', $tzLocal))->format('Y-m-d');
        $nowLocal = new \DateTime('now', $tzLocal);
        $currentHour = (int)$nowLocal->format('G');

        if ($date === $today && MealWindows::isWithinWindow($mealType, $currentHour)) {
            return clone $nowLocal;
        }

        $averageHour = $this->nutrition->findAverageMealHour($identifier, $mealType);
        $targetHour = $averageHour !== null
            ? max(MealWindows::startHour($mealType), min(MealWindows::endHour($mealType) - 1, (int)round($averageHour)))
            : MealWindows::defaultHour($mealType);

        $localDatetime = new \DateTime($date, $tzLocal);
        $localDatetime->setTime($targetHour, 0);

        return $localDatetime;
    }

    private function isTodayLocalDate(string $date): bool
    {
        $tzLocal = new \DateTimeZone('America/Argentina/Buenos_Aires');

        return $date === (new \DateTime('today', $tzLocal))->format('Y-m-d');
    }

    private function resolveMealReminderReply(string $body): ?string
    {
        return match ($this->normalizeReplyCommand($body)) {
            self::MEAL_REMINDER_NO_COMI, 'todavia no comi', 'no comi' => self::MEAL_REMINDER_NO_COMI,
            self::MEAL_REMINDER_DELAYED, 'me retrase' => self::MEAL_REMINDER_DELAYED,
            self::MEAL_REMINDER_CAPTURE_NOW, 'cargar ahora' => self::MEAL_REMINDER_CAPTURE_NOW,
            self::MEAL_REMINDER_SALTEADO, 'lo saltee', 'me lo saltee' => self::MEAL_REMINDER_SALTEADO,
            default => null,
        };
    }

    private function parseWaterFrequencyReply(string $body): ?int
    {
        $normalized = trim($body);
        if ($normalized === '') {
            return null;
        }

        if (ctype_digit($normalized)) {
            return (int)$normalized;
        }

        if (preg_match('/\d+/', $normalized, $matches) === 1) {
            return (int)$matches[0];
        }

        return null;
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

            try {
                $analysisText = $this->openAi->analyzeMeal($message->body, $base64, $mealType, $nextMealPhrase);
            } catch (\Throwable $e) {
                error_log('Nutri Helper: fallo analizando comida por imagen: ' . $e->getMessage());
                $this->greenApi->sendMessage($message->chatId, 'No pude analizar esa foto en este momento. Revisá la cuota de OpenAI e intentá de nuevo.');
                return;
            }

            $fileKey = $this->images->store($identifier, $base64);

            $this->finalizeMealEntry($message->chatId, $identifier, $mealType, $fileKey, $message->body, $analysisText);
        } catch (\Throwable $e) {
            error_log('Nutri Helper: fallo procesando imagen: ' . $e->getMessage());
            $this->greenApi->sendMessage($message->chatId, 'No pude procesar tu imagen para guardarla.');
        }
    }

    /**
     * Resolves the missed-meal reminder list/button/text reply. "Cargar
     * ahora" jumps into the same guided capture flow as the generic
     * "cargar" command, but prefilled with today's reminded meal.
     */
    private function handleMissedMealReply(IncomingMessage $message, string $mealType): void
    {
        $selectedId = $this->resolveMealReminderReply($message->body);
        if ($selectedId === null) {
            $this->sendMealReminderOptions($message->chatId, $mealType);
            return;
        }

        $this->personas->setPendingMealReminder($message->chatId, null);

        if ($selectedId === self::MEAL_REMINDER_CAPTURE_NOW) {
            $tzLocal = new \DateTimeZone('America/Argentina/Buenos_Aires');
            $this->startBackdateCaptureFlow(
                $message->chatId,
                (new \DateTime('today', $tzLocal))->format('Y-m-d'),
                $mealType,
                'Perfecto, lo cargo igual venga con foto o sin foto.'
            );
            return;
        }

        if ($selectedId === self::MEAL_REMINDER_DELAYED) {
            $this->greenApi->sendMessage(
                $message->chatId,
                'Dale. Si terminás haciendo tu ' . $this->mealTypeLabelLower($mealType)
                . ' más tarde, usá "cargar" y elegí esa comida para que quede bien registrada.'
            );
            return;
        }

        if ($selectedId === self::MEAL_REMINDER_NO_COMI) {
            $this->greenApi->sendMessage(
                $message->chatId,
                'Listo. Cuando comas algo, mandame la foto o usá "cargar" si terminó siendo más tarde.'
            );
            return;
        }

        $this->greenApi->sendMessage($message->chatId, 'Listo, no la tomo en cuenta por ahora.');
    }

    private function sendMealReminderOptions(string $chatId, string $mealType): void
    {
        $mealLabel = $this->mealTypeLabelLower($mealType);

        $this->sendButtonsWithFallback(
            $chatId,
            '🍽️ ¿Qué pasó con tu ' . $mealLabel . ' de hoy? Todavía no vi que lo registraras.',
            [
                ['id' => self::MEAL_REMINDER_NO_COMI, 'text' => 'No comi'],
                ['id' => self::MEAL_REMINDER_DELAYED, 'text' => 'Me retrase'],
                ['id' => self::MEAL_REMINDER_CAPTURE_NOW, 'text' => 'Cargar ahora'],
            ],
            'Respondé con: No comi, Me retrase o Cargar ahora.'
        );
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
