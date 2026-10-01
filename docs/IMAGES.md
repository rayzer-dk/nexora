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

## Cleanup (`commerce:media:gc`, daily, dry-run unless `--apply`)

* Removes size files of older generations (younger than 7 days are kept) and sizes whose master no longer exists.
  Masters and originals are never touched by this step. A removed size is simply made again when requested.
* Moves **unused pictures** to `var/media-trash/<date>/` and purges the trash after 30 days. A picture counts as used when
  any row points at it by id (products, documents, category images, page share images, the media library, and every other
  foreign key to the asset table), when its file name appears in any rich text or settings column, or when it is younger
  than 30 days or a demo picture. A column that cannot be checked counts as "used".
* `--clear-sizes` removes every made size (they return on demand). `--orphan-days`, `--grace-days`, `--trash-days` tune the periods.

Copy files back from `var/media-trash/` to `public/media/` (same relative path) and upload the picture again to restore one.
