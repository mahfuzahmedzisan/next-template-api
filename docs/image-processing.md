# Image conversion

Raster uploads (JPEG, PNG, GIF, WebP, BMP, and the rest) are stored as WebP. SVG uploads are sanitized, then stored as `.svg`.

`App\Services\ImageConversionService` owns the pipeline. Call it from any service, controller, or job:

```php
$result = app(ImageConversionService::class)->convertAndStore(
    disk: 'public',
    directory: 'avatars/'.$user->id,
    source: $file,
    basename: (string) Str::ulid(),
    options: new ImageConversionOptions(width: 512, height: 512, quality: 82),
);
```

Avatars go through `App\Services\AvatarStorageService`, which only handles the user path and the previous-file delete.

## Required capability

WebP encoding must succeed. The service does not store a PNG fallback. Either GD WebP or the `cwebp` binary must be present:

```bash
php -r "var_dump(function_exists('imagewebp'));"
cwebp -version
```

The production image (`Dockerfile`) builds GD with `--with-webp` and installs the `webp` package (`cwebp` / `dwebp`).

### Windows

1. Enable the `gd` extension compiled with WebP, or install libwebp and put `cwebp.exe` on `PATH`.
2. Confirm with the commands above.

### Linux without Docker

```bash
sudo apt-get install -y webp
```

Rebuild PHP GD with `libwebp-dev` when you want `imagewebp()` as well. `cwebp` alone is enough for the service.

## SVG

SVG uploads are stored as `.svg` only after `App\Services\SvgSanitizer` runs. Files with a DOCTYPE or entity declarations are rejected. Scripts, event handlers, `javascript:` values, link-rewriting animations, unsafe CSS, and remote references are removed. If sanitization fails, the upload is rejected. Spatie may still minify the cleaned file when `svgo` is on `PATH`. Raster conversion is not applied to SVG.

## Config

`config/image-optimizer.php` sets `cwebp` to `-q 85`. Avatar encode quality is 82 via `ImageConversionOptions`.
