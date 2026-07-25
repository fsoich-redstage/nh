<?php
declare(strict_types=1);

namespace NutriHelper\Domain;

/**
 * Parses the free-text nutrition analysis returned by OpenAI into structured
 * fields. Regexes ported as-is from the previous implementation
 * (extract_nutrition_uniform_labels) — they were already tuned against real
 * model output, so behavior is preserved rather than rewritten.
 */
final class NutritionAnalysisParser
{
    /**
     * @return array{
     *     calories: array{label:?string,value:?int},
     *     protein: array{label:?string,value:?int},
     *     carbs: array{label:?string,value:?int},
     *     fat: array{label:?string,value:?int},
     *     nota: array{label:?string,value:?string}
     * }
     */
    public function extract(string $text): array
    {
        $t = $text;
        $t = preg_replace('/[*_`]+/u', '', $t);
        $t = preg_replace('/[ \t]+/u', ' ', $t);
        $t = preg_replace('/\h+/u', ' ', $t);
        $t = str_replace(["\r\n", "\r"], "\n", $t);

        $buildOut = function (string $title, ?string $n1Raw, ?string $n2Raw, ?string $unit, string $defaultUnit): array {
            if ($n1Raw === null || $n1Raw === '') {
                return ['label' => null, 'value' => null];
            }

            $unitResolved = $unit !== null && $unit !== '' ? strtolower($unit) : $defaultUnit;
            if ($unitResolved === 'cal') {
                $unitResolved = 'kcal';
            }

            $n1 = (float)str_replace(',', '.', $n1Raw);
            $value = $n1;
            $hasRange = $n2Raw !== null && $n2Raw !== '';
            if ($hasRange) {
                $n2 = (float)str_replace(',', '.', $n2Raw);
                $value = ($n1 + $n2) / 2;
            }
            $value = (int)round($value);

            $rangeText = $hasRange ? (trim($n1Raw) . '–' . trim($n2Raw)) : trim($n1Raw);
            $label = sprintf('%s: %s %s', $title, $rangeText, $unitResolved);

            return ['label' => $label, 'value' => $value];
        };

        $num = '(?P<n1>\d{1,4}(?:[.,]\d{1,2})?)';
        $num2 = '(?P<n2>\d{1,4}(?:[.,]\d{1,2})?)';
        $sep = '(?:-|–| a )';

        $rxCaloriesKw = '/
            calor[ií]as?
            (?:\s+(?![:\-–])[^0-9\n]{0,30})*
            \s*[:\-–]\s*
            ' . $num . '
            (?:\s*' . $sep . '\s*' . $num2 . ')?
            \s*(?P<unit>k?cal)?
        /iux';

        $rxProteinKw = '/
            prote[ií]nas?
            (?:\s+(?![:\-–])[^0-9\n]{0,30})*
            \s*[:\-–]\s*
            ' . $num . '
            (?:\s*' . $sep . '\s*' . $num2 . ')?
            \s*(?P<unit>g)?
        /iux';

        $rxCarbsKw = '/
            carbohidratos?
            (?:\s+(?![:\-–])[^0-9\n]{0,30})*
            \s*[:\-–]\s*
            ' . $num . '
            (?:\s*' . $sep . '\s*' . $num2 . ')?
            \s*(?P<unit>g)?
        /iux';

        $rxFatKw = '/
            grasas?
            (?:\s+(?![:\-–])[^0-9\n]{0,30})*
            \s*[:\-–]\s*
            ' . $num . '
            (?:\s*' . $sep . '\s*' . $num2 . ')?
            \s*(?P<unit>g)?
        /iux';

        $rxCaloriesVal = '/
            \b' . $num . '
            (?:\s*' . $sep . '\s*' . $num2 . ')?
            \s*(?P<unit>kcal|cal)\b
        /iux';

        $rxGramsVal = '/
            \b' . $num . '
            (?:\s*' . $sep . '\s*' . $num2 . ')?
            \s*(?P<unit>g)\b
        /iux';

        $matchLast = function (string $kwRegex, string $valRegex) use ($t): ?array {
            if (preg_match_all($kwRegex, $t, $m1, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) && $m1 !== []) {
                return end($m1);
            }
            if (preg_match_all($valRegex, $t, $m2, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) && $m2 !== []) {
                return end($m2);
            }

            return null;
        };

        $outCal = ['label' => null, 'value' => null];
        if ($mCal = $matchLast($rxCaloriesKw, $rxCaloriesVal)) {
            $outCal = $buildOut('Calorías', $mCal['n1'] ?? null, $mCal['n2'] ?? null, $mCal['unit'] ?? null, 'kcal');
        }

        $outPro = ['label' => null, 'value' => null];
        if ($mPro = $matchLast($rxProteinKw, $rxGramsVal)) {
            $outPro = $buildOut('Proteínas', $mPro['n1'] ?? null, $mPro['n2'] ?? null, $mPro['unit'] ?? null, 'g');
        }

        $outCarb = ['label' => null, 'value' => null];
        if ($mCarb = $matchLast($rxCarbsKw, $rxGramsVal)) {
            $outCarb = $buildOut('Carbohidratos', $mCarb['n1'] ?? null, $mCarb['n2'] ?? null, $mCarb['unit'] ?? null, 'g');
        }

        $outFat = ['label' => null, 'value' => null];
        if ($mFat = $matchLast($rxFatKw, $rxGramsVal)) {
            $outFat = $buildOut('Grasas', $mFat['n1'] ?? null, $mFat['n2'] ?? null, $mFat['unit'] ?? null, 'g');
        }

        $outNota = ['label' => null, 'value' => null];
        $rxNota = '/^\s*nota(?:\s+(?:extra|final))?\s*[:\-–]\s*(.+)\s*$/imu';
        if (preg_match_all($rxNota, $t, $mNota, PREG_SET_ORDER) && $mNota !== []) {
            $value = trim(end($mNota)[1]);
            if ($value !== '') {
                $outNota = ['label' => 'Nota: ' . $value, 'value' => $value];
            }
        }

        return [
            'calories' => $outCal,
            'protein'  => $outPro,
            'carbs'    => $outCarb,
            'fat'      => $outFat,
            'nota'     => $outNota,
        ];
    }

    /**
     * Text preceding the macro breakdown (the free-form "note" about the photo).
     */
    public function extractNote(string $analysis): string
    {
        $t = trim($analysis);
        if ($t === '') {
            return '';
        }

        if (preg_match('/^(.*?)(?:Calor[ií]as?:|Consejo\s+actual:|Consejo\s+pr[oó]xima)/isu', $t, $m)) {
            return trim($m[1]);
        }

        foreach (preg_split('/\r?\n/', $t) as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/calor[ií]as|prote[ií]nas|grasas|carbohidratos/i', $line)) {
                continue;
            }

            return $line;
        }

        return $t;
    }

    /**
     * @return array{actual:string,proxima:string}
     */
    public function extractAdvices(string $analysis): array
    {
        $actual = '';
        $proxima = '';

        if (preg_match('/Consejo\s+actual:\s*(.+?)(?:\r?\n|$)/isu', $analysis, $m)) {
            $actual = trim($m[1]);
        }

        if (preg_match('/Consejo\s+pr[oó]xima\s+comida[^\:]*:\s*(.+?)(?:\r?\n|$)/isu', $analysis, $m)) {
            $proxima = trim($m[1]);
        } elseif (preg_match('/Consejo\s+pr[oó]ximo:\s*(.+?)(?:\r?\n|$)/isu', $analysis, $m)) {
            $proxima = trim($m[1]);
        }

        return ['actual' => $actual, 'proxima' => $proxima];
    }
}
