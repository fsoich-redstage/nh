<?php
declare(strict_types=1);

namespace NutriHelper\View;

/**
 * Renders the two pages the site's front door (public/index.php) can show:
 * the real meal-history "cards" page, and the promotional landing shown for
 * an invalid/empty identifier. Markup, CSS and JS ported as-is from the
 * production index.php — it was already tuned (filters, day separators,
 * scroll-snap, theme colors per meal type) so behavior is preserved rather
 * than redesigned.
 */
final class LandingRenderer
{
    /**
     * @param array<int,array<string,mixed>> $entries Chronological (oldest first).
     */
    public function renderHistory(string $headerName, string $identifier, array $entries, string $imagePublicPath): string
    {
        $displayName = $headerName !== '' ? $headerName : $identifier;
        $title = 'Nutri Helper' . ($displayName !== '' ? ' ' . $displayName : '');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $regCount = count($entries);
        $selfHref = '?identifier=' . rawurlencode($identifier);

        $cardsHtml = $entries === []
            ? "<p style='text-align:center; padding:20px;'>No hay registros.</p>"
            : $this->renderCards($entries, rtrim($imagePublicPath, '/'));

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<title>{$safeTitle}</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
{$this->css()}
</style>
</head>
<body>
<div class="topbar" id="topbar">
  <div class="wrapper">
    <div class="titlebar narrow">
      <a class="btn title-btn" href="{$selfHref}">{$safeTitle}</a>
      <span class="btn btn--meal" id="countPill" style="background:var(--btn-bg);color:var(--btn-text);border-color:var(--btn-border);">{$regCount} registros</span>
    </div>
    <div class="narrow">
      <div class="controls" id="controls">
        <div class="btnrow meals" role="group" aria-label="Filtros por comida">
          <button class="btn btn--meal" data-filter="DESAYUNO">Desayuno</button>
          <button class="btn btn--meal" data-filter="ALMUERZO">Almuerzo</button>
          <button class="btn btn--meal" data-filter="MERIENDA">Merienda</button>
          <button class="btn btn--meal" data-filter="CENA">Cena</button>
        </div>
        <div class="btnrow ctrls" role="group" aria-label="Controles">
          <button class="btn" id="last2w" data-active="true">Últimas 2 semanas</button>
          <button class="btn" id="sortToggle" data-state="desc">Orden: recientes primero</button>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="wrapper cards" id="cardsRoot">
{$cardsHtml}
</div>

<div class="wrapper">
  <div class="footer"></div>
</div>

<script>
{$this->js()}
</script>
</body>
</html>
HTML;
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     */
    private function renderCards(array $entries, string $imagePublicPath): string
    {
        $html = '';
        $lastYmd = null;
        $dayNumber = 0;
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

        foreach ($entries as $entry) {
            $fotoPath = $imagePublicPath . '/' . (string)($entry['foto'] ?? '') . '.jpg';
            $desc = htmlspecialchars((string)($entry['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8');

            $labCal = str_replace('Calorías', 'Cal', (string)($entry['calorias_label'] ?? ''));
            $labPro = str_replace('Proteínas', 'Prote', (string)($entry['proteinas_label'] ?? ''));
            $labCarb = str_replace('Carbohidratos', 'Carbo', (string)($entry['carbohidratos_label'] ?? ''));
            $labFat = (string)($entry['grasas_label'] ?? '');
            $labCal = htmlspecialchars($labCal, ENT_QUOTES, 'UTF-8');
            $labPro = htmlspecialchars($labPro, ENT_QUOTES, 'UTF-8');
            $labCarb = htmlspecialchars($labCarb, ENT_QUOTES, 'UTF-8');
            $labFat = htmlspecialchars($labFat, ENT_QUOTES, 'UTF-8');

            // The DB stores the insert instant as reported by the server's NOW();
            // production display subtracts a fixed 3h to land on Buenos Aires
            // local time, so we keep that same adjustment here.
            $fecha = new \DateTime((string)($entry['datetime'] ?? 'now'));
            $fecha->modify('-3 hours');
            $ymd = $fecha->format('Y-m-d');

            if ($lastYmd === null || $ymd !== $lastYmd) {
                $dayNumber++;
                if ($dayNumber > 1) {
                    $html .= '<div class="day-sep" aria-hidden="true"></div>';
                }
                $lastYmd = $ymd;
            }

            $dia = mb_strtoupper($dias[(int)$fecha->format('w')], 'UTF-8');
            $fechaTexto = $dia . ' ' . $fecha->format('d/m');
            $hora24 = $fecha->format('H:i');
            $ampm = ((int)$fecha->format('H') < 12) ? 'AM' : 'PM';
            $ts = $fecha->getTimestamp();

            [$comida, $theme] = $this->mealThemeForHour((int)$fecha->format('H'));

            $html .= <<<CARD
  <div class="card {$theme}" data-comida="{$comida}" data-ts="{$ts}">
    <div class="inner">
      <div class="header-grid">
        <div class="fecha header-el">{$fechaTexto}</div>
        <div class="pill pill-dia header-el" aria-label="Día">
          <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
            <line x1="3" y1="10" x2="21" y2="10"></line>
            <line x1="8" y1="2" x2="8" y2="6"></line>
            <line x1="16" y1="2" x2="16" y2="6"></line>
          </svg>
          <span>Dia {$dayNumber}</span>
        </div>
        <div class="comida header-el">{$comida}</div>
        <div class="pill pill-time header-el" aria-label="Hora">
          <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9"></circle>
            <polyline points="12,7 12,12 16,14"></polyline>
          </svg>
          <span>{$hora24} <span class="ampm">{$ampm}</span></span>
        </div>
      </div>
    </div>
    <img src="{$fotoPath}" alt="Foto {$desc}" loading="lazy" />
    <div class="descripcion">{$desc}</div>
    <div class="macros">

CARD;
            if ($labCal !== '') {
                $html .= '      <span class="macro-pill" title="Calorías"><span class="emoji" aria-hidden="true">🔥</span>' . $labCal . '</span>' . "\n";
            }
            if ($labPro !== '') {
                $html .= '      <span class="macro-pill" title="Proteínas"><span class="emoji" aria-hidden="true">🥩</span>' . $labPro . '</span>' . "\n";
            }
            if ($labFat !== '') {
                $html .= '      <span class="macro-pill" title="Grasas"><span class="emoji" aria-hidden="true">🧈</span>' . $labFat . '</span>' . "\n";
            }
            if ($labCarb !== '') {
                $html .= '      <span class="macro-pill" title="Carbohidratos"><span class="emoji" aria-hidden="true">🥖</span>' . $labCarb . '</span>' . "\n";
            }
            $html .= "    </div>\n  </div>\n";
        }

        return $html;
    }

    /**
     * @return array{0:string,1:string} [comida, css theme class]
     */
    private function mealThemeForHour(int $hour): array
    {
        return match (true) {
            $hour >= 8 && $hour < 12  => ['DESAYUNO', 'theme-desayuno'],
            $hour >= 12 && $hour < 15 => ['ALMUERZO', 'theme-almuerzo'],
            $hour >= 15 && $hour < 19 => ['MERIENDA', 'theme-merienda'],
            default                   => ['CENA', 'theme-cena'],
        };
    }

    public function renderPromo(string $botLink): string
    {
        $safeBotLink = htmlspecialchars($botLink, ENT_QUOTES, 'UTF-8');
        $phrase = htmlspecialchars(
            'Enviame una foto todos los días de tus 4 comidas con una breve descripción, y te devuelvo los datos clave.',
            ENT_QUOTES,
            'UTF-8'
        );

        $ctaHtml = $botLink !== '' && $botLink !== '#'
            ? '<p><a class="btn" href="' . $safeBotLink . '" target="_blank" rel="noopener noreferrer">Ir al bot</a></p>'
            : '<p><a class="btn disabled" href="#" aria-disabled="true">Ir al bot</a></p>'
              . '<div class="note">Configurá NUTRI_BOT_WHATSAPP_LINK en .env.</div>';

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0b0f14">
<title>Nutri Helper</title>
<style>
html,body{height:100%;margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;background:#0b0f14;color:#e5e7eb}
.wrap{min-height:100%;display:block;max-width:980px;margin:72px auto 24px;padding:0 16px}
.card{width:100%;min-height:220px;background:#101826;border-radius:20px;box-shadow:0 12px 36px rgba(0,0,0,.4);padding:24px 28px;text-align:center;border:1px solid rgba(255,255,255,.06)}
.title{font-size:1.6rem;margin:6px 0 10px;color:#f3f4f6;font-weight:800;letter-spacing:.2px}
.sub{font-size:1.04rem;opacity:.92;margin:0 0 24px;color:#d1d5db;line-height:1.65}
.btn{display:inline-block;background:#22c55e;color:#0b0f14;text-decoration:none;padding:12px 20px;border-radius:16px;font-weight:800;min-width:220px;box-shadow:0 6px 18px rgba(34,197,94,.25)}
.btn:hover{filter:brightness(.96)}
.btn.disabled{pointer-events:none;opacity:.5}
.note{margin-top:12px;font-size:.9rem;color:#9ca3af}
</style>
</head>
<body>
<div class="wrap"><div class="card">
<h1 class="title">Nutri Helper</h1>
<p class="sub">{$phrase}</p>
{$ctaHtml}
</div></div>
</body>
</html>
HTML;
    }

    private function css(): string
    {
        return <<<CSS
:root{
  --bg:#121212;
  --fg:#f1f1f1;
  --card-bg:#1e1e1e;
  --border:#2b2b2b;
  --desayuno:#f1c40f;
  --almuerzo:#2ecc71;
  --merienda:#e67e22;
  --cena:#64b5f6;
  --btn-bg:#1a1a1a;
  --btn-border:#3a3a3a;
  --btn-text:#eaeaea;
  --weeks-active:#B698FF;
  --order-desc:#00BFA6;
  --order-asc:#FF9DBB;
  --row-gap:10px;
  --btn-radius:10px;
  --shadow1:0 1px 2px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.06);
  --header-h:140px;
}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--fg);text-rendering:optimizeLegibility}
.wrapper{max-width:820px;margin:0 auto}
.narrow{max-width:520px;margin:0 auto}
.topbar{position:sticky;top:0;z-index:10;background:var(--bg);padding:8px 12px 6px;backdrop-filter:saturate(120%) blur(2px)}
.btn{appearance:none;cursor:pointer;width:100%;padding:12px 14px;border-radius:var(--btn-radius);background:var(--btn-bg);color:var(--btn-text);border:1px solid var(--btn-border);font-weight:800;letter-spacing:.2px;text-align:center;box-shadow:var(--shadow1);transition:transform .06s ease, box-shadow .2s ease, filter .15s ease;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;line-height:1;font-size:.95rem;user-select:none}
.btn:hover{filter:brightness(1.03)}
.btn:active{transform:scale(.98)}
.titlebar{display:grid;grid-template-columns:repeat(4,1fr);align-items:center;gap:var(--row-gap);margin:2px auto var(--row-gap)}
.title-btn{grid-column:1 / span 3;font-size:1.05rem;color:#eac54f}
.count-pill{grid-column:4;pointer-events:none;user-select:none}
.controls{display:grid;grid-template-columns:1fr;gap:var(--row-gap)}
.btnrow{display:grid;gap:var(--row-gap)}
.btnrow.meals{grid-template-columns:repeat(4,1fr)}
.btnrow.ctrls{grid-template-columns:repeat(2,1fr)}
.btn--meal[data-filter="DESAYUNO"][data-active="true"]{background:var(--desayuno);color:#000;border-color:#000}
.btn--meal[data-filter="ALMUERZO"][data-active="true"]{background:var(--almuerzo);color:#000;border-color:#000}
.btn--meal[data-filter="MERIENDA"][data-active="true"]{background:var(--merienda);color:#000;border-color:#000}
.btn--meal[data-filter="CENA"][data-active="true"]{background:var(--cena);color:#000;border-color:#000}
#last2w[data-active="true"]{background:var(--weeks-active);color:#1a1133;border-color:#6d5bb2}
#sortToggle[data-state="desc"]{background:var(--order-desc);color:#002a25;border-color:#0a5f4f}
#sortToggle[data-state="asc"]{background:var(--order-asc);color:#3a0010;border-color:#7a2746}
@keyframes flashPulse{0%{box-shadow:0 0 0 rgba(255,255,255,0);transform:scale(1)}40%{box-shadow:0 0 18px rgba(255,255,255,.25);transform:scale(1.02)}100%{box-shadow:0 0 0 rgba(255,255,255,0);transform:scale(1)}}
.btn.flash{animation:flashPulse .35s ease-out}
.cards{padding:22px 12px 12px}
.card{background:var(--card-bg);border-radius:var(--btn-radius);box-shadow:0 6px 20px rgba(0,0,0,0.35);border:1px solid var(--border);margin:12px auto;padding:16px 16px 20px;max-width:520px;display:flex;flex-direction:column;gap:10px;scroll-margin-top:var(--header-h)}
.inner{width:84%;margin:0 auto}
.header-grid{display:grid;grid-template-columns:1fr auto;grid-template-rows:auto auto;gap:8px 12px;align-items:center}
.header-el{font-size:1.05rem;font-weight:800;letter-spacing:.3px}
.fecha{grid-column:1;grid-row:1;text-transform:uppercase}
.comida{grid-column:1;grid-row:2;margin:0;font-weight:800;letter-spacing:.2px}
.pill{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:6px 12px;border-radius:10px;line-height:1;font-weight:800;font-size:1.05rem;white-space:nowrap;height:32px;min-width:120px;box-shadow:var(--shadow1);backdrop-filter:saturate(140%) blur(4px);border:1px solid transparent}
.pill svg{width:18px;height:18px;display:block}
.pill-dia{grid-column:2;grid-row:1;background:linear-gradient(180deg,#232323,#1a1a1a);border:1px solid #555;color:#eaeaea}
.pill-time{grid-column:2;grid-row:2;background:var(--desayuno);color:#000;border-color:rgba(0,0,0,.35)}
.ampm{font-size:.78em;opacity:.9;margin-left:2px;font-weight:800}
.card img{width:84%;height:auto;border-radius:10px;border:1px solid var(--border);margin:0 auto;display:block;box-shadow:0 8px 24px rgba(0,0,0,.35)}
.descripcion{margin:8px auto 0;padding:8px 14px;width:84%;border-radius:10px;font-weight:800;letter-spacing:.2px;line-height:1.25;font-size:1.05rem;text-align:center;border:1px solid rgba(0,0,0,.3);box-shadow:0 2px 6px rgba(0,0,0,.25)}
.macros{width:84%;margin:8px auto 0;display:grid;grid-template-columns:repeat(2,1fr);gap:8px;align-items:stretch}
.macro-pill{display:flex;align-items:center;justify-content:center;gap:6px;padding:6px 10px;height:34px;width:100%;border-radius:10px;font-weight:700;font-size:.92rem;line-height:1;text-align:center;background:linear-gradient(180deg,#202020,#171717);border:1px solid var(--border);color:#eaeaea;white-space:nowrap}
.macro-pill .emoji{font-size:.95rem;line-height:1}
.theme-desayuno .comida{color:var(--desayuno)}
.theme-almuerzo .comida{color:var(--almuerzo)}
.theme-merienda .comida{color:var(--merienda)}
.theme-cena .comida{color:var(--cena)}
.theme-desayuno .pill-time,.theme-desayuno .descripcion{background:var(--desayuno);color:#000}
.theme-almuerzo .pill-time,.theme-almuerzo .descripcion{background:var(--almuerzo);color:#000}
.theme-merienda .pill-time,.theme-merienda .descripcion{background:var(--merienda);color:#000}
.theme-cena .pill-time,.theme-cena .descripcion{background:var(--cena);color:#000}
.day-sep{max-width:520px;padding:18px 0;margin:0 auto}
.day-sep::before{content:"";display:block;width:92%;height:4px;margin:0 auto;background:#7a7a7a;border-radius:2px}
.footer{margin:16px 0 32px;text-align:center;color:#cfcfcf;font-size:.95rem}
.footer .muted{color:#9a9a9a}
@media(max-width:680px){.btnrow.meals{grid-template-columns:repeat(2,1fr)}.btnrow.ctrls{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.cards{padding:20px 10px 10px}.card{margin:10px auto;padding:14px}.header-el,.pill,.descripcion{font-size:1rem}.pill{padding:5px 10px;height:30px;min-width:110px}.inner,.card img,.descripcion,.macros{width:90%}.macro-pill{height:36px;font-size:.96rem}.day-sep{padding:14px 0}.day-sep::before{width:94%}}
html, body { width: 100%; max-width: 100%; overflow-x: hidden; overscroll-behavior-x: none; }
img, video, canvas { max-width: 100%; height: auto; display: block; }
CSS;
    }

    private function js(): string
    {
        return <<<'JS'
let activeFilters = new Set();
let last2w = true;
let sortOrder = 'desc';

const cardsRoot = document.getElementById('cardsRoot');
const controls  = document.getElementById('controls');
const btnLast2w = document.getElementById('last2w');
const btnSort   = document.getElementById('sortToggle');
const topbar    = document.getElementById('topbar');
const countPill = document.getElementById('countPill');

const allCards = () => Array.from(cardsRoot.querySelectorAll('.card'));

const setHeaderHeightVar = () => {
  const h = topbar.getBoundingClientRect().height;
  const fixed = Math.round(h + 8);
  document.documentElement.style.setProperty('--header-h', fixed + 'px');
  document.querySelectorAll('.card').forEach(c => c.style.scrollMarginTop = fixed + 'px');
};

const dayKeyFromTs = (ts) => new Date(ts * 1000).toISOString().slice(0,10);

function rebuildDaySeparators() {
  cardsRoot.querySelectorAll('.day-sep').forEach(s => s.remove());
  const vis = allCards().filter(c => c.style.display !== 'none');
  let prevKey = null;
  vis.forEach((card, idx) => {
    const ts = parseInt(card.getAttribute('data-ts'), 10) || 0;
    const key = dayKeyFromTs(ts);
    if (idx > 0 && key !== prevKey) {
      const sep = document.createElement('div');
      sep.className = 'day-sep';
      sep.setAttribute('aria-hidden', 'true');
      cardsRoot.insertBefore(sep, card);
    }
    prevKey = key;
  });
}

function firstVisibleCard(){
  return allCards().find(c => c.style.display !== 'none') || null;
}

function scrollToCardExact(card){
  const headerH = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--header-h')) || 140;
  const target = card.offsetTop - headerH;
  window.scrollTo({ top: target, behavior: 'auto' });
}

let lastScrollY = 0;
function snapIfNearTop(){
  const y = window.scrollY || 0;
  const dirUp = y < lastScrollY;
  lastScrollY = y;
  const card = firstVisibleCard();
  if (!dirUp || !card) return;
  const headerH = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--header-h')) || 140;
  const target  = card.offsetTop - headerH;
  const delta   = target - y;
  if (delta > 0 && delta <= 24) {
    window.scrollTo({ top: target, behavior: 'auto' });
  }
}

function updateCounter(visibleCount){
  countPill.textContent = `${visibleCount} registro${visibleCount===1?'':'s'}`;
}

function applyFilters() {
  const nowSec = Math.floor(Date.now()/1000);
  const threshold = nowSec - (14*24*60*60);

  allCards().forEach(card => {
    const meal = card.getAttribute('data-comida');
    const ts   = parseInt(card.getAttribute('data-ts'),10) || 0;
    let visible = true;
    if (activeFilters.size > 0 && !activeFilters.has(meal)) visible = false;
    if (last2w && ts < threshold) visible = false;
    card.style.display = visible ? '' : 'none';
  });

  const visibleCards = allCards().filter(c => c.style.display !== 'none');
  visibleCards.sort((a,b) => {
    const ta = parseInt(a.getAttribute('data-ts'),10) || 0;
    const tb = parseInt(b.getAttribute('data-ts'),10) || 0;
    return sortOrder === 'desc' ? (tb - ta) : (ta - tb);
  });
  visibleCards.forEach(c => cardsRoot.appendChild(c));

  rebuildDaySeparators();
  updateCounter(visibleCards.length);

  if (visibleCards.length > 0) {
    scrollToCardExact(visibleCards[0]);
    lastScrollY = window.scrollY || 0;
  }
}

function flash(btn){
  btn.classList.remove('flash');
  void btn.offsetWidth;
  btn.classList.add('flash');
  setTimeout(()=>btn.classList.remove('flash'), 350);
}

if (controls) {
  controls.addEventListener('click', (e) => {
    const btn = e.target.closest('button.btn');
    if (!btn) return;

    const filter = btn.getAttribute('data-filter');
    if (filter) {
      if (activeFilters.has(filter)) {
        activeFilters.delete(filter);
        btn.removeAttribute('data-active');
      } else {
        activeFilters.add(filter);
        btn.setAttribute('data-active','true');
      }
      flash(btn);
      applyFilters();
      return;
    }

    if (btn === btnLast2w) {
      last2w = !last2w;
      btnLast2w.setAttribute('data-active', last2w ? 'true' : 'false');
      flash(btnLast2w);
      applyFilters();
      return;
    }

    if (btn === btnSort) {
      if (sortOrder === 'desc') {
        sortOrder = 'asc';
        btnSort.setAttribute('data-state','asc');
        btnSort.textContent = 'Orden: antiguos primero';
      } else {
        sortOrder = 'desc';
        btnSort.setAttribute('data-state','desc');
        btnSort.textContent = 'Orden: recientes primero';
      }
      flash(btnSort);
      applyFilters();
      return;
    }
  });
}

window.addEventListener('scroll', snapIfNearTop, {passive:true});

(function init(){
  setHeaderHeightVar();
  applyFilters();
})();
window.addEventListener('resize', () => {
  setHeaderHeightVar();
  snapIfNearTop();
});
JS;
    }
}
