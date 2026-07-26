<?php
declare(strict_types=1);

namespace NutriHelper\Domain;

/**
 * Renders a small PNG bar chart of a day's macro totals (calories/protein/
 * carbs/fat against a sensible daily reference) using GD — no external
 * dependencies, consistent with the rest of this project. Saved into the
 * same publicly-served directory as meal photos so it can be sent back via
 * GreenApiClient::sendFileByUrl() the same way photos are shown on the
 * history page.
 */
final class DayChartRenderer
{
    // Rough daily reference values used only to scale the bars — not a
    // nutritional recommendation, just a way to make "how full is this bar"
    // meaningful at a glance.
    private const REFERENCE = [
        'calorias'      => ['label' => 'Calorías', 'unit' => 'kcal', 'max' => 2200],
        'proteinas'     => ['label' => 'Proteínas', 'unit' => 'g', 'max' => 90],
        'carbohidratos' => ['label' => 'Carbohidratos', 'unit' => 'g', 'max' => 275],
        'grasas'        => ['label' => 'Grasas', 'unit' => 'g', 'max' => 78],
    ];

    private const BAR_COLORS = [
        'calorias'      => [230, 126, 34],
        'proteinas'     => [46, 204, 113],
        'carbohidratos' => [52, 152, 219],
        'grasas'        => [155, 89, 182],
    ];

    public function __construct(
        private readonly string $directory,
        private readonly string $publicUrlBase
    ) {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("No se pudo crear el directorio de charts: {$this->directory}");
        }
    }

    /**
     * @param array{calorias:int,proteinas:int,carbohidratos:int,grasas:int} $totals
     * @return string Public URL of the generated PNG.
     */
    public function render(string $identifier, array $totals): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('La extensión GD no está disponible.');
        }

        $width = 480;
        $barHeight = 46;
        $barGap = 18;
        $labelWidth = 150;
        $chartAreaWidth = $width - $labelWidth - 20;
        $height = (int)(count(self::REFERENCE) * ($barHeight + $barGap)) + $barGap;

        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        $gray = imagecolorallocate($image, 230, 230, 230);
        $dark = imagecolorallocate($image, 40, 40, 40);
        imagefilledrectangle($image, 0, 0, $width, $height, $white);

        $y = $barGap;
        foreach (self::REFERENCE as $key => $meta) {
            $value = (int)($totals[$key] ?? 0);
            $ratio = $meta['max'] > 0 ? min(1.0, $value / $meta['max']) : 0.0;
            $barWidth = (int)round($chartAreaWidth * $ratio);

            [$r, $g, $b] = self::BAR_COLORS[$key];
            $color = imagecolorallocate($image, $r, $g, $b);

            imagestring($image, 4, 5, $y + (int)($barHeight / 2) - 6, (string)$meta['label'], $dark);
            imagefilledrectangle($image, $labelWidth, $y, $labelWidth + $chartAreaWidth, $y + $barHeight, $gray);
            imagefilledrectangle($image, $labelWidth, $y, $labelWidth + $barWidth, $y + $barHeight, $color);

            $valueText = $value . ' ' . $meta['unit'];
            imagestring($image, 4, $labelWidth + 6, $y + (int)($barHeight / 2) - 6, $valueText, $dark);

            $y += $barHeight + $barGap;
        }

        $fileKey = 'chart_' . time() . '_' . $identifier . '.png';
        $path = rtrim($this->directory, '/') . '/' . $fileKey;

        if (!imagepng($image, $path)) {
            imagedestroy($image);
            throw new \RuntimeException("No se pudo guardar el chart en {$path}.");
        }
        imagedestroy($image);

        return rtrim($this->publicUrlBase, '/') . '/' . $fileKey;
    }
}
