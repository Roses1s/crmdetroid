<?php
declare(strict_types=1);

function now_ms(): int { return (int) round(microtime(true) * 1000); }
function err(string $m = '', bool $needLogin = false): never { throw new RuntimeException($m); }

require __DIR__ . '/../site/config.php';
require __DIR__ . '/../site/db.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $ok): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "  ✅ $name\n"; }
    else { $failed++; echo "  ❌ $name\n"; }
}

echo "=== PHP: загрузки и имена ===\n";
check('png разрешён', crm_allowed_upload('a.PNG') === 'png');
check('php в имени запрещён', crm_allowed_upload('x.php.jpg') === null);
check('svg запрещён', crm_allowed_upload('x.svg') === null);
check('имя Система занято', crm_reserved_user_name('Система'));
check('имя system занято', crm_reserved_user_name(' System '));
check('обычное имя ок', !crm_reserved_user_name('Иван Петров'));
check('системный комментарий', crm_is_sys_comment(['author' => 'Система']));
check('не системный', !crm_is_sys_comment(['author' => 'Иван']));

$dir = sys_get_temp_dir();
$png = $dir . '/crm-magic-' . bin2hex(random_bytes(4)) . '.png';
file_put_contents($png, "\x89PNG\r\n\x1a\n" . 'xxxx');
check('magic png', crm_upload_magic_ok($png, 'png'));
check('png как jpg — нет', !crm_upload_magic_ok($png, 'jpg'));
@unlink($png);

$jpg = $dir . '/crm-magic-' . bin2hex(random_bytes(4)) . '.jpg';
file_put_contents($jpg, "\xFF\xD8\xFF\xE0xxxx");
check('magic jpg', crm_upload_magic_ok($jpg, 'jpeg'));
@unlink($jpg);

$pdf = $dir . '/crm-magic-' . bin2hex(random_bytes(4)) . '.pdf';
file_put_contents($pdf, '%PDF-1.4xxxx');
check('magic pdf', crm_upload_magic_ok($pdf, 'pdf'));
check('pdf как zip — нет', !crm_upload_magic_ok($pdf, 'zip'));
@unlink($pdf);

$txt = $dir . '/crm-magic-' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($txt, "hello\n");
check('magic txt', crm_upload_magic_ok($txt, 'txt'));
file_put_contents($txt, "a\0b");
check('txt с NUL — нет', !crm_upload_magic_ok($txt, 'txt'));
@unlink($txt);

echo "\n=== PHP: этапы ===\n";
check('перестановка колонок', crm_stage_renames(['A', 'B'], ['B', 'A']) === []);
check('переименование одной', crm_stage_renames(['Новый', 'B'], ['Новые', 'B']) === [['Новый', 'Новые']]);
check('добавление этапа', crm_stage_renames(['A', 'B'], ['A', 'B', 'C']) === []);
check('like escape', crm_like_pat('a%b_c') === '%a\\%b\\_c%');

echo "\n=== PHP: синтаксис ===\n";
foreach (['api.php', 'db.php', 'config.php'] as $f) {
    $path = __DIR__ . '/../site/' . $f;
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    check("php -l $f", $code === 0);
}

echo "\nИТОГО PHP: $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
