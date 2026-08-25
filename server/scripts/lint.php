<?php
/**
 * keel admin
 * PHP 语法检查
 *
 * 对 vendor/ runtime/ 之外的所有 .php 跑一遍 `php -l`。
 *
 * 为什么是独立脚本而不是 composer.json 里的一行内联 `php -r`：
 * 内联写法里的 `$f`、`$p` 会先被 shell 当变量展开成空串，
 * 报出来的是「unexpected token ")"」这种与真实代码无关的错，排查成本远大于多一个文件。
 *
 * 用法：composer lint（或 php scripts/lint.php）
 *
 * ⚠️ 这只是语法检查，不是静态分析。它能挡住「改完忘了闭合括号」这类低级错误，
 * 挡不住类型错误与未定义方法——那需要 phpstan，属于后续再引入的范围。
 *
 * @author 火火
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$skip = ['/vendor/', '/runtime/', '/.git/'];

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$checked = 0;
$failed  = [];

foreach ($files as $file) {
    /** @var SplFileInfo $file */
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    foreach ($skip as $fragment) {
        if (str_contains($path, $fragment)) {
            continue 2;
        }
    }

    $output = [];
    $code   = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);

    $checked++;
    if ($code !== 0) {
        $failed[] = substr($path, strlen($root) + 1) . PHP_EOL . '  ' . implode(PHP_EOL . '  ', $output);
    }
}

if ($failed !== []) {
    echo '✗ ', count($failed), ' 个文件有语法错误：', PHP_EOL, PHP_EOL;
    echo implode(PHP_EOL . PHP_EOL, $failed), PHP_EOL;
    exit(1);
}

echo "✓ {$checked} 个 PHP 文件语法检查通过", PHP_EOL;
exit(0);
