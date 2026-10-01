# Images: master, named sizes, cleanup

## Model

One upload stores **one master file** (`media/<stem>.webp`, at most 1920 px wide, EXIF orientation applied, metadata
stripped) and, optionally, the untouched original (`<stem>.source.<ext>`). Every other size is a **named size** made from
the master:

| Name | Default width | Used for |
|---|---|---|
| `thumb` | 160 | cart, menu, lists, gallery strip |
| `card` | 480 | catalog and slider cards, blog cards |
| `product` | 960 | product page, article images |
| `zoom` | 1600 | large view, retina product page |

The full-screen viewer loads the master itself.

A size lives at `media/<stem>.<name>-g<generation>.<ext>`. Pages build these URLs from the stored master URL without a
database query (`media_attrs`, `media_variant`, `media_srcset` in Twig). The set of names is closed; any other name is a 404.

## When a size is made

* **Main photo of a product:** `thumb`, `card` and `product` are made when the photo becomes the primary one.
* **Every other photo:** on the first request for the URL. The media controller makes the file under a lock, writes it
  atomically and answers; afterwards the web server serves the file straight from disk (one-year immutable cache).
* **After an import:** `commerce:media:warm` (scheduled every 15 minutes) prepares the everyday sizes of all main photos.
* If the server cannot make a size, the page falls back to the master (`chrome.js`), so a picture is never broken.

Nginx needs `try_files $uri /index.php$is_args$args;`, Apache uses the bundled `.htaccess`.

## Changing sizes

Media library > Image processing. Changing a width or the quality raises the **generation**. New pages use new URLs
(made on demand), nothing is uploaded again, and an old URL redirects to the current one.

## Where files are stored

New uploads go to `media/catalog/<first 2 hex of the hash>/<hash>.<ext>` and `media/video/<2 hex>/<hash>.<ext>`: 256 fixed
folders, no folder per day or month, however many pictures are imported. The library folders you create are only
labels in the database; they do not move files. Older files keep their old paths.

## Cleanup

* `commerce:media:gc` (daily, dry-run unless `--apply`) removes size files of older generations (younger than 7 days are
  kept) and sizes whose master no longer exists, and purges the trash after 30 days. Masters and originals are never
  touched by this step. A removed size is simply made again when requested.
* **Unused pictures are removed by hand**: Media library > Unused pictures lists the candidates as thumbnails with check
  boxes. A picture counts as used when any row points at it by id (products, product videos, documents, category images,
  page share images, and every other foreign key to the asset table; being listed in the library does not count), when its
  file name appears in any rich text or settings column, or when it is younger than the chosen age (30 days by default)
  or a demo picture. A column that cannot be checked counts as "used". Each picture in the confirmed selection is checked
  again before it moves to `var/media-trash/<date>/` with a manifest (`_assets/<id>.json`).
* The same screen lists the trash and restores pictures (files and records, including library membership) for 30 days.
* `commerce:media:gc --orphans --apply` does the unused-picture step without a screen (opt-in; not scheduled).
* `--clear-sizes` removes every made size (they return on demand). `--orphan-days`, `--grace-days`, `--trash-days` tune the periods.

## Video

A product video is a link (YouTube, Vimeo or a direct https mp4/webm file) stored in `mc_product_video`; nothing is hosted.
The product gallery shows the preview picture and creates the player only after a click. The preview is fetched once when
the link is saved (YouTube thumbnail, Vimeo oEmbed; only those fixed hosts are contacted) and stored as a library picture,
or an own picture is uploaded. Without a reachable preview YouTube falls back to its own thumbnail and the other providers
to a neutral placeholder.
