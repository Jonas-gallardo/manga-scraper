<?php
/**
 * convert_to_webp.php
 *
 * Utilidad para convertir TODOS los cómics ya descargados a WebP al 85%.
 * También actualiza el tamaño en disco en la base de datos.
 *
 * MODOS DE USO:
 *   1. Web:   https://tu-servidor/convert_to_webp.php
 *   2. CLI:   php convert_to_webp.php [--all|--id=ID] [--quality=85]
 *
 * PARÁMETROS (web):
 *   action=convert_all   → Convierte todos los cómics
 *   action=convert&id=X  → Convierte un cómic específico
 *   quality=85           → Calidad WebP (opcional, default 85)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/conexion.php';

header('Content-Type: text/plain; charset=utf-8');

// ── CLI mode ──
if (PHP_SAPI === 'cli') {
    // Verificar permisos de escritura en descargas/
    $test_dir = __DIR__ . '/descargas';
    if (is_dir($test_dir) && !is_writable($test_dir)) {
        echo "⚠️  ADVERTENCIA: El directorio 'descargas/' no tiene permisos de escritura.\n";
        echo "   Los cómics fueron descargados por el servidor web (usuario: daemon).\n";
        echo "   Para ejecutar este script desde CLI, necesitas permisos de escritura.\n";
        echo "   Solución: sudo chmod -R o+w descargas/  (o ejecuta via web)\n\n";
    }

    $options = getopt('', ['all', 'id:', 'quality:']);
    $quality = isset($options['quality']) ? (int) $options['quality'] : 85;
    $quality = max(1, min(100, $quality));

    if (isset($options['all'])) {
        convertir_todos($pdo, $quality);
    } elseif (isset($options['id'])) {
        convertir_por_id($pdo, (int) $options['id'], $quality);
    } else {
        echo "Uso: php convert_to_webp.php [--all|--id=ID] [--quality=85]\n";
        echo "  --all          Convierte TODOS los cómics descargados\n";
        echo "  --id=ID        Convierte un cómic específico por ID\n";
        echo "  --quality=85   Calidad WebP (1-100, default 85)\n";
        exit(1);
    }
    exit;
}

// ── Web mode ──
header('Content-Type: application/json; charset=utf-8');

$action  = trim($_POST['action'] ?? $_GET['action'] ?? '');
$id      = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$quality = max(1, min(100, (int) ($_POST['quality'] ?? $_GET['quality'] ?? 85)));

if ($action === 'convert_all') {
    $result = convertir_todos($pdo, $quality, true);
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
} elseif ($action === 'convert' && $id > 0) {
    $result = convertir_por_id($pdo, $id, $quality, true);
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Use action=convert_all o action=convert&id=ID',
    ], JSON_UNESCAPED_UNICODE);
}

// ──────────────────────────────────────────────────────────────
// FUNCIONES
// ──────────────────────────────────────────────────────────────

/**
 * Convierte TODOS los cómics de la base de datos a WebP.
 */
function convertir_todos(PDO $pdo, int $quality, bool $is_web = false): array {
    $stmt = $pdo->query(
        'SELECT id_fuente, titulo, ruta_carpeta FROM comics_descargados WHERE ruta_carpeta IS NOT NULL'
    );
    $comics = $stmt->fetchAll();

    if (empty($comics)) {
        $msg = "No hay cómics descargados para convertir.";
        if ($is_web) return ['message' => $msg, 'total' => 0, 'convertidos' => 0, 'errores' => 0];
        echo $msg . "\n";
        return ['total' => 0, 'convertidos' => 0, 'errores' => 0];
    }

    $total      = count($comics);
    $convertidos = 0;
    $errores     = 0;
    $total_bytes_original = 0;
    $total_bytes_webp     = 0;

    foreach ($comics as $comic) {
        echo "🔄 [{$comic['id_fuente']}] {$comic['titulo']}... ";

        if (!$comic['ruta_carpeta'] || !is_dir($comic['ruta_carpeta'])) {
            echo "⚠️  Carpeta no encontrada\n";
            $errores++;
            continue;
        }

        $stats = convertir_comic_a_webp($comic['ruta_carpeta'], $quality);

        if ($stats['converted'] > 0) {
            $ahorro = $stats['bytes_ahorrados'];
            $ahorro_fmt = format_bytes($ahorro);
            echo "✅ {$stats['converted']} imágenes convertidas (-{$ahorro_fmt})\n";
            $convertidos++;
            $total_bytes_original += $stats['bytes_original'];
            $total_bytes_webp     += $stats['bytes_webp'];

            // Actualizar tamano_bytes en BD
            $nuevo_tamano = calcular_tamano_dir($comic['ruta_carpeta']);
            $stmt_upd = $pdo->prepare(
                'UPDATE comics_descargados SET tamano_bytes = ? WHERE id_fuente = ?'
            );
            $stmt_upd->execute([$nuevo_tamano, $comic['id_fuente']]);
        } elseif ($stats['skipped'] > 0) {
            echo "⏭️  Ya en WebP ({$stats['skipped']} imágenes)\n";
            $convertidos++; // counted as success
        } else {
            echo "⚠️  Sin imágenes para convertir\n";
        }

        if ($stats['failed'] > 0) {
            echo "     ⚠️  {$stats['failed']} fallos de conversión\n";
        }
    }

    $total_ahorro = $total_bytes_original - $total_bytes_webp;
    $resumen = "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
             . "🎉 CONVERSIÓN COMPLETADA\n"
             . "  • Total cómics procesados: {$total}\n"
             . "  • Convertidos/OK: {$convertidos}\n"
             . "  • Errores: {$errores}\n"
             . "  • Ahorro total: " . format_bytes($total_ahorro) . "\n"
             . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

    if ($is_web) {
        return [
            'message' => $resumen,
            'total' => $total,
            'convertidos' => $convertidos,
            'errores' => $errores,
            'bytes_ahorrados' => $total_ahorro,
        ];
    }

    echo $resumen;
    return ['total' => $total, 'convertidos' => $convertidos, 'errores' => $errores];
}

/**
 * Convierte un cómic específico por ID.
 */
function convertir_por_id(PDO $pdo, int $id, int $quality, bool $is_web = false): array {
    $stmt = $pdo->prepare('SELECT id_fuente, titulo, ruta_carpeta FROM comics_descargados WHERE id_fuente = ?');
    $stmt->execute([$id]);
    $comic = $stmt->fetch();

    if (!$comic) {
        $msg = "Cómic ID {$id} no encontrado en la base de datos.";
        if ($is_web) return ['message' => $msg];
        echo $msg . "\n";
        return ['message' => $msg];
    }

    if (!$comic['ruta_carpeta'] || !is_dir($comic['ruta_carpeta'])) {
        $msg = "Carpeta del cómic ID {$id} no encontrada en disco.";
        if ($is_web) return ['message' => $msg];
        echo $msg . "\n";
        return ['message' => $msg];
    }

    $stats = convertir_comic_a_webp($comic['ruta_carpeta'], $quality);

    // Actualizar tamano_bytes en BD
    $nuevo_tamano = calcular_tamano_dir($comic['ruta_carpeta']);
    $stmt_upd = $pdo->prepare('UPDATE comics_descargados SET tamano_bytes = ? WHERE id_fuente = ?');
    $stmt_upd->execute([$nuevo_tamano, $id]);

    if ($is_web) {
        return [
            'message' => "Cómic «{$comic['titulo']}» (ID {$id}): {$stats['converted']} convertidas, {$stats['skipped']} ya webp, {$stats['failed']} fallos. Ahorrado: " . format_bytes($stats['bytes_ahorrados']),
            'id' => $id,
            'titulo' => $comic['titulo'],
            'stats' => $stats,
        ];
    }

    echo "📊 Resultados para «{$comic['titulo']}» (ID {$id}):\n";
    echo "  • Convertidas: {$stats['converted']}\n";
    echo "  • Ya en WebP:  {$stats['skipped']}\n";
    echo "  • Fallos:      {$stats['failed']}\n";
    echo "  • Ahorrado:    " . format_bytes($stats['bytes_ahorrados']) . "\n";

    return [
        'message' => "OK",
        'stats' => $stats,
    ];
}

/**
 * Formatea bytes a formato legible.
 */
function format_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
