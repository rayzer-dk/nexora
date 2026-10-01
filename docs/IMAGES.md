# Images: originals, folders, cached sizes, cleanup

## Model

You upload the **original**; formats and sizes are made for you and kept in a separate **cache**.

* **Original:** `media/<folder>/<name>.<ext>`, in the library folder you chose when uploading (`media/uploads/` when no folder
  is chosen). It keeps its format (JPEG, PNG, WebP or AVIF; HEIC becomes JPEG), the EXIF orientation is applied, camera
  metadata (GPS and so on) is removed, and a picture longer than 2560 px is scaled down. The file name is the name of the
  uploaded file in plain letters (`iphone-15-pro.jpg`); a taken name gets `-2`, `-3`. The same picture uploaded twice is
  stored once.
* **Cache:** `media/cache/<name>-g<generation>/<folder>/<name>.<webp|avif|jpg>`, made from the original. Deleting the whole
  cache is always safe: it is made again when pages ask for it.

| Name | Default width | Used for |
|---|---|---|
| `thumb` | 160 | cart, menu, lists, gallery strip |
| `card` | 480 | catalog and slider cards, blog cards |
| `product` | 960 | product page, article images |
| `zoom` | 1600 | large view, full-screen viewer |

Pages build these URLs from the stored original URL without a database query (`media_picture`, `media_attrs`,
`media_variant`, `media_srcset` in Twig). The set of names is closed; any other name is a 404. A size is never larger than
the original.

## Format on the storefront

Media library > Image processing:

* **WebP only** (default): every size is WebP.
* **AVIF with a WebP fallback:** the page contains `<picture>` with an AVIF source first; browsers without AVIF use the WebP
  `<img>`. Offered only when this PHP build can write AVIF (`imageavif`); otherwise WebP is used and the settings say so.
* **JPEG:** maximum compatibility.

## When a size is made

* **Main photo of a product:** `thumb`, `card` and `product` (both WebP and AVIF in AVIF mode) are made when the photo
  becomes the primary one.
* **Every other photo:** on the first request for the URL. The media controller makes the file under a lock, writes it
  atomically and answers; afterwards the web server serves the file straight from disk (one-year immutable cache).
* **After an import:** `commerce:media:warm` (scheduled every 15 minutes) prepares the everyday sizes of all main photos.
* If the server cannot make a size, the controller answers with the original itself, so a picture is never broken.

Nginx needs `try_files $uri /index.php$is_args$args;`, Apache uses the bundled `.htaccess`.

## Changing sizes or quality

Media library > Image processing. Changing a width or a quality raises the **generation**. New pages use new URLs (made on
demand), nothing is uploaded again, and an old URL redirects to the current one.

## Folders

Folders are real directories you create in the Media library (nested, any depth). Choose one when uploading in the library or
in the product form (the last choice is remembered). Moving a picture to another folder in the library only changes where
it is listed: the file and its URL stay the same, so no page or description ever breaks. Videos uploaded as files keep
`media/video/<2 hex>/` and review photos go to `media/reviews/`.

## Product photos and videos

Photos and videos of a product are one list. Drag items (or use the arrow buttons) to order them; the order is saved at once.
The first photo is the main photo; a video can sit anywhere in the list.

## Cleanup

* `commerce:media:gc` (daily, dry-run unless `--apply`) removes cache files of older generations (younger than 7 days are
  kept) and cache files whose original no longer exists, and purges the trash after 30 days. Originals are never
  touched by this step. A removed size is simply made again when requested.
* **Unused pictures are removed by hand**: Media library > Unused pictures lists the candidates as thumbnails with check
  boxes. A picture counts as used when any row points at it by id (products, product videos, documents, category images,
  page share images, and every other foreign key to the asset table; being listed in the library does not count), when its
  file name appears in any rich text or settings column, or when it is younger than the chosen age (30 days by default)
  or a demo picture. A column that cannot be checked counts as "used". Each picture in the confirmed selection is checked
  again before it moves to `var/media-trash/<date>/` with a manifest (`_assets/<id>.json`).
* The same screen lists the trash and restores pictures (files and records, including library membership) for 30 days.
* `commerce:media:gc --orphans --apply` does the unused-picture step without a screen (opt-in; not scheduled).
* `--clear-sizes` removes the whole size cache (it returns on demand). `--orphan-days`, `--grace-days`, `--trash-days` tune the periods.

## Video

A product video is a link (YouTube, Vimeo or a direct https mp4/webm file) stored in `mc_product_video`; nothing is hosted.
The product gallery shows the preview picture and creates the player only after a click. The preview is fetched once when
the link is saved (YouTube thumbnail, Vimeo oEmbed; only those fixed hosts are contacted) and stored as a library picture,
or an own picture is uploaded. Without a reachable preview YouTube falls back to its own thumbnail and the other providers
to a neutral placeholder.
