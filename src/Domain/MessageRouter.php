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
        $name = $contact['name'] ?? '';
        $shortName = $contact['shortName'] ?? '';
        $pushname = $contact['pushname'] ?? '';

        $this->personas->getOrCreateIdentifier($chatId, $name, $shortName, $pushname);

        $greeting = $shortName !== '' ? $shortName : $name;

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
                'Escribí "ayuda" para ver tips, o "agua" para elegir tu recordatorio de hidratación.',
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

        if ($body === 'ayuda') {
            $this->greenApi->sendMessage($message->chatId, implode("\n", [
                '📸 Consejo Nutri Helper:',
                '1. Asegurate de que la foto esté bien iluminada.',
                '2. Completa la descripcion.',
                '3. Enviá la imagen para un completo calculo nutricional.',
            ]));
            return;
        }

        $this->greenApi->sendMessage($message->chatId, implode("\n", [
            '¡Hola! Soy Nutri Helper.',
            'Enviame una foto todos los dias de tus 4 comidas y te devuelvo los datos clave.',
            'También podés escribir "ayuda" para ver tips sobre cómo sacarle provecho.',
            'Escribí "agua" para elegir cuántas veces por día querés tu recordatorio de agua.',
        ]));
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
     * Shared by handleImage() and handleTextMealEntry(): parses the model's
     * analysis, stores the nutri record (foto='' for text-only entries), and
     * sends the reply with note/advice/macros/history link.
     */
    private function finalizeMealEntry(
        string $chatId,
        string $identifier,
        string $mealType,
        string $foto,
        string $userText,
        string $analysisText
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
        ]);

        $reply = [];
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
