<?php
declare(strict_types=1);

namespace NutriHelper\View;

/**
 * Renders the two admin-only pages: the full user list (public/admin/index.php)
 * and one user's full contact info + meal history with photos
 * (public/admin/persona.php). Both are gated by AdminAuth — this view never
 * decides who's allowed to see it, only how to show it.
 */
final class AdminRenderer
{
    /**
     * @param array<int,array<string,mixed>> $users From PersonaRepository::findAllWithStats().
     */
    public function renderUserList(array $users): string
    {
        $rows = '';
        foreach ($users as $user) {
            $identifier = (string)$user['identifier'];
            $displayName = $this->displayName($user);
            $avatar = $this->avatarHtml((string)($user['foto'] ?? ''), $displayName);
            $phone = htmlspecialchars((string)$user['number'], ENT_QUOTES, 'UTF-8');
            $onboarding = htmlspecialchars((string)($user['onboarding_step'] ?? ''), ENT_QUOTES, 'UTF-8');
            $age = htmlspecialchars((string)($user['age_range'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8');
            $weight = htmlspecialchars((string)($user['weight_range'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8');
            $water = ((int)($user['water_frequency'] ?? 0)) > 0
                ? (int)$user['water_frequency'] . 'x/día'
                : 'off';
            $totalMeals = (int)($user['total_meals'] ?? 0);
            $lastMealAt = $user['last_meal_at'] ?? null;
            $lastActivity = $lastMealAt !== null
                ? htmlspecialchars($this->formatLocalDatetime((string)$lastMealAt), ENT_QUOTES, 'UTF-8')
                : 'nunca';
            $safeIdentifier = htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8');

            $rows .= <<<ROW
<tr>
  <td class="avatar-cell">{$avatar}</td>
  <td>
    <a class="name-link" href="persona.php?identifier={$safeIdentifier}">{$displayName}</a>
    <div class="muted">{$safeIdentifier}</div>
  </td>
  <td>{$phone}</td>
  <td><span class="badge badge--{$onboarding}">{$onboarding}</span></td>
  <td>{$age}</td>
  <td>{$weight}</td>
  <td>{$water}</td>
  <td><span class="count-pill">{$totalMeals}</span></td>
  <td>{$lastActivity}</td>
  <td><a class="btn-small" href="../?identifier={$safeIdentifier}" target="_blank" rel="noopener noreferrer">Historial público ↗</a></td>
</tr>
ROW;
        }

        $total = count($users);

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<title>Nutri Helper — Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
{$this->css()}
</style>
</head>
<body>
<div class="wrap">
  <h1>Nutri Helper — Usuarios <span class="muted">({$total})</span></h1>
  <div class="table-scroll">
  <table>
    <thead>
      <tr>
        <th></th><th>Nombre</th><th>Teléfono</th><th>Onboarding</th>
        <th>Edad</th><th>Peso</th><th>Agua</th><th>Comidas</th><th>Última actividad</th><th></th>
      </tr>
    </thead>
    <tbody>
{$rows}
    </tbody>
  </table>
  </div>
</div>
</body>
</html>
HTML;
    }

    /**
     * @param array<string,mixed> $persona From PersonaRepository::findByIdentifier().
     * @param array<int,array<string,mixed>> $entries From NutritionRepository::fetchEntriesForIdentifier(), chronological (oldest first).
     */
    public function renderUserDetail(array $persona, array $entries, string $imagePublicPath): string
    {
        $identifier = (string)$persona['identifier'];
        $displayName = $this->displayName($persona);
        $avatar = $this->avatarHtml((string)($persona['foto'] ?? ''), $displayName, true);
        $phone = htmlspecialchars((string)$persona['number'], ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string)($persona['name'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8');
        $shortname = htmlspecialchars((string)($persona['shortname'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8');
        $pushname = htmlspecialchars((string)($persona['pushname'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8');
        $onboarding = htmlspecialchars((string)($persona['onboarding_step'] ?? ''), ENT_QUOTES, 'UTF-8');
        $age = htmlspecialchars((string)($persona['age_range'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8');
        $weight = htmlspecialchars((string)($persona['weight_range'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8');
        $water = ((int)($persona['water_frequency'] ?? 0)) > 0
            ? (int)$persona['water_frequency'] . ' veces/día'
            : 'desactivado';
        $safeIdentifier = htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8');

        $orderedEntries = array_reverse($entries);
        $cards = $orderedEntries === []
            ? '<p class="muted">Sin comidas registradas todavía.</p>'
            : $this->renderEntryCards($orderedEntries, rtrim($imagePublicPath, '/'));

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<title>Nutri Helper — {$displayName}</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
{$this->css()}
</style>
</head>
<body>
<div class="wrap">
  <p><a class="btn-small" href="index.php">&larr; Volver al listado</a></p>
  <div class="contact-card">
    {$avatar}
    <div>
      <h1>{$displayName}</h1>
      <div class="muted">{$safeIdentifier} · {$phone}</div>
      <div class="contact-grid">
        <div><span class="muted">Nombre:</span> {$name}</div>
        <div><span class="muted">Shortname:</span> {$shortname}</div>
        <div><span class="muted">Pushname:</span> {$pushname}</div>
        <div><span class="muted">Onboarding:</span> <span class="badge badge--{$onboarding}">{$onboarding}</span></div>
        <div><span class="muted">Edad:</span> {$age}</div>
        <div><span class="muted">Peso:</span> {$weight}</div>
        <div><span class="muted">Agua:</span> {$water}</div>
        <div><a href="../?identifier={$safeIdentifier}" target="_blank" rel="noopener noreferrer">Ver historial público ↗</a></div>
      </div>
    </div>
  </div>

  <h2>Comidas registradas <span class="muted">({$this->count($orderedEntries)})</span></h2>
  <div class="entries">
{$cards}
  </div>
</div>
</body>
</html>
HTML;
    }

    private function count(array $entries): int
    {
        return count($entries);
    }

    /**
     * @param array<string,mixed> $user
     */
    private function displayName(array $user): string
    {
        $candidates = [$user['shortname'] ?? '', $user['pushname'] ?? '', $user['name'] ?? ''];
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return htmlspecialchars($candidate, ENT_QUOTES, 'UTF-8');
            }
        }

        return '(sin nombre)';
    }

    private function avatarHtml(string $photoUrl, string $safeDisplayNameHtml, bool $large = false): string
    {
        $size = $large ? 96 : 40;
        $photoUrl = trim($photoUrl);

        if ($photoUrl !== '' && preg_match('#^https?://#i', $photoUrl)) {
            $safeUrl = htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8');

            return "<img class=\"avatar\" style=\"width:{$size}px;height:{$size}px\" src=\"{$safeUrl}\" alt=\"\" loading=\"lazy\" />";
        }

        $initial = htmlspecialchars(mb_strtoupper(mb_substr(strip_tags($safeDisplayNameHtml), 0, 1, 'UTF-8'), 'UTF-8'), ENT_QUOTES, 'UTF-8');

        return "<div class=\"avatar avatar--placeholder\" style=\"width:{$size}px;height:{$size}px;line-height:{$size}px;font-size:" . (int)($size / 2) . "px\">{$initial}</div>";
    }

    /**
     * @param array<int,array<string,mixed>> $entries Newest first.
     */
    private function renderEntryCards(array $entries, string $imagePublicPath): string
    {
        $html = '';
        foreach ($entries as $entry) {
            $foto = (string)($entry['foto'] ?? '');
            $fotoPath = $foto !== '' ? $imagePublicPath . '/' . $foto . '.jpg' : '';
            $desc = htmlspecialchars((string)($entry['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8');
            $comida = htmlspecialchars((string)($entry['comida'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8');
            $source = htmlspecialchars((string)($entry['source'] ?? ''), ENT_QUOTES, 'UTF-8');

            $fechaLocal = $this->formatLocalDatetime((string)($entry['datetime'] ?? 'now'));
            $fechaSafe = htmlspecialchars($fechaLocal, ENT_QUOTES, 'UTF-8');

            $calLabel = htmlspecialchars((string)($entry['calorias_label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $proLabel = htmlspecialchars((string)($entry['proteinas_label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $carbLabel = htmlspecialchars((string)($entry['carbohidratos_label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $fatLabel = htmlspecialchars((string)($entry['grasas_label'] ?? ''), ENT_QUOTES, 'UTF-8');

            $imgHtml = $fotoPath !== ''
                ? "<a href=\"{$fotoPath}\" target=\"_blank\" rel=\"noopener noreferrer\"><img class=\"entry-photo\" src=\"{$fotoPath}\" alt=\"\" loading=\"lazy\" /></a>"
                : '<div class="muted">📝 Cargado por texto (sin foto)</div>';

            $html .= <<<CARD
  <div class="entry-card">
    <div class="entry-header">
      <span class="entry-date">{$fechaSafe}</span>
      <span class="entry-meal">{$comida}</span>
      <span class="muted">{$source}</span>
    </div>
    {$imgHtml}
    <div class="entry-desc">{$desc}</div>
    <div class="entry-macros">
      <span>{$calLabel}</span><span>{$proLabel}</span><span>{$carbLabel}</span><span>{$fatLabel}</span>
    </div>
  </div>
CARD;
        }

        return $html;
    }

    /**
     * DB stores the insert instant as reported by the server's NOW() in what
     * is effectively America/Argentina/Buenos_Aires local time already (see
     * NutritionRepository) — displayed as-is, no further conversion needed.
     */
    private function formatLocalDatetime(string $datetime): string
    {
        try {
            $date = new \DateTime($datetime);
        } catch (\Exception) {
            return $datetime;
        }

        return $date->format('d/m/Y H:i');
    }

    private function css(): string
    {
        return <<<CSS
:root{--bg:#0b0f14;--fg:#e5e7eb;--card:#101826;--border:#1f2937;--muted:#9ca3af;--accent:#22c55e}
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--fg)}
.wrap{max-width:1100px;margin:0 auto;padding:20px 16px 60px}
h1{font-size:1.4rem;margin:0 0 4px}
h2{font-size:1.15rem;margin:24px 0 10px}
.muted{color:var(--muted);font-size:.85em}
a{color:var(--accent)}
.table-scroll{overflow-x:auto;border-radius:12px;border:1px solid var(--border)}
table{border-collapse:collapse;width:100%;min-width:820px}
th,td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border);vertical-align:middle;font-size:.9rem}
th{background:#0f1620;color:var(--muted);font-weight:700;position:sticky;top:0}
tr:hover td{background:rgba(255,255,255,.02)}
.avatar-cell{width:48px}
.avatar{border-radius:50%;object-fit:cover;display:block;background:#1f2937}
.avatar--placeholder{display:flex;align-items:center;justify-content:center;background:#1f2937;color:#eaeaea;font-weight:800}
.name-link{font-weight:700;text-decoration:none}
.badge{display:inline-block;padding:3px 8px;border-radius:8px;font-size:.75rem;font-weight:700;background:#1f2937;color:#eaeaea}
.badge--done{background:#14532d;color:#86efac}
.badge--awaiting_age,.badge--awaiting_weight{background:#78350f;color:#fcd34d}
.count-pill{display:inline-block;min-width:26px;text-align:center;padding:2px 8px;border-radius:999px;background:#1f2937;font-weight:700}
.btn-small{display:inline-block;padding:6px 10px;border-radius:8px;background:#1f2937;text-decoration:none;font-size:.8rem;white-space:nowrap}
.contact-card{display:flex;gap:20px;align-items:flex-start;background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:12px}
.contact-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:6px 24px;margin-top:10px;font-size:.92rem}
.entries{display:grid;gap:14px}
.entry-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px;max-width:420px}
.entry-header{display:flex;justify-content:space-between;gap:10px;font-weight:700;margin-bottom:8px;font-size:.9rem}
.entry-photo{width:100%;border-radius:10px;display:block;margin-bottom:8px}
.entry-desc{font-weight:600;margin-bottom:8px}
.entry-macros{display:flex;flex-wrap:wrap;gap:8px;font-size:.8rem;color:var(--muted)}
@media(max-width:640px){.contact-card{flex-direction:column}.contact-grid{grid-template-columns:1fr}}
CSS;
    }
}
