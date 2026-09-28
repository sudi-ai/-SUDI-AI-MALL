# Mall image delivery

The generator reads only the currently mounted mall uploads. It keeps originals,
dimensions, colour/EXIF metadata and the database unchanged, and writes optional
quality-92 WebP sidecars only when at least 15% smaller. Existing sidecars are
reused; animated PNGs and unsupported/oversized images keep their original files.
No image is generated during a customer request.

Install `cwebp` and its runtime dependencies in `deploy/media-tools/bin` and
`deploy/media-tools/lib`. The September 2026 server uses binaries extracted from
Ubuntu repository packages `webp` and `libwebpdemux2`; no system packages or
AI containers are changed. Record package hashes in the deployment backup.

Copy the Python script to `/opt/sudi-mall/deploy/optimize-images.py`. Install the
service and timer under `/etc/systemd/system` and enable the timer after the first
successful run. The timer has a CPU quota and follows the live mall release on
future deployments. It does not restart any application.

Apply the two maps and the commented location in `nginx-media.conf` only to the
mall Nginx config. Preserve all existing PHP, hidden-file and installer denials.
Back up that config and upload hashes first. Run `nginx -t` before reloading the
mall web container. Test WebP Accept, no WebP, `image/webp;q=0`, `?original=1`,
missing images, ETags, API and page responses, and original upload hashes.

WebP responses vary on Accept; images are cached for seven days. Clients without
WebP support and `?original=1` receive the original. No CDN or paid service is
enabled. To roll back, restore the previous mall Nginx config, validate and reload
only the mall web container, then disable the media timer. Sidecars can remain.

References: [Nginx try_files](https://nginx.org/en/docs/http/ngx_http_core_module.html#try_files),
[Nginx expires](https://nginx.org/en/docs/http/ngx_http_headers_module.html#expires).
