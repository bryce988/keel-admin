<?php

declare(strict_types=1);

namespace app\common\support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * 表格导入导出
 *
 * 用 openspout 而不是 PhpSpreadsheet：它是**流式**的，两万行读写内存峰值只有 4MB，
 * 而且不随文件增长。webman 是常驻进程，一次导出把内存吃到几百 MB 就再也降不回来，
 * 整个 worker 会带着这份内存服务后续所有请求（PROJECT.md §14）。
 *
 * 同理，导出必须由调用方用 chunk 分批喂数据，**不能先 get() 全表再传进来**——
 * 那样瓶颈就从写文件挪到了查询，内存照样爆。
 */
final class Spreadsheet
{
    /** 导出文件的存放目录，相对 runtime */
    private const EXPORT_DIR = 'exports';

    /**
     * 流式写出 xlsx
     *
     * @param  string[]  $headers  表头
     * @param  callable  $feed     fn(callable $emit): void —— 内部按 chunk 取数，
     *                             每行调一次 $emit(array $row)
     * @return string  生成的文件绝对路径
     */
    public static function writeXlsx(string $filename, array $headers, callable $feed): string
    {
        self::gc();

        $dir = runtime_path() . '/' . self::EXPORT_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = sprintf('%s/%s_%s_%s.xlsx', $dir, $filename, date('Ymd_His'), bin2hex(random_bytes(3)));

        $writer = new XlsxWriter();
        $writer->openToFile($path);

        // openspout v5 的三处 API 与 v4 不同，照 v4 写会直接 500：
        // - Style 是不可变对象，用 withXxx() 且**必须传参**（没有无参的 setFontBold()）
        // - Row::fromValues() 的第二个参数是**行高**，不是样式
        // - 带样式的行要用 fromValuesWithStyle()
        $writer->addRow(Row::fromValuesWithStyle($headers, (new Style())->withFontBold(true)));

        try {
            $feed(function (array $values) use ($writer): void {
                $writer->addRow(Row::fromValues($values));
            });
        } finally {
            $writer->close();
        }

        return $path;
    }

    /**
     * 逐行读取 xlsx / csv
     *
     * 第一行当表头，之后每行组装成 [表头 => 值] 交给 $handle($row, $rowNumber)。
     * 用回调而不是返回数组：返回数组等于把整个文件读进内存，导入十万行时就炸了。
     */
    public static function eachRow(string $path, callable $handle): void
    {
        $reader = self::readerFor($path);
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = [];
                $number  = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $values = array_map(
                        static fn ($v) => is_string($v) ? trim($v) : $v,
                        $row->toArray()
                    );
                    $number++;

                    if ($number === 1) {
                        $headers = array_map('strval', $values);

                        continue;
                    }

                    if (array_filter($values, static fn ($v) => $v !== '' && $v !== null) === []) {
                        continue;   // 跳过空行，Excel 里常有一堆看不见的空行
                    }

                    $assoc = [];
                    foreach ($headers as $i => $name) {
                        $assoc[$name] = $values[$i] ?? '';
                    }

                    $handle($assoc, $number);
                }

                break;   // 只处理第一个 sheet
            }
        } finally {
            $reader->close();
        }
    }

    private static function readerFor(string $path): ReaderInterface
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv'
            ? new CsvReader()
            : new XlsxReader();
    }

    /**
     * 清掉一小时前的导出文件
     *
     * 导出文件是一次性的，下载完就没用了。不清理的话磁盘会被慢慢吃满，
     * 而这种问题通常是在磁盘 100% 的凌晨才被发现。
     */
    private static function gc(int $ttl = 3600): void
    {
        $dir = runtime_path() . '/' . self::EXPORT_DIR;
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - $ttl) {
                @unlink($file);
            }
        }
    }
}
