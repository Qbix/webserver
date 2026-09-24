# Image Processing

Qbix Server resizes and converts images on request, picks the smallest format the browser accepts, and caches the results on disk. There is nothing to install beyond PHP's GD extension, and no application code is involved: add `?w=400` to an image URL and the server does the rest.

This page describes exactly what happens to a request, where the results go, and the limits of the current implementation. For ordinary static file serving, see [static-files.md](static-files.md).

## What triggers processing

An image request is handled by the image pipeline in two cases. Everything else is served as a plain static file.

**Resize.** A `GET` or `HEAD` for a `.png`, `.jpg`, `.jpeg`, `.gif`, `.webp`, `.bmp` or `.avif` URL whose query string includes `w` or `h`:

```
/photos/hero.jpg?w=400        400px wide, height in proportion
/photos/hero.jpg?h=300        300px tall, width in proportion
/photos/hero.jpg?w=400&h=300  exactly 400×300
```

**Conversion.** A request for an image that does not exist on disk, when a file with the same name and a different image extension does:

```
/photos/hero.webp   no hero.webp on disk, but hero.png exists → WebP made from hero.png
/photos/hero.jpg    no hero.jpg on disk, but hero.png exists  → JPEG made from hero.png
```

For conversion, the server looks for a source in this order: `.png`, `.jpg`, `.jpeg`, `.gif`, `.webp`, `.bmp`. AVIF can be produced but is not read as a source.

## How a request is processed

1. **Find the source.** The file at the URL, or for a conversion, the first same-named file in the order above.
2. **Work out the size.** `w` and `h` are clamped to 1–4096 pixels and then to the source's own dimensions, so images are never enlarged. With only one of `w` or `h`, the other is computed from the source's aspect ratio.
3. **Choose the output format.** Start from the extension in the URL. Then, if the browser's `Accept` header includes `image/avif` and PHP's GD was built with AVIF support, switch to AVIF. Otherwise, if `Accept` includes `image/webp`, switch to WebP.
4. **Apply Save-Data.** If the request carries `Save-Data: on`, quality drops to 50, and a conversion without `w` or `h` is capped at 800px wide.
5. **Check the cache.** If a cached result exists and is newer than the source file, serve it.
6. **Generate.** Decode with GD, resample with `imagecopyresampled()`, encode, and write the result to the cache. This happens in the worker handling the request.
7. **Respond** with the cached file (see [Response headers](#response-headers)).

### Format negotiation

Step 3 means the extension in the URL is a request, not a promise. `<img src="/photo.jpg?w=400">` returns AVIF to a browser that advertises AVIF, WebP to one that advertises only WebP, and JPEG to one that advertises neither. You get the benefit of `<picture>` with a list of `<source>` elements without writing one. The response carries `Vary: Accept` so shared caches store each variant separately.

### Transparency

When the output format supports an alpha channel (PNG, WebP, GIF, AVIF), transparency is preserved. When it does not (JPEG), transparent pixels are composited onto white, which is what you want for a PNG logo served as JPEG, and not what you want if you expected the transparency to survive. Request `.png` or `.webp` for images that need it.

### Quality

The default encoding quality is 82, set by `Q.images.quality`. For JPEG, WebP and AVIF, the value is passed to GD directly. For PNG, which is lossless, it is mapped to a zlib compression level: quality 100 uses level 0 and quality 0 uses level 9. `Save-Data: on` overrides the setting with 50.

GD occasionally writes an empty file when encoding lossy WebP. The server detects this and re-encodes losslessly.

## The cache

Results are written under the app directory (or, when running standalone, the parent of `--root`):

```
files/Q/cached/images/{url directory}/{name}/{w}x{h}.{format}
```

For example, `/photos/hero.jpg?w=400` requested by a browser that accepts AVIF becomes:

```
files/Q/cached/images/photos/hero/400x.avif
```

A conversion with no size is stored as `original.{format}`. Save-Data variants get an `_sd` suffix (`400x_sd.webp`), so visitors on metered connections and everyone else are cached separately.

**Invalidation.** Before serving a cached file, the server compares its modification time with the source's. Replace `hero.jpg` and every size and format derived from it is regenerated on its next request. Nothing needs to be purged by hand.

**Eviction.** After every 50 images generated, the server totals the cache. If it exceeds the limit (`Q.images.cache.maxSize`, in bytes, default 268435456 = 256MB), it deletes the least recently accessed files until the total is under 75% of the limit, and removes directories left empty. "Least recently accessed" uses the filesystem's access time; on a volume mounted `noatime` this becomes least recently created.

## Response headers

```
Content-Type: image/avif
Cache-Control: public, max-age=31536000, immutable
ETag: "66f3a1b2-4c1e"
Vary: Accept
```

The `ETag` is derived from the cached file's modification time and size, and a matching `If-None-Match` gets a `304 Not Modified`.

`immutable` tells browsers not to revalidate for a year. The server regenerates when the source changes, but a browser that already has the old version will keep showing it. If you replace images in place and need visitors to see the change, change the URL: add a version parameter (`hero.jpg?w=400&v=2`). Extra parameters do not affect the server's cache key, so this costs nothing on the server.

## Animated GIFs

GD reads only the first frame of a GIF. The server detects animated GIFs and, when the request is for the GIF itself with no resize, copies it through unchanged. Any resize or conversion of an animated GIF produces a still image of the first frame. Note that step 3 applies here too: a browser that accepts WebP asking for `anim.gif?w=200` gets a still WebP.

## Requirements

- **GD** for everything. Without it the pipeline declines and the original file is served.
- **WebP output and input** need GD built with WebP support (standard in most PHP builds).
- **AVIF output** needs `imageavif()`, available from PHP 8.1 when GD is built with libavif. Without it, negotiation stops at WebP.
- **BMP input** needs `imagecreatefrombmp()` (PHP 7.2+).

Check with `php -r 'print_r(gd_info());'`.

## Configuration

```json
{
  "Q": {
    "images": {
      "quality": 82,
      "cache": { "maxSize": 268435456 }
    }
  }
}
```

| Key | Default | Meaning |
|---|---|---|
| `Q.images.quality` | `82` | Encoding quality, 1–100 |
| `Q.images.cache.maxSize` | `268435456` | Cache size limit in bytes before eviction |

## Current limitations

- **`w` and `h` together stretch.** When both are given, the output is exactly `w`×`h`, and the image is scaled to fill it without cropping. If the requested shape differs from the source's, the image is distorted. To keep proportions, pass only one dimension.
- **No upscaling.** Asking for more pixels than the source has returns the source's size. With both dimensions given, each is clamped separately, which changes the shape.
- **Other query parameters are ignored.** There is no `fit`, `crop`, `q` or `dpr` parameter yet.
- **Generation is synchronous.** The first request for a new size pays the encoding cost (AVIF is the slowest). Two simultaneous first requests for the same size will both generate it.

## Compared with nginx

| nginx | Qbix Server |
|---|---|
| `image_filter resize 400 -;` (needs `ngx_http_image_filter_module`) | `?w=400` |
| `map $http_accept $webp_suffix { ... }` plus `try_files $uri$webp_suffix $uri` and pre-generated `.webp` files | Automatic, from the `Accept` header, generated on first request |
| A separate job to regenerate thumbnails when originals change | Modification-time check on every request |

---
[← Static Files](static-files.md) · [← Back to README](../README.md)
