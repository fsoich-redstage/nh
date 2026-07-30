<?php
declare(strict_types=1);

namespace NutriHelper\Repository;

use NutriHelper\Db\Database;
use NutriHelper\Domain\WaterReminderScheduler;
use NutriHelper\Http\GreenApiClient;

final class PersonaRepository
{
    public const ONBOARDING_AWAITING_AGE = 'awaiting_age';
    public const ONBOARDING_AWAITING_WEIGHT = 'awaiting_weight';
    public const ONBOARDING_DONE = 'done';

    // Steps of the "cargar" backdated-meal flow (persona.pending_backdate_step):
    // awaiting_day -> user picked "cargar", waiting for the day-offset list reply
    // awaiting_meal -> day chosen, waiting for the meal-type list reply
    // awaiting_content -> meal type chosen, waiting for the photo/text itself
    public const BACKDATE_AWAITING_DAY = 'awaiting_day';
    public const BACKDATE_AWAITING_MEAL = 'awaiting_meal';
    public const BACKDATE_AWAITING_CONTENT = 'awaiting_content';

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
     * Display name for the history page header: shortname takes priority
     * over the full name, matching how the WhatsApp bot addresses people.
     */
    public function findDisplayNameByIdentifier(string $identifier): string
    {
        $stmt = $this->conn->prepare('SELECT shortname, name FROM persona WHERE identifier = ? LIMIT 1');
        $stmt->execute([$identifier]);
        $row = $stmt->fetch();

        if ($row === false) {
            return '';
        }

        $shortname = trim((string)($row['shortname'] ?? ''));

        return $shortname !== '' ? $shortname : trim((string)($row['name'] ?? ''));
    }

    /**
     * Every persona row — backs bin/backfill_persona_contact_info.php, which
     * re-fetches Green API contact info for people who onboarded before this
     * codebase captured the profile picture / pushname correctly.
     *
     * @return array<int,array{number:string,identifier:string,name:string,shortname:string,foto:string}>
     */
    public function findAllPersonas(): array
    {
        $stmt = $this->conn->query('SELECT `number`, identifier, name, shortname, foto FROM persona');

        return $stmt->fetchAll();
    }

    /**
     * Every persona with their full contact info plus meal-count/last-activity
     * stats — backs the admin panel's user list (admin/index.php).
     * Ordered with the most recently active people first, and anyone who
     * never logged a meal at the bottom.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findAllWithStats(): array
    {
        $pushnameSelect = Database::tableHasColumn($this->conn, 'persona', 'pushname')
            ? 'p.pushname'
            : "'' AS pushname";

        $sql = "SELECT p.number, p.identifier, p.name, p.shortname, {$pushnameSelect}, p.foto,
                       p.age_range, p.weight_range, p.water_frequency, p.onboarding_step,
                       COUNT(n.id) AS total_meals, MAX(n.datetime) AS last_meal_at
                FROM persona p
                LEFT JOIN nutri n ON n.identifier = p.identifier
                GROUP BY p.number
                ORDER BY last_meal_at IS NULL, last_meal_at DESC";

        return $this->conn->query($sql)->fetchAll();
    }

    /**
     * Full contact info for one identifier — backs the admin panel's
     * per-user detail page (admin/persona.php).
     *
     * @return array<string,mixed>|null
     */
    public function findByIdentifier(string $identifier): ?array
    {
        $pushnameSelect = Database::tableHasColumn($this->conn, 'persona', 'pushname')
            ? 'pushname'
            : "'' AS pushname";

        $stmt = $this->conn->prepare(
            "SELECT `number`, identifier, name, shortname, {$pushnameSelect}, foto,
                    age_range, weight_range, water_frequency, onboarding_step
             FROM persona WHERE identifier = ? LIMIT 1"
        );
        $stmt->execute([$identifier]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Fills in whatever Green API's GetContactInfo actually returned for this
     * chat — name / shortname / foto (profilePicUrl) / pushname (only when
     * that column exists) — without touching fields Green API didn't have a
     * value for, so a temporary gap in Green API's response never blanks out
     * data this persona already had. Returns whether anything was updated.
     *
     * @param array{name?:string,shortName?:string,pushname?:string,profilePicUrl?:string} $contact
     */
    public function updateContactInfo(string $chatId, array $contact): bool
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return false;
        }

        $fieldsToColumns = [
            'name'          => 'name',
            'shortName'     => 'shortname',
            'profilePicUrl' => 'foto',
        ];
        if (Database::tableHasColumn($this->conn, 'persona', 'pushname')) {
            $fieldsToColumns['pushname'] = 'pushname';
        }

        $sets = [];
        $values = [];
        foreach ($fieldsToColumns as $contactKey => $column) {
            if (($contact[$contactKey] ?? '') !== '') {
                $sets[] = "{$column} = ?";
                $values[] = $contact[$contactKey];
            }
        }

        if ($sets === []) {
            return false;
        }

        $values[] = $number;
        $stmt = $this->conn->prepare('UPDATE persona SET ' . implode(', ', $sets) . ' WHERE `number` = ?');
        $stmt->execute($values);

        return true;
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

        foreach (['water_frequency', 'setting'] as $column) {
            try {
                $update = $this->conn->prepare("UPDATE persona SET {$column} = ? WHERE `number` = ?");
                $update->execute([$value, $number]);
                return $value;
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public function getWaterFrequency(string $chatId): ?int
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return null;
        }

        foreach (['water_frequency', 'setting'] as $column) {
            try {
                $stmt = $this->conn->prepare("SELECT {$column} FROM persona WHERE `number` = ? LIMIT 1");
                $stmt->execute([$number]);
                $value = $stmt->fetchColumn();

                return ($value !== false && $value !== null) ? (int)$value : null;
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * Returns the identifier for a number, creating a new persona row if
     * needed. $contact is whatever GreenApiClient::getContactInfo() returned
     * (any subset of name/shortName/pushname/profilePicUrl — all optional,
     * since Green API doesn't always have all of them for a given number).
     *
     * @param array{name?:string,shortName?:string,pushname?:string,profilePicUrl?:string} $contact
     */
    public function getOrCreateIdentifier(string $number, array $contact = []): string
    {
        $number = self::normalizePhone($number);
        if ($number === '') {
            throw new \RuntimeException('Número inválido para generar identifier.');
        }

        $found = $this->lookupIdentifier($number);
        if ($found !== null) {
            return $found;
        }

        $hasPushname = Database::tableHasColumn($this->conn, 'persona', 'pushname');

        $columns = ['`number`', 'name', 'shortname', 'foto'];
        $values = [
            $number,
            $contact['name'] ?? '',
            $contact['shortName'] ?? '',
            // "foto" holds the profile picture URL — NOT the pushname (a past
            // bug here meant it never stored an actual photo at all).
            $contact['profilePicUrl'] ?? '',
        ];

        if ($hasPushname) {
            $columns[] = 'pushname';
            $values[] = $contact['pushname'] ?? '';
        }

        // identifier/onboarding_step are appended after the loop below fills
        // in a fresh identifier each retry; values order must match $columns.
        $columns[] = 'identifier';
        $columns[] = 'onboarding_step';

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insert = $this->conn->prepare(
            sprintf('INSERT INTO persona (%s) VALUES (%s)', implode(', ', $columns), $placeholders)
        );

        // Collisions are very unlikely (6 chars over an 18-char alphabet) but
        // not impossible — retry with a fresh identifier a few times instead
        // of letting the UNIQUE-key violation bubble up as an uncaught 500.
        $lastException = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $identifier = $this->generateIdentifier();

            try {
                $insert->execute([...$values, $identifier, self::ONBOARDING_AWAITING_AGE]);
                return $identifier;
            } catch (\PDOException $e) {
                if (!self::isDuplicateKeyViolation($e)) {
                    throw $e;
                }
                $lastException = $e;
            }
        }

        throw new \RuntimeException(
            'No se pudo generar un identifier único tras varios intentos.',
            0,
            $lastException
        );
    }

    private static function isDuplicateKeyViolation(\PDOException $e): bool
    {
        // SQLSTATE 23000 = integrity constraint violation (MySQL error 1062
        // for duplicate key specifically, but the SQLSTATE alone is enough
        // here since this INSERT can only violate the identifier UNIQUE key
        // or the number PRIMARY KEY, and a duplicate number would already
        // have been caught by lookupIdentifier() above).
        return $e->getCode() === '23000';
    }

    /**
     * Current onboarding step for a chat. Defaults to "done" for rows that
     * predate this column (no onboarding to interrupt for already-active users).
     */
    public function getOnboardingStep(string $chatId): string
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return self::ONBOARDING_DONE;
        }

        $stmt = $this->conn->prepare(
            "SELECT COALESCE(onboarding_step, '" . self::ONBOARDING_DONE . "') FROM persona WHERE `number` = ? LIMIT 1"
        );
        $stmt->execute([$number]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string)$value : self::ONBOARDING_DONE;
    }

    public function setOnboardingStep(string $chatId, string $step): void
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return;
        }

        $stmt = $this->conn->prepare('UPDATE persona SET onboarding_step = ? WHERE `number` = ?');
        $stmt->execute([$step, $number]);
    }

    public function setAgeRange(string $chatId, string $ageRange): void
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return;
        }

        $stmt = $this->conn->prepare('UPDATE persona SET age_range = ? WHERE `number` = ?');
        $stmt->execute([$ageRange, $number]);
    }

    public function setWeightRange(string $chatId, string $weightRange): void
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return;
        }

        $stmt = $this->conn->prepare('UPDATE persona SET weight_range = ? WHERE `number` = ?');
        $stmt->execute([$weightRange, $number]);
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

        return $this->getOrCreateIdentifier($number, $contact);
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
        foreach (['water_frequency', 'setting'] as $column) {
            try {
                $stmt = $this->conn->query(
                    "SELECT `number`, name, shortname, identifier, {$column} AS water_frequency
                     FROM persona
                     WHERE {$column} IS NOT NULL AND {$column} > 0"
                );

                return $stmt->fetchAll();
            } catch (\Throwable) {
            }
        }

        return [];
    }

    /**
     * Everyone past onboarding — the pool eligible for the missed-meal reminder.
     *
     * @return array<int,array{number:string,identifier:string}>
     */
    public function findActivePersonas(): array
    {
        $stmt = $this->conn->query(
            "SELECT `number`, identifier FROM persona WHERE COALESCE(onboarding_step, '" . self::ONBOARDING_DONE . "') = '" . self::ONBOARDING_DONE . "'"
        );

        return $stmt->fetchAll();
    }

    /**
     * Whether this chat currently has an unanswered water-frequency list
     * message pending — set right before sending it, cleared once a reply
     * comes in (or once any other command is dispatched). Needed because,
     * unlike the meal-reminder/backdate flows, a water-frequency list_reply
     * carries a bare number as its rowId with nothing else to key off of.
     */
    public function getPendingWaterPoll(string $chatId): bool
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return false;
        }

        try {
            $stmt = $this->conn->prepare('SELECT pending_water_poll FROM persona WHERE `number` = ? LIMIT 1');
            $stmt->execute([$number]);

            return (bool)$stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    public function setPendingWaterPoll(string $chatId, bool $pending): void
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return;
        }

        try {
            $stmt = $this->conn->prepare('UPDATE persona SET pending_water_poll = ? WHERE `number` = ?');
            $stmt->execute([$pending ? 1 : 0, $number]);
        } catch (\Throwable) {
        }
    }

    /**
     * Which meal type's "did you forget?" reminder is currently outstanding
     * for this chat, if any — set right before sending the interactive
     * buttons, cleared once the reply is processed.
     */
    public function getPendingMealReminder(string $chatId): ?string
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return null;
        }

        $stmt = $this->conn->prepare('SELECT pending_meal_reminder FROM persona WHERE `number` = ? LIMIT 1');
        $stmt->execute([$number]);
        $value = $stmt->fetchColumn();

        return ($value !== false && $value !== null && $value !== '') ? (string)$value : null;
    }

    public function setPendingMealReminder(string $chatId, ?string $mealType): void
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return;
        }

        $stmt = $this->conn->prepare('UPDATE persona SET pending_meal_reminder = ? WHERE `number` = ?');
        $stmt->execute([$mealType, $number]);
    }

    /**
     * Which meal type someone is currently expected to describe by plain
     * text, after choosing "comí pero me olvidé de mandar la foto".
     */
    public function getPendingTextMeal(string $chatId): ?string
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return null;
        }

        $stmt = $this->conn->prepare('SELECT pending_text_meal FROM persona WHERE `number` = ? LIMIT 1');
        $stmt->execute([$number]);
        $value = $stmt->fetchColumn();

        return ($value !== false && $value !== null && $value !== '') ? (string)$value : null;
    }

    public function setPendingTextMeal(string $chatId, ?string $mealType): void
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return;
        }

        $stmt = $this->conn->prepare('UPDATE persona SET pending_text_meal = ? WHERE `number` = ?');
        $stmt->execute([$mealType, $number]);
    }

    /**
     * Current step of the "cargar" backdated-meal flow for this chat, if any
     * — see the BACKDATE_* constants above. Defaults to null (not in the
     * flow), same convention as getPendingMealReminder()/getPendingTextMeal().
     */
    public function getPendingBackdateStep(string $chatId): ?string
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return null;
        }

        $stmt = $this->conn->prepare('SELECT pending_backdate_step FROM persona WHERE `number` = ? LIMIT 1');
        $stmt->execute([$number]);
        $value = $stmt->fetchColumn();

        return ($value !== false && $value !== null && $value !== '') ? (string)$value : null;
    }

    public function getPendingBackdateDate(string $chatId): ?string
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return null;
        }

        $stmt = $this->conn->prepare('SELECT pending_backdate_date FROM persona WHERE `number` = ? LIMIT 1');
        $stmt->execute([$number]);
        $value = $stmt->fetchColumn();

        return ($value !== false && $value !== null && $value !== '') ? (string)$value : null;
    }

    public function getPendingBackdateMeal(string $chatId): ?string
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return null;
        }

        $stmt = $this->conn->prepare('SELECT pending_backdate_meal FROM persona WHERE `number` = ? LIMIT 1');
        $stmt->execute([$number]);
        $value = $stmt->fetchColumn();

        return ($value !== false && $value !== null && $value !== '') ? (string)$value : null;
    }

    /**
     * Advances (or starts/clears, when $step is null) the "cargar" flow,
     * optionally recording the chosen date and/or meal type at the same time
     * — one UPDATE per transition instead of three separate setters.
     */
    public function setPendingBackdate(string $chatId, ?string $step, ?string $date = null, ?string $mealType = null): void
    {
        $number = self::normalizePhone($chatId);
        if ($number === '') {
            return;
        }

        $stmt = $this->conn->prepare(
            'UPDATE persona
             SET pending_backdate_step = ?, pending_backdate_date = ?, pending_backdate_meal = ?
             WHERE `number` = ?'
        );
        $stmt->execute([$step, $date, $mealType, $number]);
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
