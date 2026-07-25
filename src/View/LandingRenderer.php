<?php
declare(strict_types=1);

namespace NutriHelper\View;

final class LandingRenderer
{
    /**
     * @param array<int,array<string,mixed>> $entries
     */
    public function render(string $identifier, array $entries): string
    {
        $rows = '';
        foreach ($entries as $entry) {
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                htmlspecialchars((string)($entry['datetime'] ?? '')),
                htmlspecialchars((string)($entry['descripcion'] ?? '')),
                htmlspecialchars((string)($entry['calorias_label'] ?? '')),
                htmlspecialchars((string)($entry['proteinas_label'] ?? '')),
                htmlspecialchars((string)($entry['carbohidratos_label'] ?? '')),
                htmlspecialchars((string)($entry['grasas_label'] ?? ''))
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6">Todavía no hay comidas registradas.</td></tr>';
        }

        $safeIdentifier = htmlspecialchars($identifier);

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Nutri Helper — Historial {$safeIdentifier}</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 2em; background: #f7f7f7; color: #222; }
  table { border-collapse: collapse; width: 100%; background: #fff; }
  th, td { padding: 8px 12px; border-bottom: 1px solid #ddd; text-align: left; font-size: 0.9em; }
  th { background: #eee; }
</style>
</head>
<body>
<h1>Historial nutricional — {$safeIdentifier}</h1>
<table>
  <thead>
    <tr><th>Fecha</th><th>Descripción</th><th>Calorías</th><th>Proteínas</th><th>Carbohidratos</th><th>Grasas</th></tr>
  </thead>
  <tbody>
    {$rows}
  </tbody>
</table>
</body>
</html>
HTML;
    }

    public function renderInvalidIdentifier(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><title>Nutri Helper</title></head>
<body>
<h1>Identificador inválido</h1>
<p>No encontramos un historial para ese identificador. Verificá el link que te envió el bot.</p>
</body>
</html>
HTML;
    }
}
