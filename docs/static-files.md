# Static Files

Qbix Server serves files from the document root directly, without running PHP. It handles conditional requests, compresses text on the way out, and hands image URLs with size parameters to the image pipeline.

## Serving

A request for a file with an allowed extension is answered straight from disk. The list covers text and data (`html`, `htm`, `txt`, `md`, `json`, `xml`, `yaml`, `yml`, `csv`, `tsv`, `log`), code (`css`, `js`, `mjs`, `map`, `wasm`), images (`png`, `gif`, `webp`, `jpg`, `jpeg`, `svg`, `bmp`, `ico`, `avif`), fonts (`woff`, `woff2`, `ttf`, `otf`), media (`mp3`, `wav`, `ogg`, `mp4`, `webm`) and `pdf` and `zip`. The response includes:

- **`Content-Type`**, mapped from the extension. Files with any other extension are refused, so a stray `.env` or `.sql` in the document root is not downloadable.
- **`ETag`**, derived from the file's modification time and size. A request with a matching `If-None-Match` gets `304 Not Modified` and no body.
- **`Last-Modified`**, from the file's modification time, for clients that validate with `If-Modified-Since` instead.
- **`Cache-Control: public, max-age=0, must-revalidate`**. Browsers keep the file but check with the server before reusing it, and the check costs one `304`. If your asset URLs carry a version or hash, send a longer lifetime from your own config or a reverse proxy.

Recently served files are held in an in-memory response cache keyed by path and content encoding, and revalidated against the file's modification time at intervals, so a hot file is not re-read from disk on every request.

## Compression

Text types (HTML, CSS, JavaScript, JSON, SVG and similar) under 5MB are compressed with gzip when the request's `Accept-Encoding` allows it, and the response carries `Vary: Accept-Encoding`.

By default this happens per request, with the result kept in the in-memory response cache. To compress each file once and keep the results on disk, shared by all workers, enable the precompression cache:

```json
{
  "Q": {
    "webserver": {
      "precompress": { "enabled": true, "maxFiles": 1000, "minSize": 1024, "level": 6 }
    }
  }
}
```

The cache is a directory of `.gz` files (default: `qbixserver-precompress` under the system temp directory, set with `dir`), trimmed to the `maxFiles` most recently served. Because they are real files, a front proxy or CDN can serve them directly.

## Images

Image URLs can carry a size, and a request for a format that does not exist on disk is converted from one that does:

```
/photos/hero.jpg?w=400    resized to 400px wide
/photos/hero.webp         made from hero.png if there is no hero.webp
```

The server also picks AVIF or WebP when the browser accepts them, honours `Save-Data`, and caches every result on disk. How all of this works, and its current limits, is in [images.md](images.md).

## Access-controlled files

To serve a file only after PHP has checked permissions, have the script respond with `X-Accel-Redirect` and the server will stream the file itself. See [headers.md](headers.md).

---
[Image Processing →](images.md) · [← Back to README](../README.md)
