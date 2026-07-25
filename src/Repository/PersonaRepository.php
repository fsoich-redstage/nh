<?php
declare(strict_types=1);

namespace NutriHelper\Repository;

use NutriHelper\Domain\WaterReminderScheduler;
use NutriHelper\Http\GreenApiClient;

final class PersonaRepository
{
    public function __construct(private readonly \PDO $conn)
    {
    }

    public static function normalizePhone(string $input): string
    {
        return preg_replace('/\D+/', '', $input) ?? '';
    }

    public static function isWellFormedIdentifier(string $identifier): bool
    {
        return (bool)preg_match('/^[AFHJKRUXZ1-9]{4,8}$/', $identifier);
    }

    /**
     * True/false when the lookup succeeded, null on invalid input.
     */
    public function exists(string $chatId): ?bool
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return null;
        }

        $stmt = $this->conn->prepare('SELECT 1 FROM persona WHERE `number` = ? LIMIT 1');
        $stmt->execute([$number]);

        return (bool)$stmt->fetchColumn();
    }

    public function lookupIdentifier(string $number): ?string
    {
        $stmt = $this->conn->prepare('SELECT identifier FROM persona WHERE `number` = ? LIMIT 1');
        $stmt->execute([$number]);
        $identifier = $stmt->fetchColumn();

        return $identifier !== false ? (string)$identifier : null;
    }

    /**
     * Sets how many times per day (3-12) the water reminder should fire for
     * this chat, or clears it (NULL = disabled) when $frequency is 0.
     * Returns the stored value, or null on invalid input.
     */
    public function setWaterFrequency(string $chatId, int $frequency): ?int
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return null;
        }

        if ($frequency !== 0 && !WaterReminderScheduler::isValidFrequency($frequency)) {
            return null;
        }

        $value = $frequency === 0 ? null : $frequency;

        $update = $this->conn->prepare('UPDATE persona SET water_frequency = ? WHERE `number` = ?');
        $update->execute([$value, $number]);

        return $value;
    }

    public function getWaterFrequency(string $chatId): ?int
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return null;
        }

        $stmt = $this->conn->prepare('SELECT water_frequency FROM persona WHERE `number` = ? LIMIT 1');
        $stmt->execute([$number]);
        $value = $stmt->fetchColumn();

        return ($value !== false && $value !== null) ? (int)$value : null;
    }

    /**
     * Returns the identifier for a number, creating a new persona row if needed.
     */
    public function getOrCreateIdentifier(
        string $number,
        string $name = '',
        string $shortName = '',
        string $photo = ''
    ): string {
        $number = self::normalizePhone($number);
        if ($number === '') {
            throw new \RuntimeException('Número inválido para generar identifier.');
        }

        $found = $this->lookupIdentifier($number);
        if ($found !== null) {
            return $found;
        }

        $identifier = $this->generateIdentifier();

        $insert = $this->conn->prepare(
            'INSERT INTO persona (`number`, name, shortname, foto, identifier) VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([$number, $name, $shortName, $photo, $identifier]);

        return $identifier;
    }

    /**
     * Ensures a persona row exists for the chat, fetching contact info from
     * Green API when a new row needs to be created. Returns the identifier.
     */
    public function ensurePerson(GreenApiClient $greenApi, string $chatId): string
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            throw new \RuntimeException('Número inválido recibido para persona.');
        }

        $existing = $this->lookupIdentifier($number);
        if ($existing !== null) {
            return $existing;
        }

        $contact = $greenApi->getContactInfo($chatId) ?? [];

        return $this->getOrCreateIdentifier(
            $number,
            $contact['name'] ?? '',
            $contact['shortName'] ?? '',
            $contact['pushname'] ?? ''
        );
    }

    public function identifierExists(string $identifier): bool
    {
        $stmt = $this->conn->prepare('SELECT 1 FROM persona WHERE identifier = ? LIMIT 1');
        $stmt->execute([$identifier]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return array<int,array{number:string,name:string,shortname:string,identifier:string,water_frequency:int}>
     */
    public function findWaterReminderRecipients(): array
    {
        $stmt = $this->conn->query(
            'SELECT `number`, name, shortname, identifier, water_frequency
             FROM persona
             WHERE water_frequency IS NOT NULL AND water_frequency > 0'
        );

        return $stmt->fetchAll();
    }

    private function generateIdentifier(int $length = 6): string
    {
        $chars = 'AFHJKRUXZ123456789';
        $maxIndex = strlen($chars) - 1;
        $identifier = '';

        for ($i = 0; $i < $length; $i++) {
            $identifier .= $chars[random_int(0, $maxIndex)];
        }

        return $identifier;
    }
}
