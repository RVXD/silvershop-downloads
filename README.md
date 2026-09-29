# SilverShop Downloads

Downloadable (digital) products for [SilverShop](https://github.com/silvershop/silvershop-core).

Attach one or more files to a product and let paying customers download them through a secure,
ownership-checked link. Files live in the **protected** asset store and are never exposed by a public URL —
every download is streamed through a gated controller after verifying the customer owns a paid order
containing the product.

## Features

- **Digital product flag** on each product (`IsDigital`). Marking a product digital hides its **Shipping** and
  **Stock** tabs (they don't apply), and `isDigital()` is a seam a shipping module can read to skip
  weight/shipping for the line.
- **Multiple files per product**, managed on a dedicated *Downloads* tab in the CMS (with a file-size column).
- **Per-variation files** — a product's variations can each carry their own downloads (delivered only to buyers of
  that variation), alongside shared product-wide files (delivered to buyers of any variation).
- **Protected storage** — files are moved out of the public asset store on save and kept there across publishes.
- **Ownership-gated delivery** — a download is only served to a customer with a *paid* order containing the
  product/variation.
- **Order confirmation email + guest checkout** — download links are added to the order confirmation email, as
  **tokenised links that work without a login** (so guest checkout works); logged-in customers also get the
  account list. The email intro text is configurable in *Settings → Shop → Downloads*.
- **Per-customer download limit** and optional **link expiry** (configurable).
- **Sold downloads can't be deleted** — once a product/variation has been ordered, its downloads are protected from
  deletion so buyers don't lose access.
- **Common digital-goods file types allowed** (ebooks, design source, fonts, lossless audio) that the default
  upload whitelist blocks — configurable.
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

Download limit and expiry are edited in the CMS, not in YAML:

- **Global defaults** — *Settings → Shop → Downloads*: **Download limit per customer** (0 = unlimited) and
  **Download expiry (days after purchase)** (0 = never expires). Both default to 0 (perpetual, unlimited access),
  matching the market norm.
- **Per-product overrides** — on a product's **Downloads** tab, expand **Download settings** and tick *Override the
  shop default…* to set a limit / expiry just for that product.

Access is granted only once the order is **paid**; expiry is counted from the order's paid date.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
