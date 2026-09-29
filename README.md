# SilverShop Downloads

Downloadable (digital) products for [SilverShop](https://github.com/silvershop/silvershop-core).

Attach one or more files to a product and let paying customers download them through a secure,
ownership-checked link. Files live in the **protected** asset store and are never exposed by a public URL —
every download is streamed through a gated controller after verifying the customer owns a paid order
containing the product.

## Features

- **Digital product flag** on each product (`IsDigital`) — a seam shipping modules can read to skip
  weight/shipping for the line.
- **Multiple files per product**, managed on a dedicated *Downloads* tab in the CMS.
- **Protected storage** — files are moved out of the public asset store on save.
- **Ownership-gated delivery** — a download is only served to a logged-in customer who has a *paid* order
  containing the product.
- **Per-customer download limit** and optional **link expiry** (configurable).
- **Download log** for the limit check and a basic audit trail.
- **`$member->AvailableDownloads()`** helper for an account-page "My downloads" list.
- Translatable (en, nl, de, fr, it, es).

## Requirements

- PHP 8.3+
- silverstripe/framework ^6.0
- silvershop/core ^6

## Installation

```sh
composer require silvershop/downloads
```

Then run `dev/build?flush=all`.

## Usage

1. Edit a product, tick **Digital product** on the *Main* tab and **save**. A **Downloads** tab appears (it stays
   hidden on physical products, so the catalog isn't cluttered).
2. On the **Downloads** tab, add a *Download* record and upload its file. The file is moved to the protected asset
   store and kept there — it is never served by a public URL.
3. Once a customer pays for an order containing that product, they can download the file. Build the link in
   a template or account page with:

   ```html
   <% loop $Member.AvailableDownloads %>
       <a href="$DownloadLink">$Title</a>
   <% end_loop %>
   ```

`DownloadLink()` points at the gated controller (`shop-downloads/process/<id>`), which enforces login,
ownership, the download limit and expiry before streaming the file.

## Configuration

```yaml
SilverShop\Downloads\Download:
  download_limit: 5      # max downloads per customer, per file (0 = unlimited)
  link_expiry_days: 0    # days a link stays valid after the order was paid (0 = never expires)
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
