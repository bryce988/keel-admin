<?php

/**
 * 校验消息（项目覆写层）
 *
 * webman/validation 自带 13 种语言，`vendor/webman/validation/resources/lang/zh_CN/validation.php`
 * 已经是完整的中文包，这里**只覆盖用得到的那几十条**，其余照用包里的。
 * `Illuminate\Translation\FileLoader::loadPaths()` 是按路径顺序
 * `array_replace_recursive` 合并的，项目路径排在最后，所以同名 key 以本文件为准
 * （`min` / `max` 这种嵌套项也是逐 key 合并，只写 numeric/string 不会把 file/array 覆盖没）。
 *
 * 为什么要覆写：包里的措辞是 `':attribute 不能为空。'`——中文与 `:attribute` 之间有个空格、
 * 句尾有个句号。这两条在表单字段下方的红色小字里都很扎眼，而且与项目里
 * 手写抛出的那些消息（AuthService、ProfileService）风格不一致。
 * 统一成「无空格、无句号」。
 *
 * 语言由 config/translation.php 的 locale 决定（zh_CN）。
 */
return [
    // ---------------------------------------------------------------- 必填与类型
    'required' => ':attribute不能为空',
    'filled'   => ':attribute不能为空',
    'present'  => '缺少:attribute',
    'string'   => ':attribute必须是文本',
    'integer'  => ':attribute必须是整数',
    'numeric'  => ':attribute必须是数字',
    'boolean'  => ':attribute必须是布尔值',
    'array'    => ':attribute必须是数组',
    'json'     => ':attribute必须是合法的 JSON',

    // ---------------------------------------------------------------- 格式
    'email'       => ':attribute格式不正确',
    'url'         => ':attribute必须是合法的链接',
    'ip'          => ':attribute必须是合法的 IP',
    'date'        => ':attribute不是合法的日期',
    'date_format' => ':attribute格式必须为 :format',
    'after'       => ':attribute必须晚于 :date',
    'before'      => ':attribute必须早于 :date',
    'regex'       => ':attribute格式不正确',
    'alpha_dash'  => ':attribute只能包含字母、数字、短横线和下划线',
    'alpha_num'   => ':attribute只能包含字母和数字',

    // ---------------------------------------------------------------- 取值与长度
    'in'       => ':attribute取值不在允许范围内',
    'not_in'   => ':attribute取值不在允许范围内',
    'distinct' => ':attribute有重复项',
    'digits'   => ':attribute必须是 :digits 位数字',
    'min'      => [
        'numeric' => ':attribute不能小于 :min',
        'string'  => ':attribute长度不能少于 :min 个字符',
        'array'   => ':attribute至少要选 :min 项',
    ],
    'max' => [
        'numeric' => ':attribute不能大于 :max',
        'string'  => ':attribute长度不能超过 :max 个字符',
        'array'   => ':attribute最多只能选 :max 项',
    ],
    'size' => [
        'numeric' => ':attribute必须等于 :size',
        'string'  => ':attribute长度必须为 :size',
        'array'   => ':attribute必须选 :size 项',
    ],
    'between' => [
        'numeric' => ':attribute必须在 :min 到 :max 之间',
        'string'  => ':attribute长度必须在 :min 到 :max 个字符之间',
        'array'   => ':attribute必须选 :min 到 :max 项',
    ],

    // ---------------------------------------------------------------- 字段间关系
    'same'      => ':attribute与:other不一致',
    'different' => ':attribute与:other不能相同',
    'confirmed' => ':attribute两次输入不一致',

    // ---------------------------------------------------------------- 库里查（需要 DatabasePresenceVerifier）
    'unique' => ':attribute已存在',
    'exists' => ':attribute不存在',

    // ---------------------------------------------------------------- 项目自定义规则
    // 注册在 app\common\support\Validator::registerRules()，改规则记得一起改这里
    'code'  => ':attribute只能包含字母、数字与 _ : . -',
    'phone' => '请输入正确的手机号',
];
