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

NORMAL = (0x7A, 0x7A, 0x7A)   # = pages.json 的 tabBar.color
ACTIVE = (0x40, 0x9E, 0xFF)   # = pages.json 的 tabBar.selectedColor


def tri(px, py, a, b, c):
    def side(p, q, r):
        return (q[0] - p[0]) * (r[1] - p[1]) - (q[1] - p[1]) * (r[0] - p[0])
    d1, d2, d3 = side(a, b, (px, py)), side(b, c, (px, py)), side(c, a, (px, py))
    return not (((d1 < 0) or (d2 < 0) or (d3 < 0)) and ((d1 > 0) or (d2 > 0) or (d3 > 0)))


def rect(px, py, x0, y0, x1, y1):
    return x0 <= px <= x1 and y0 <= py <= y1


def circle(px, py, cx, cy, r):
    return (px - cx) ** 2 + (py - cy) ** 2 <= r * r


def rrect(px, py, x0, y0, x1, y1, r):
    """圆角矩形。四角用同一半径；落在角落方格外的点按普通矩形判"""
    if not rect(px, py, x0, y0, x1, y1):
        return False
    for cx, cy in ((x0 + r, y0 + r), (x1 - r, y0 + r), (x1 - r, y1 - r), (x0 + r, y1 - r)):
        out_x = px < cx if cx == x0 + r else px > cx
        out_y = py < cy if cy == y0 + r else py > cy
        if out_x and out_y and not circle(px, py, cx, cy, r):
            return False
    return True


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


def workbench(x, y):
    """2×2 宫格：工作台的通用画法。
    不用「房子」——房子是「首页」的语义，而这一格现在的职责是入口聚合"""
    return any(
        rrect(x, y, x0, y0, x0 + 24, y0 + 24, 5)
        for x0, y0 in ((12, 12), (45, 12), (12, 45), (45, 45))
    )


def contacts(x, y):
    """通讯录：卡片外框 + 里面一个人 + 左侧三道书脊。
    只画一个人不画两个：两个人是「群组」的语义，通讯录是「按名册找人」"""
    card = rrect(x, y, 20, 11, 70, 69, 7) and not rrect(x, y, 25, 16, 65, 64, 3)
    spine = any(rrect(x, y, 8, y0, 17, y0 + 6, 3) for y0 in (21, 38, 55))
    head = circle(x, y, 45, 32, 8)
    # 肩：上圆下平，才像人不像方块
    shoulder = (rect(x, y, 32, 49, 58, 58)
                or rrect(x, y, 32, 44, 58, 58, 11) and y <= 55)

    return card or spine or head or shoulder


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
    # home 与 message 目前不在 tabBar 里（首页改成工作台、系统公告降级为工作台入口），
    # 但图形留着：删掉的话将来想换回来又得重画一遍
    for name, shape in (('home', home), ('message', message), ('mine', mine),
                        ('workbench', workbench), ('contacts', contacts)):
        for suffix, color in (('', NORMAL), ('-active', ACTIVE)):
            p = f'static/tabbar/{name}{suffix}.png'
            print(p, render(shape, color, p), 'bytes')
