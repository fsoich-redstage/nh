<?php
declare(strict_types=1);

namespace NutriHelper\Http;

/**
 * Wraps the OpenAI Responses API calls used to analyze a meal photo.
 * Prompt wording and meal-slot logic ported as-is from the previous
 * implementation (dameMagia) since it was already tuned and working.
 */
final class OpenAiClient
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    public function __construct(private readonly string $apiKey)
    {
    }

    public function analyzeMeal(string $description, string $imageBase64): string
    {
        [$mealName, $nextMealName] = $this->currentMealSlot();

        $text = 'Haz un análisis nutricional resumido que incluya: nota (unas palabras sobre el análisis) y luego '
            . 'calorías aproximadas, proteínas, carbohidratos y grasas SOLO TOTALES SUMADOS NO OTROS VALORES de este/a '
            . $mealName . ' que contiene ' . $description . '. '
            . 'Responde en el siguiente formato exacto, una línea por ítem y en este orden: '
            . 'Nota: … Calorías: … kcal Proteínas: … g Carbohidratos: … g Grasas: … g '
            . 'Consejo actual: … Consejo próxima comida (' . trim($nextMealName) . '): …. '
            . 'El consejo actual debe basarse en la foto; el de la próxima comida debe ser específico para '
            . trim($nextMealName) . '. No uses doble salto de linea';

        $payload = [
            'model' => 'gpt-4o-mini',
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $text],
                    [
                        'type' => 'input_image',
                        'image_url' => 'data:image/jpeg;base64,' . $imageBase64,
                        'detail' => 'high',
                    ],
                ],
            ]],
        ];

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('cURL error llamando a OpenAI: ' . $error);
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $response = $this->trimToLastJsonBrace($response);
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('No se pudo decodificar la respuesta de OpenAI: ' . json_last_error_msg());
        }

        if (!empty($data['error'])) {
            $message = $data['error']['message'] ?? 'Unknown error';
            $code = $data['error']['code'] ?? ($data['error']['type'] ?? 'error');
            throw new \RuntimeException("OpenAI API error ({$code}) [{$httpCode}]: {$message}");
        }

        return $this->extractOutputText($data);
    }

    /**
     * @return array{0:string,1:string} [comida actual, próxima comida] según hora en Buenos Aires.
     */
    private function currentMealSlot(): array
    {
        $tz = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $hour = (int)(new \DateTime('now', $tz))->format('H');

        return match (true) {
            $hour >= 8 && $hour < 12  => ['DESAYUNO', 'EL ALMUERZO DE HOY'],
            $hour >= 12 && $hour < 15 => ['ALMUERZO', 'LA MERIENDA DE HOY'],
            $hour >= 15 && $hour < 19 => ['MERIENDA', 'LA CENA DE HOY'],
            default                   => ['CENA', 'EL DESAYUNO DE MANANA'],
        };
    }

    private function trimToLastJsonBrace(string $raw): string
    {
        $pos = strrpos($raw, '}');

        return $pos !== false ? substr($raw, 0, $pos + 1) : $raw;
    }

    private function extractOutputText(array $data): string
    {
        if (!empty($data['output_text']) && is_string($data['output_text'])) {
            return $data['output_text'];
        }

        if (!empty($data['output']) && is_array($data['output'])) {
            $parts = [];
            foreach ($data['output'] as $message) {
                foreach ($message['content'] ?? [] as $content) {
                    if (($content['type'] ?? null) === 'output_text' && isset($content['text'])) {
                        $parts[] = $content['text'];
                    }
                }
            }
            if ($parts !== []) {
                return implode("\n", $parts);
            }
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
