#!/usr/bin/env python3
"""
生成 tabBar 图标（static/tabbar/*.png）

    python3 scripts/make-tabbar-icons.py

为什么是脚本而不是几个丢进来的 png：二进制文件进了仓库就没人知道它从哪来、
要改颜色或尺寸时只能重新去找设计稿。这里图标是几何图形，用代码描述反而最清楚——
改 NORMAL / ACTIVE 就换色，改 SIZE 就换尺寸，与 pages.json 里的
tabBar.color / selectedColor 保持一致由这个文件负责。

纯标准库实现（本机没有 PIL / rsvg / magick）：自己按 PNG 规范拼字节，
SS 倍超采样再按覆盖率算 alpha，等效于抗锯齿。
"""
import zlib
import struct

SIZE, SS = 81, 4          # uni-app 建议 81×81；SS 是超采样倍数

NORMAL = (0x8A, 0x8F, 0x99)   # = pages.json 的 tabBar.color
ACTIVE = (0x2B, 0x6C, 0xF6)   # = pages.json 的 tabBar.selectedColor


def tri(px, py, a, b, c):
    def side(p, q, r):
        return (q[0] - p[0]) * (r[1] - p[1]) - (q[1] - p[1]) * (r[0] - p[0])
    d1, d2, d3 = side(a, b, (px, py)), side(b, c, (px, py)), side(c, a, (px, py))
    return not (((d1 < 0) or (d2 < 0) or (d3 < 0)) and ((d1 > 0) or (d2 > 0) or (d3 > 0)))


def rect(px, py, x0, y0, x1, y1):
    return x0 <= px <= x1 and y0 <= py <= y1


def circle(px, py, cx, cy, r):
    return (px - cx) ** 2 + (py - cy) ** 2 <= r * r


def home(x, y):
    """房子：屋顶三角 + 身体方块 − 门（挖空，所以判断放在最前面）"""
    if rect(x, y, 33.5, 48, 47.5, 68):
        return False
    return tri(x, y, (40.5, 12), (8, 42), (73, 42)) or rect(x, y, 17, 40, 64, 68)


def message(x, y):
    """信封：外框 + 上沿两道斜线（信封盖）。
    不画铃铛：铃铛在小尺寸下那颗铃舌会糊成一坨，信封的轮廓更好认"""
    left, right, top, bottom = 12.0, 69.0, 22.0, 59.0
    border = 5.0

    inside = rect(x, y, left, top, right, bottom)
    hollow = rect(x, y, left + border, top + border, right - border, bottom - border)
    frame = inside and not hollow

    # 信封盖：从左上、右上各斜向中点，用「点到线段距离」画出带圆头的粗线
    def near(ax, ay, bx, by, w):
        dx, dy = bx - ax, by - ay
        t = ((x - ax) * dx + (y - ay) * dy) / (dx * dx + dy * dy)
        t = 0.0 if t < 0 else (1.0 if t > 1 else t)
        return (x - (ax + t * dx)) ** 2 + (y - (ay + t * dy)) ** 2 <= (w / 2) ** 2

    mid_x, mid_y = (left + right) / 2, top + 20.0
    flap = inside and (near(left, top, mid_x, mid_y, border) or near(right, top, mid_x, mid_y, border))

    return frame or flap


def mine(x, y):
    """人：头 + 肩（半椭圆，底部截平）"""
    head = circle(x, y, 40.5, 26, 13.5)
    shoulder = ((x - 40.5) ** 2) / (27.0 ** 2) + ((y - 70.0) ** 2) / (26.0 ** 2) <= 1 and y <= 69
    return head or shoulder


def render(shape, rgb, path):
    r, g, b = rgb
    rows = []
    for py in range(SIZE):
        row = bytearray([0])                      # 每行开头是 filter 字节
        for px in range(SIZE):
            hit = 0
            for sy in range(SS):
                for sx in range(SS):
                    if shape(px + (sx + 0.5) / SS, py + (sy + 0.5) / SS):
                        hit += 1
            row += bytes((r, g, b, int(round(255 * hit / (SS * SS)))))
        rows.append(bytes(row))

    def chunk(tag, data):
        c = tag + data
        return struct.pack('>I', len(data)) + c + struct.pack('>I', zlib.crc32(c))

    png = (b'\x89PNG\r\n\x1a\n'
           + chunk(b'IHDR', struct.pack('>IIBBBBB', SIZE, SIZE, 8, 6, 0, 0, 0))
           + chunk(b'IDAT', zlib.compress(b''.join(rows), 9))
           + chunk(b'IEND', b''))
    with open(path, 'wb') as f:
        f.write(png)
    return len(png)


if __name__ == '__main__':
    for name, shape in (('home', home), ('message', message), ('mine', mine)):
        for suffix, color in (('', NORMAL), ('-active', ACTIVE)):
            p = f'static/tabbar/{name}{suffix}.png'
            print(p, render(shape, color, p), 'bytes')
