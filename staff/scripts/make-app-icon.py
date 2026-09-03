#!/usr/bin/env python3
"""
生成 App 图标（static/icons/icon-*.png）

    python3 scripts/make-app-icon.py

图形与 web/public/favicon.svg、web/src/components/BrandLogo.vue 是**同一份**：
船体肋骨的横剖 + 一根贯穿的龙骨主梁——龙骨是全船肋骨唯一的附着点，
这个脚手架对业务代码就是这个关系。改标记要连它们一起改。

为什么是脚本：本机没有 PIL / rsvg / magick，而且图标要出 5 个尺寸，
手工导出必然有一天漏掉一个。纯标准库实现——自己按 PNG 规范拼字节，
用距离场（SDF）算覆盖率做抗锯齿，比超采样快得多也更平滑。
"""
import math
import struct
import zlib

# ---------------------------------------------------------------- 设计稿（32 格坐标系）
GRID = 32.0
RADIUS = 7.5           # 圆角，与 favicon.svg 的 rx 一致
STROKE = 3.0           # 笔宽
BG = (0x39, 0x86, 0xFF)      # = favicon.svg 的 fill，取自 --el-color-primary
FG = (0xFF, 0xFF, 0xFF)

# 两条路径，与 favicon.svg 的 d 属性逐点对应
HULL = [                                    # M4 5.2 C 4.6 17.1, 9.7 25, 16 25.8
    ((4.0, 5.2), (4.6, 17.1), (9.7, 25.0), (16.0, 25.8)),
    ((16.0, 25.8), (22.3, 25.0), (27.4, 17.1), (28.0, 5.2)),   # C 22.3 25, 27.4 17.1, 28 5.2
]
KEEL = ((16.0, 3.5), (16.0, 28.5))          # M16 3.5 V 28.5

ANDROID_SIZES = [48, 72, 96, 144, 192]      # ldpi/mdpi · hdpi · xhdpi · xxhdpi · xxxhdpi
EXTRA_SIZES = [512, 1024]                   # 512 应用市场；1024 是 HBuilderX
                                            # 「App 图标配置 → 自动生成所有图标」的源图


def bezier_points(p0, c1, c2, p3, n):
    """三次贝塞尔采样成折线。n 够大时，折线与曲线的偏差远小于一个像素"""
    pts = []
    for i in range(n + 1):
        t = i / n
        u = 1 - t
        x = u*u*u*p0[0] + 3*u*u*t*c1[0] + 3*u*t*t*c2[0] + t*t*t*p3[0]
        y = u*u*u*p0[1] + 3*u*u*t*c1[1] + 3*u*t*t*c2[1] + t*t*t*p3[1]
        pts.append((x, y))
    return pts


def segments(size):
    """把所有笔画拆成线段。采样密度跟着尺寸走：小图标不需要那么多点"""
    n = max(40, int(size / 3))
    pts = []
    for curve in HULL:
        pts += bezier_points(*curve, n=n)
    segs = [(pts[i], pts[i+1]) for i in range(len(pts) - 1)]
    segs.append(KEEL)
    return segs


def dist_to_segment(px, py, a, b):
    ax, ay = a
    bx, by = b
    dx, dy = bx - ax, by - ay
    if dx == 0 and dy == 0:
        return math.hypot(px - ax, py - ay)
    t = ((px - ax) * dx + (py - ay) * dy) / (dx*dx + dy*dy)
    t = 0.0 if t < 0 else (1.0 if t > 1 else t)     # 夹到线段内 = 圆头端点（round cap）
    return math.hypot(px - (ax + t*dx), py - (ay + t*dy))


def rounded_rect_sdf(px, py, half, r):
    """圆角矩形的有符号距离，负值在内部。中心在原点，半边长 half"""
    qx = abs(px) - (half - r)
    qy = abs(py) - (half - r)
    outside = math.hypot(max(qx, 0.0), max(qy, 0.0))
    inside = min(max(qx, qy), 0.0)
    return outside + inside - r


def render(size, path):
    scale = size / GRID
    half_w = STROKE / 2 * scale                      # 笔宽的一半（像素）
    segs = [((a[0]*scale, a[1]*scale), (b[0]*scale, b[1]*scale)) for a, b in segments(size)]
    half = size / 2
    radius = RADIUS * scale

    # 按行做包围盒剪枝：一行只需要与它有交集的线段，512 尺寸下能省掉九成计算
    rows_segs = []
    for y in range(size):
        band = []
        for a, b in segs:
            lo, hi = min(a[1], b[1]) - half_w - 1, max(a[1], b[1]) + half_w + 1
            if lo <= y + 0.5 <= hi:
                band.append((a, b))
        rows_segs.append(band)

    raw = bytearray()
    for y in range(size):
        raw.append(0)                                # 每行开头的 filter 字节
        py = y + 0.5
        band = rows_segs[y]
        for x in range(size):
            px = x + 0.5

            # 背景：圆角矩形。SDF 直接换算成覆盖率，等效抗锯齿
            d_bg = rounded_rect_sdf(px - half, py - half, half, radius)
            a_bg = min(max(0.5 - d_bg, 0.0), 1.0)
            if a_bg <= 0:
                raw += b'\x00\x00\x00\x00'
                continue

            # 笔画：到所有线段的最近距离
            d = min((dist_to_segment(px, py, a, b) for a, b in band), default=1e9)
            a_fg = min(max(half_w - d + 0.5, 0.0), 1.0)

            r = round(BG[0] + (FG[0] - BG[0]) * a_fg)
            g = round(BG[1] + (FG[1] - BG[1]) * a_fg)
            b_ = round(BG[2] + (FG[2] - BG[2]) * a_fg)
            raw += bytes((r, g, b_, round(a_bg * 255)))

    def chunk(tag, data):
        c = tag + data
        return struct.pack('>I', len(data)) + c + struct.pack('>I', zlib.crc32(c))

    png = (b'\x89PNG\r\n\x1a\n'
           + chunk(b'IHDR', struct.pack('>IIBBBBB', size, size, 8, 6, 0, 0, 0))
           + chunk(b'IDAT', zlib.compress(bytes(raw), 9))
           + chunk(b'IEND', b''))
    with open(path, 'wb') as f:
        f.write(png)
    return len(png)


if __name__ == '__main__':
    for s in ANDROID_SIZES + EXTRA_SIZES:
        p = f'static/icons/icon-{s}.png'
        print(f'{p}  {render(s, p)} bytes')
