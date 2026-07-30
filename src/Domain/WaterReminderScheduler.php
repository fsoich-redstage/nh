<?php
declare(strict_types=1);

namespace NutriHelper\Domain;

/**
 * Spreads N water reminders per day evenly across the 9-20hs window.
 * Deterministic: given a frequency, the same set of hours is produced every
 * time, so the hourly cron can just ask "does this hour belong to this
 * person's schedule?" without persisting a schedule anywhere.
 */
final class WaterReminderScheduler
{
    public const WINDOW_START_HOUR = 9;
    public const WINDOW_END_HOUR = 20;
    private const WINDOW_DURATION_HOURS = 12;
    public const MIN_FREQUENCY = 3;
    public const MAX_FREQUENCY = 12;

    /**
     * @return int[] Sorted, deduplicated hours (24h, local time) at which a
     *               reminder should be sent for the given daily frequency.
     */
    public static function scheduledHours(int $frequency): array
    {
        $frequency = max(1, $frequency);
        $span = self::WINDOW_DURATION_HOURS;
        $step = $span / $frequency;

        $hours = [];
        for ($k = 0; $k < $frequency; $k++) {
            $hour = (int)round(self::WINDOW_START_HOUR + $k * $step);
            $hour = max(self::WINDOW_START_HOUR, min(self::WINDOW_END_HOUR, $hour));
            $hours[$hour] = true;
        }

        $result = array_keys($hours);
        sort($result);

        return $result;
    }

    public static function isScheduledHour(int $frequency, int $hour): bool
    {
        return in_array($hour, self::scheduledHours($frequency), true);
    }

    public static function isValidFrequency(int $frequency): bool
    {
        return $frequency >= self::MIN_FREQUENCY && $frequency <= self::MAX_FREQUENCY;
    }
}
