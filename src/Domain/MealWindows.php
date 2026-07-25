<?php
declare(strict_types=1);

namespace NutriHelper\Domain;

/**
 * Single source of truth for the four meal slots: their typical hour window,
 * a default reminder hour to use before a person has any history, and the
 * "next meal" phrase used in advice prompts.
 */
final class MealWindows
{
    private const WINDOWS = [
        'DESAYUNO' => ['start' => 8, 'end' => 12, 'default_hour' => 9],
        'ALMUERZO' => ['start' => 12, 'end' => 15, 'default_hour' => 13],
        'MERIENDA' => ['start' => 15, 'end' => 19, 'default_hour' => 17],
        'CENA'     => ['start' => 19, 'end' => 24, 'default_hour' => 21],
    ];

    private const NEXT_PHRASE = [
        'DESAYUNO' => 'EL ALMUERZO DE HOY',
        'ALMUERZO' => 'LA MERIENDA DE HOY',
        'MERIENDA' => 'LA CENA DE HOY',
        'CENA'     => 'EL DESAYUNO DE MANANA',
    ];

    private const DEMONSTRATIVE = [
        'DESAYUNO' => 'este',
        'ALMUERZO' => 'este',
        'MERIENDA' => 'esta',
        'CENA'     => 'esta',
    ];

    /** @return string[] */
    public static function all(): array
    {
        return array_keys(self::WINDOWS);
    }

    public static function classifyHour(int $hour): string
    {
        return match (true) {
            $hour >= 8 && $hour < 12  => 'DESAYUNO',
            $hour >= 12 && $hour < 15 => 'ALMUERZO',
            $hour >= 15 && $hour < 19 => 'MERIENDA',
            default                   => 'CENA',
        };
    }

    public static function startHour(string $mealType): int
    {
        return self::WINDOWS[$mealType]['start'] ?? 0;
    }

    public static function endHour(string $mealType): int
    {
        return self::WINDOWS[$mealType]['end'] ?? 24;
    }

    public static function defaultHour(string $mealType): int
    {
        return self::WINDOWS[$mealType]['default_hour'] ?? 12;
    }

    public static function nextMealPhrase(string $mealType): string
    {
        return self::NEXT_PHRASE[$mealType] ?? 'LA PROXIMA COMIDA';
    }

    public static function isValidMealType(string $mealType): bool
    {
        return isset(self::WINDOWS[$mealType]);
    }

    /**
     * Poll option label for "I skipped this meal on purpose", with correct
     * Spanish demonstrative agreement (este desayuno/almuerzo, esta merienda/cena).
     */
    public static function skipOptionLabel(string $mealType): string
    {
        $demonstrative = self::DEMONSTRATIVE[$mealType] ?? 'esta';

        return 'Me salteé ' . $demonstrative . ' ' . mb_strtolower($mealType, 'UTF-8');
    }
}
