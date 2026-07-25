<?php
declare(strict_types=1);

namespace NutriHelper\Domain;

use NutriHelper\Http\GreenApiClient;
use NutriHelper\Http\OpenAiClient;
use NutriHelper\Repository\NutritionRepository;
use NutriHelper\Repository\PersonaRepository;

/**
 * Decides what to do with an incoming message: onboard a new number, run the
 * meal-photo analysis, toggle the water reminder, answer "ayuda", or fall
 * back to the default instructions message.
 */
final class MessageRouter
{
    private const MAX_MEALS_PER_DAY = 4;

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
            $this->handleOnboarding($message->chatId);
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

    private function handleOnboarding(string $chatId): void
    {
        $contact = $this->greenApi->getContactInfo($chatId) ?? [];
        $name = $contact['name'] ?? 'No disponible';
        $shortName = $contact['shortName'] ?? 'No disponible';
        $pushname = $contact['pushname'] ?? 'No disponible';

        $this->personas->getOrCreateIdentifier($chatId, $name, $shortName, $pushname);

        $this->greenApi->sendMessage(
            $chatId,
            'Bienvenido a NutriHelper ' . $name . '. Estas listo para cambiar tus habitos para siempre? '
            . 'Mandame una foto de tus 4 comidas y te ayudo ...'
        );
    }

    private function handleText(IncomingMessage $message): void
    {
        $body = mb_strtolower(trim($message->body), 'UTF-8');

        if ($body === 'agua' || $body === 'push agua -r xrr') {
            $this->handleWaterToggle($message->chatId);
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
            'Escribí "agua" para activar o desactivar tu recordatorio de agua.',
        ]));
    }

    private function handleWaterToggle(string $chatId): void
    {
        $newValue = $this->personas->toggleSetting($chatId);

        if ($newValue === null) {
            $this->greenApi->sendMessage(
                $chatId,
                '❌ No pude actualizar tu preferencia de agua. Probá de nuevo más tarde.'
            );
            return;
        }

        $state = $newValue === 1 ? 'ACTIVADO' : 'DESACTIVADO';
        $this->greenApi->sendMessage($chatId, "💧 Recordatorio de agua: {$state}.");
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

        try {
            $imageBytes = @file_get_contents($downloadUrl);
            if ($imageBytes === false) {
                throw new \RuntimeException('No pude descargar la imagen.');
            }
            $base64 = base64_encode($imageBytes);

            $analysisText = '';
            try {
                $analysisText = $this->openAi->analyzeMeal($message->body, $base64);
            } catch (\Throwable) {
                // Continue with zeros/empty analysis rather than failing the whole flow.
            }

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
            $userText = trim($message->body);
            $description = $userText !== '' ? $userText : ($noteFromAnalysis !== '' ? $noteFromAnalysis : 'Imagen sin descripción');
            $noteForMessage = $noteFromAnalysis !== '' ? $noteFromAnalysis : $userText;

            $advices = $analysisText !== '' ? $this->parser->extractAdvices($analysisText) : ['actual' => '', 'proxima' => ''];

            $fileKey = $this->images->store($identifier, $base64);

            $this->nutrition->insert([
                'foto'                => $fileKey,
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

            $this->greenApi->sendMessage($message->chatId, implode("\n", array_filter($reply, static fn ($v) => $v !== null)));
        } catch (\Throwable $e) {
            error_log('Nutri Helper: fallo procesando imagen: ' . $e->getMessage());
            $this->greenApi->sendMessage($message->chatId, 'No pude procesar tu imagen para guardarla.');
        }
    }
}
