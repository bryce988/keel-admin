#!/usr/bin/env python3
"""
从 public/favicon.svg 生成 favicon.ico 与 apple-touch-icon.png。

    python3 web/scripts/make-icons.py

改了标记的形状或主色，只要 favicon.svg 是对的，跑一遍这个脚本就能把两个
位图对齐过来（组件 BrandLogo.vue 里的内联 SVG 仍要手工同步，它不在这条链上）。

## 为什么自己写光栅化

- `qlmanage -t -s 16` 不按 viewBox 缩放：它把图渲染在自己的默认大画布上，
  再缩到目标尺寸，结果是一张几乎全白、左上角剩一个蓝点的图。而且尺寸声明
  是对的（IHDR 就是 16x16），`sips -g pixelWidth` 也查不出问题——只有把像素
  解出来数颜色才看得见。第一版 favicon.ico 就是这么坏的。
- Chrome headless 渲染正确，但会和已在运行的 Chrome 抢单例锁，十次里九次卡死。
- Pillow / cairosvg / ImageMagick / Inkscape 本机都没有，而给一个脚手架的
  图标生成加一条 Python 依赖不值当。

所以用距离场：形状由「点到几何体的距离」定义，描边就是距离 <= 线宽/2 的
区域，圆头线帽和圆角接头都是这个定义的自然结果。像素中心离边界超过一个
像素的直接判定内外，只有边界像素做 4x4 超采样，全部尺寸两秒跑完。
输出与 Chrome 的渲染结果在 16px 上逐像素比对无显著差异。
"""
import math, pathlib, re, struct, zlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
SVG = ROOT / "public" / "favicon.svg"

# ── 从矢量源里取形状，别在这里抄一份坐标 ────────────────────────────────

def parse_svg(text):
    fill = re.search(r'<rect[^>]*fill="#([0-9a-fA-F]{6})"', text)
    width = re.search(r'stroke-width="([\d.]+)"', text)
    ds = re.findall(r'<path[^>]*\sd="([^"]+)"', text)
    if not (fill and width and ds):
        raise SystemExit("favicon.svg 里没找到 fill / stroke-width / path，格式变了？")
    rgb = tuple(int(fill.group(1)[i:i+2], 16) for i in (0, 2, 4))
    return rgb, float(width.group(1)), ds

def parse_path(d, steps=40):
    """只认这个标记用到的 M / C / V。碰到别的命令直接报错——默默画错更难查。"""
    toks = re.findall(r"[A-Za-z]|-?\d*\.?\d+", d)
    pts, cur, i = [], (0.0, 0.0), 0
    while i < len(toks):
        cmd = toks[i]; i += 1
        if cmd == "M":
            cur = (float(toks[i]), float(toks[i+1])); i += 2
            pts.append(cur)
        elif cmd == "C":
            p1 = (float(toks[i]), float(toks[i+1]))
            p2 = (float(toks[i+2]), float(toks[i+3]))
            p3 = (float(toks[i+4]), float(toks[i+5])); i += 6
            p0 = cur
            for s in range(1, steps + 1):
                t = s / steps; u = 1 - t
                pts.append((u*u*u*p0[0] + 3*u*u*t*p1[0] + 3*u*t*t*p2[0] + t*t*t*p3[0],
                            u*u*u*p0[1] + 3*u*u*t*p1[1] + 3*u*t*t*p2[1] + t*t*t*p3[1]))
            cur = p3
        elif cmd == "V":
            cur = (cur[0], float(toks[i])); i += 1
            pts.append(cur)
        else:
            raise SystemExit(f"path 里出现未支持的命令 {cmd!r}，需要先给这个脚本补上")
    return pts

# ── 距离场 ─────────────────────────────────────────────────────────────

def dist_to_segments(segs, x, y):
    best = 1e9
    for ax, ay, dx, dy, L2 in segs:
        t = 0.0 if L2 == 0 else max(0.0, min(1.0, ((x-ax)*dx + (y-ay)*dy) / L2))
        ex, ey = x - (ax + t*dx), y - (ay + t*dy)
        d = ex*ex + ey*ey
        if d < best:
            best = d
    return math.sqrt(best)

def dist_to_rrect(x, y, rx):
    """圆角矩形（0,0..32,32）的有符号距离，负值在内部"""
    qx, qy = abs(x - 16) - (16 - rx), abs(y - 16) - (16 - rx)
    return math.hypot(max(qx, 0), max(qy, 0)) + min(max(qx, qy), 0) - rx

def coverage(fn, px, py, step, thresh):
    d = fn(px + 0.5 * step, py + 0.5 * step) - thresh
    if d < -0.9 * step: return 1.0
    if d >  0.9 * step: return 0.0
    hit = 0
    for i in range(4):
        for j in range(4):
            if fn(px + (i + 0.5) * step / 4, py + (j + 0.5) * step / 4) <= thresh:
                hit += 1
    return hit / 16

def render(size, rx, segs, stroke, rgb):
    step = 32.0 / size
    rows = []
    for py in range(size):
        row = []
        for px in range(size):
            ux, uy = px * step, py * step
            bg = coverage(lambda x, y: dist_to_rrect(x, y, rx), ux, uy, step, 0.0)
            fg = min(coverage(lambda x, y: dist_to_segments(segs, x, y),
                              ux, uy, step, stroke / 2), bg)
            a = round(bg * 255)
            # 全透明像素的 RGB 一并清零：有的图标渲染器不看 alpha 直接取 RGB，
            # 留着底色会让圆角外那四个角泛出蓝点
            row.append((0, 0, 0, 0) if a == 0
                       else tuple(round(c + (255 - c) * fg) for c in rgb) + (a,))
        rows.append(row)
    return rows

# ── PNG / ICO 封装 ─────────────────────────────────────────────────────

def png(rows):
    h, w = len(rows), len(rows[0])
    raw = b"".join(b"\x00" + bytes(v for p in r for v in p) for r in rows)
    def chunk(t, d):
        return struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d))
    return (b"\x89PNG\r\n\x1a\n"
            + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 6, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(raw, 9))
            + chunk(b"IEND", b""))

def ico(images):
    """images: [(size, png_bytes)]。ICO 允许直接内嵌 PNG，Vista 以后都认。"""
    head = struct.pack("<HHH", 0, 1, len(images))
    offset = 6 + 16 * len(images)
    entries = blobs = b""
    for size, data in images:
        entries += struct.pack("<BBBBHHII", size, size, 0, 0, 1, 32, len(data), offset)
        blobs += data
        offset += len(data)
    return head + entries + blobs

def main():
    rgb, stroke, ds = parse_svg(SVG.read_text())
    pts = [parse_path(d) for d in ds]
    segs = []
    for chain in pts:
        for (ax, ay), (bx, by) in zip(chain, chain[1:]):
            segs.append((ax, ay, bx - ax, by - ay, (bx - ax) ** 2 + (by - ay) ** 2))
    print(f"从 favicon.svg 读到：主色 #{'%02x%02x%02x' % rgb}，线宽 {stroke}，"
          f"{len(ds)} 条路径 / {len(segs)} 段")

    ico_sizes = [16, 32, 48]
    imgs = [(s, png(render(s, 7.5, segs, stroke, rgb))) for s in ico_sizes]
    (ROOT / "public" / "favicon.ico").write_bytes(ico(imgs))
    print(f"  favicon.ico          {'/'.join(map(str, ico_sizes))}  圆角 7.5")

    # apple-touch-icon 不切圆角也不留白：iOS 自己会切圆角，预先切会切两次
    (ROOT / "public" / "apple-touch-icon.png").write_bytes(png(render(180, 0, segs, stroke, rgb)))
    print("  apple-touch-icon.png 180x180  满底方形")

if __name__ == "__main__":
    main()
