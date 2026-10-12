<?php

// Standalone diagnostic: php tests/fixtures/maintenance-entrypoint-probe.php /path/to/wordpress
// Each native WordPress control runs in a fresh process with an owned ABSPATH.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Symfony\Component\Process\Process;
use Themosis\Core\Maintenance\WordPressMaintenanceMode;

if (($argv[1] ?? '') === '--child') {
    [$script, $flag, $wordpress, $owned, $scenario] = $argv;
    define('ABSPATH', $owned . '/');
    define('WP_CONTENT_DIR', $owned);
    define('MINUTE_IN_SECONDS', 60);
    require $wordpress . '/wp-includes/plugin.php';
    require $wordpress . '/wp-includes/load.php';

    if ($scenario === 'installing') {
        wp_installing(true);
    }
    if ($scenario === 'filter-disabled') {
        add_filter('enable_maintenance_mode', static fn () => false);
    }
    echo wp_is_maintenance_mode() ? 'active;' : 'inactive;';
    wp_maintenance();
    echo 'downstream';
    exit;
}

$wordpress = rtrim($argv[1] ?? '', '/');
if (! is_file($wordpress . '/wp-includes/load.php')) {
    throw new RuntimeException('Supply the installed WordPress directory.');
}
$owned = sys_get_temp_dir() . '/themosis-maintenance-probe-' . bin2hex(random_bytes(8));
mkdir($owned, 0700);
$mode = new WordPressMaintenanceMode($owned . '/.maintenance', $owned . '/down');
$controls = [
    'absent' => 'inactive;downstream',
    'fresh' => 'active;native-maintenance',
    'expired' => 'inactive;downstream',
    'installing' => 'inactive;downstream',
    'filter-disabled' => 'inactive;downstream',
    'framework-activated' => 'active;native-maintenance',
];

try {
    file_put_contents($owned . '/maintenance.php', '<?php echo "native-maintenance";');
    foreach ($controls as $scenario => $expected) {
        $mode->deactivate();
        if ($scenario === 'framework-activated') {
            $mode->activate(['retry' => 60]);
            if (! $mode->active() || $mode->data() !== ['retry' => 60]) {
                throw new RuntimeException('Framework activation/payload control failed.');
            }
        } elseif ($scenario !== 'absent') {
            $timestamp = time() - ($scenario === 'expired' ? 601 : 0);
            file_put_contents($owned . '/.maintenance', '<?php $upgrading = ' . $timestamp . ';');
        }
        $process = new Process([PHP_BINARY, __FILE__, '--child', $wordpress, $owned, $scenario]);
        $process->mustRun();
        if ($process->getOutput() !== $expected || $process->getErrorOutput() !== '') {
            throw new RuntimeException($scenario . ': unexpected subprocess output: ' . $process->getOutput() . $process->getErrorOutput());
        }
        echo $scenario . ': ' . $expected . PHP_EOL;
    }
    $mode->deactivate();
    if ($mode->active() || $mode->data() !== []) {
        throw new RuntimeException('Framework deactivation control failed.');
    }
    echo 'framework-deactivated: inactive;empty-payload' . PHP_EOL;
} finally {
    $mode->deactivate();
    unlink($owned . '/maintenance.php');
    rmdir($owned);
}
