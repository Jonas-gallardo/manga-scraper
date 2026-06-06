<?php
/**
 * test_taxonomy.php
 *
 * Script de prueba para el módulo de taxonomías.
 * Simula datos de scraping reales y verifica que los procesadores
 * funcionen correctamente.
 *
 * Uso: php includes/test_taxonomy.php
 */

require_once __DIR__ . '/TaxonomyProcessor.php';

echo "═══════════════════════════════════════════════════\n";
echo "  PRUEBA DEL MÓDULO DE TAXONOMÍAS PARA GLUGLUX\n";
echo "═══════════════════════════════════════════════════\n\n";

$processor = new TaxonomyProcessor();

$passed = 0;
$failed = 0;

function test(string $name, $expected, $actual): void
{
    global $passed, $failed;
    $expectedJson = json_encode($expected, JSON_UNESCAPED_UNICODE);
    $actualJson   = json_encode($actual, JSON_UNESCAPED_UNICODE);

    if ($expectedJson === $actualJson) {
        echo "  ✅ {$name}\n";
        $passed++;
    } else {
        echo "  ❌ {$name}\n";
        echo "     Esperado: {$expectedJson}\n";
        echo "     Obtenido: {$actualJson}\n";
        $failed++;
    }
}

// ──────────────────────────────────────────────────────────
// 1. PRUEBAS DE TAGPROCESSOR
// ──────────────────────────────────────────────────────────
echo "─── TAG PROCESSOR ───\n";

$tp = $processor->getTagProcessor();

// Tags con guiones → espacios
test(
    'Tags: guiones a espacios (ai-generated → ai generated)',
    ['ai generated'],
    $tp->process('ai-generated')
);

// Tags con mayúsculas → minúsculas (match con existentes)
test(
    'Tags: mayúsculas a minúsculas',
    ['anal', 'bondage'],
    $tp->process('Anal, Bondage')
);

// Tags con modificador entre paréntesis
test(
    'Tags: respetar paréntesis como modificador',
    ['oral (femenino)'],
    $tp->process('Oral (Femenino)')
);

// Tags con números al inicio
test(
    'Tags: números al inicio (3d)',
    ['3d'],
    $tp->process('3D')
);

// Tags con múltiples separadores
test(
    'Tags: múltiples separadores (comas, puntos y coma)',
    ['oral (femenino)', 'anal', '3d'],
    $tp->process('Oral (femenino); Anal, 3D')
);

// Tags existentes deben mantener su forma canónica
test(
    'Tags: cruce con existentes (pelo corto)',
    ['pelo corto'],
    $tp->process('PELO CORTO')
);

// ── NUEVAS PRUEBAS: MAPA DE EQUIVALENCIAS (mapping) ──
test(
    'Tags: MAPPING "big breasts (female)" → tetona',
    ['tetona'],
    $tp->process('big breasts (female)')
);

test(
    'Tags: MAPPING "big breasts" → tetona',
    ['tetona'],
    $tp->process('big breasts')
);

test(
    'Tags: MAPPING "blowjob" → mamada',
    ['mamada'],
    $tp->process('blowjob')
);

test(
    'Tags: MAPPING "nakadashi" → cum',
    ['cum'],
    $tp->process('nakadashi')
);

test(
    'Tags: MAPPING "small breasts" → tetas pequeñas',
    ['tetas pequeñas'],
    $tp->process('small breasts')
);

test(
    'Tags: MAPPING combinado con existentes (inglés + español)',
    ['tetona', 'mamada', 'oral (femenino)', '3d'],
    $tp->process('big breasts (female), blowjob, Oral (Femenino), 3D')
);

test(
    'Tags: MAPPING "yuri" → lesbiana',
    ['lesbiana'],
    $tp->process('yuri')
);

test(
    'Tags: MAPPING "netorare" → ntr',
    ['ntr'],
    $tp->process('netorare')
);

test(
    'Tags: MAPPING "glasses" → lentes',
    ['lentes'],
    $tp->process('glasses')
);

test(
    'Tags: MAPPING "school uniform" → escuela',
    ['escuela'],
    $tp->process('School Uniform')
);

test(
    'Tags: MAPPING "teacher" → profesora',
    ['profesora'],
    $tp->process('teacher')
);

test(
    'Tags: MAPPING "bondage" + existente "anal"',
    ['bondage', 'anal'],
    $tp->process('Bondage, Anal')  // Bondage ya existe en $tags, se mapea igual
);

// Null tags
test(
    'Tags: null retorna array vacío',
    [],
    $tp->process(null)
);

// Tags vacíos
test(
    'Tags: string vacío retorna array vacío',
    [],
    $tp->process('')
);

// ──────────────────────────────────────────────────────────
// 2. PRUEBAS DE UNIVERSEPROCESSOR
// ──────────────────────────────────────────────────────────
echo "\n─── UNIVERSE PROCESSOR ───\n";

$up = $processor->getUniverseProcessor();

// Universo simple
test(
    'Universo: ataque a los titanes normalizado',
    'attack on titan',
    $up->process('Attack On Titan')
);

// Universo con mayúsculas → devuelve forma canónica en lowercase
test(
    'Universo: forma canónica desde mayúsculas',
    'attack on titan',
    $up->process('ATTACK ON TITAN')
);

// Universo con alias separado por |
test(
    'Universo: alias con | (romaji | english)',
    'attack on titan',
    $up->process('Shingeki no Kyojin | Attack On Titan')
);

// Universo con números al inicio
test(
    'Universo: números al inicio (11eyes)',
    '11eyes', // No existe en referencia, devuelve el limpio
    $up->process('11eyes')
);

// Universo con punto al inicio
test(
    'Universo: punto al inicio (.hack)',
    '.hack',
    $up->process('.hack')
);

// Universo sin alias (no existe en WP, se devuelve limpio)
test(
    'Universo: sin alias ni match (kimetsu no yaiba)',
    'kimetsu no yaiba',
    $up->process('Kimetsu no Yaiba') // No existe en WP, se limpia a lowercase
);

// Universo nulo
test(
    'Universo: null retorna null',
    null,
    $up->process(null)
);

// Multiple universos (Kimetsu no Yaiba no existe en WP, se limpia)
test(
    'Universo: múltiples universos en array',
    ['attack on titan', 'kimetsu no yaiba'],
    $up->processMultiple(['Attack On Titan', 'Kimetsu no Yaiba'])
);

// ──────────────────────────────────────────────────────────
// 3. PRUEBAS DE LANGUAGEPROCESSOR
// ──────────────────────────────────────────────────────────
echo "\n─── LANGUAGE PROCESSOR ───\n";

$lp = $processor->getLanguageProcessor();

test('Idioma: "spanish" → español', 'español', $lp->process('spanish'));
test('Idioma: "en" → inglés', 'inglés', $lp->process('en'));
test('Idioma: "ja" → japonés', 'japonés', $lp->process('ja'));
test('Idioma: "es" → español', 'español', $lp->process('es'));
test('Idioma: null → null', null, $lp->process(null));

// ──────────────────────────────────────────────────────────
// 4. PRUEBAS DEL ORQUESTADOR PRINCIPAL
// ──────────────────────────────────────────────────────────
echo "\n─── TAXONOMY PROCESSOR (ORQUESTADOR) ───\n";

// Simular datos reales del scraper
$rawData = [
    'tags'     => 'Oral (Femenino), 3D, PELO CORTO, AI-GENERATED, Anal',
    'universo' => 'Kimetsu no Yaiba | Demon Slayer',
    'idioma'   => 'spanish',
    'autor'    => 'Nombre del Artista',
    'tipo'     => null,  // Usará default
];

$result = $processor->process($rawData);

$expectedResult = [
    'idioma'     => 'español',
    'universos'  => ['demon slayer'],
    'tipos'      => ['comic'],
    'autores'    => ['Nombre del Artista'],
    'personajes' => [],
    'etiquetas'  => ['oral (femenino)', '3d', 'pelo corto', 'ai generated', 'anal'],
];

test('Orquestador: procesamiento completo', $expectedResult, $result);

// Prueba con datos nulos/parciales
$partialData = [
    'tags'     => null,
    'universo' => null,
    'idioma'   => null,
    'autor'    => null,
    'tipo'     => null,
];

$partialResult = $processor->process($partialData);
$expectedPartial = [
    'idioma'     => null,
    'universos'  => [],
    'tipos'      => ['comic'],
    'autores'    => [],
    'personajes' => [],
    'etiquetas'  => [],
];

test('Orquestador: datos nulos/parciales', $expectedPartial, $partialResult);

// ──────────────────────────────────────────────────────────
// 5. PRUEBA DE PERSONAJES
// ──────────────────────────────────────────────────────────
echo "\n─── PERSONAJES ───\n";

$rawDataWithChars = [
    'tags'       => 'Oral (Femenino), Anal',
    'universo'   => 'Kimetsu no Yaiba | Demon Slayer',
    'idioma'     => 'spanish',
    'autor'      => 'Goma Gorilla',
    'tipo'       => 'doujinshi',
    'personajes' => 'Shinobu Kochou',
];

$resultWithChars = $processor->process($rawDataWithChars);

$expectedWithChars = [
    'idioma'     => 'español',
    'universos'  => ['demon slayer'],
    'tipos'      => ['doujinshi'],
    'autores'    => ['Goma Gorilla'],
    'personajes' => ['shinobu kochou'],
    'etiquetas'  => ['oral (femenino)', 'anal'],
];

test('Personajes: personaje único normalizado a minúsculas', $expectedWithChars, $resultWithChars);

// Prueba con múltiples personajes
$multiChars = [
    'tags'       => null,
    'universo'   => null,
    'idioma'     => null,
    'autor'      => null,
    'tipo'       => null,
    'personajes' => 'Shinobu Kochou, Tanjiro Kamado, Nezuko Kamado',
];

$resultMultiChars = $processor->process($multiChars);

test('Personajes: múltiples personajes separados por coma',
    ['shinobu kochou', 'tanjiro kamado', 'nezuko kamado'],
    $resultMultiChars['personajes']);

// Personajes nulos/vacíos
test('Personajes: null retorna array vacío', [], $processor->process(['personajes' => null])['personajes']);

// ──────────────────────────────────────────────────────────
// 6. PRUEBA CON DATOS REALES DEL SCRAPER
// ──────────────────────────────────────────────────────────
echo "\n─── DATOS REALES DEL SCRAPER EXISTENTE ───\n";

// Simular lo que extrae scraper.php actualmente
$scraperData = [
    'tags'       => 'Oral (Femenino), 3D, Anal, Bondage, Big Breasts, Nakadashi',
    'universo'   => 'Kimetsu no Yaiba | Demon Slayer',
    'idioma'     => 'spanish',
    'autor'      => 'Circle Name',
    'artista'    => 'Artist Name',
    'tipo'       => 'doujinshi',
    'personajes' => 'Shinobu Kochou',
];

$mappedResult = $processor->processFromScraper($scraperData);

echo "  Datos de scraper.php:\n";
echo "    Tags:       {$scraperData['tags']}\n";
echo "    Universo:   {$scraperData['universo']}\n";
echo "    Idioma:     {$scraperData['idioma']}\n";
echo "    Autor:      {$scraperData['autor']}\n";
echo "    Tipo:       {$scraperData['tipo']}\n";
echo "    Personajes: {$scraperData['personajes']}\n\n";

echo "  Resultado procesado:\n";
echo json_encode($mappedResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

// ──────────────────────────────────────────────────────────
// RESUMEN
// ──────────────────────────────────────────────────────────
echo "═══════════════════════════════════════════════════\n";
echo "  RESUMEN: {$passed} pasaron, {$failed} fallaron\n";
echo "═══════════════════════════════════════════════════\n";

exit($failed > 0 ? 1 : 0);
