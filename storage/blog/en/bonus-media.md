---
title: "Member bonus ... media: foreign files in your house"
date: "2026-09-17"
version: "1.0.0"
tags: ["media", "uploads", "php", "security", "architecture"]
excerpt: "The fifth bonus for registered readers. Uploads are the second gate through which the foreign enters the app ... this time not text but files. Why a filename is a claim and not a fact, why every asset remembers its provider, and why even deletion has an order, all on real code from this repo."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-media-pre-deti"
published: true
---

> This is the fifth **member bonus**. Previous parts: [the members-only
> lock](/en/blog/bonus-members-only), [the immutable audit
> log](/en/blog/bonus-audit-log), [full-text search via
> FTS5](/en/blog/bonus-full-text-search) and [comments with
> moderation](/en/blog/bonus-comments). Today: the second gate for
> strangers ... media uploads. A comment writes a row into the database.
> A file is let into the filesystem, where the app lives. That is an
> entirely different kind of party.

You're a developer. Last time it was a form that let foreign text into
your database, and it was trusted with zero.

Comments were gate number one.

Uploads are gate number two. They don't write rows. They let files into
the filesystem, into the place where the app you spent all week writing
lives.

The rule of both gates is the same: the foreign may enter, but only on
your terms.

## A filename is a claim, not a fact

A visitor uploads a file. The browser reports a name, a size, a type.

None of that is a fact. All of it is one side's claim.

That's why `LocalStorageProvider` treats the report like a statement
that must be verified, and normalizes once, at the boundary:

```php
// src/Modules/Core/Infrastructure/Storage/LocalStorageProvider.php — boundary
$size = is_numeric($file['size'] ?? null) ? (int) $file['size'] : 0;
$this->validateFile($tmpName, $originalName, $size);
```

The size is cast once. Paths are checked,`../` in a filename is an
attempt to walk out of the designated directory, and it ends in refusal.

And the type? The browser says `image/png`. The provider doesn't ask the
browser. It asks the bytes:

```php
// LocalStorageProvider.php — the real type, not the reported one
$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($filePath);
```

`finfo` reads content. Just like a guest's name in the comments, the
claim arrived from a stranger, the fact was established at home.

## The type decides, the extension obeys

The detected type doesn't just serve as a record. It decides which
extensions are still legal:

```php
// LocalStorageProvider.php — whitelist bound to the detected type
return in_array($extension, self::ALLOWED[$mime] ?? [], true);
```

`ALLOWED` is a map, a few legal extensions for `image/png`, different
ones for `application/pdf`. An unknown type has no extension that could
get in.

A file renamed to `photo.png` therefore doesn't pass as an image when
its bytes say otherwise. The whitelist isn't a list of allowed names,
it's a list of allowed truths.

## Two truths about one file

An asset always has two truths. The file on disk and the row in
`media.db`.

Upload creates them in order: first `store()` on disk, then `insert()`
into the database.

```php
// src/Modules/Media/Application/Services/MediaService.php — upload
$result = $storage->store($file);
$asset = MediaAsset::create(/* ... from the result */);
$this->repository->insert($asset);
```

Deletion runs the other way, and the order is not indifferent:

```php
// MediaService.php — delete: file first, row second
$storage = self::resolveProvider($asset->provider(), $this->storage);
$storage->delete($asset->path());
$this->repository->deleteTagsForAsset($id);

return $this->repository->delete($id);
```

Be honest: between the filesystem and SQLite you write no `BEGIN COMMIT`.
A transaction across two worlds doesn't exist.

So the order picked the side where the mistake is visible. A row without
a file can be found and cleaned up. A file without a row is an orphan
nobody will ever save, the app doesn't know it exists.

## Every asset remembers where it came from

Today you configure one storage provider. A year from now you swap it.

The files stayed. That's why every row in `media.db` carries a
`provider` column, and deletion looks up the host one asset at a time:

```php
// MediaService.php — resolveProvider on every touch
return match ($provider) {
    'local' => new LocalStorageProviderAdapter(new LocalStorageProvider()),
    'cloudinary' => new CloudinaryProvider(),
    default => $default,
};
```

An asset uploaded to the cloud is deleted in the cloud, even when the
config now points at the local disk. Configuration is the current
truth. The history of files is a fact written in the row.

The same discipline applies on upload: the cloud accepts images only.

```php
// MediaService.php — guard before storing
if ('cloudinary' === $provider && !$this->isImageMimeType($mimeType)) {
    throw new \RuntimeException('Cloudinary only accepts images (jpg, png, gif, webp).');
}
```

## Organization is metadata, not structure

Category is a column. Tags are a junction table, `media_tags`.

`setTagsForAsset` deletes the old ones first, then inserts the new, a
tag list is always the whole truth, never an increment:

```php
// src/Modules/Media/Infrastructure/Persistence/MediaRepository.php — tags
$this->deleteTagsForAsset($id);

foreach ($tags as $tag) {
    $tag = trim(strtolower($tag));
    // ... insert
}
```

Tags are normalized at the boundary too, trimmed and lowercased.
Without it, `PHP` and `php` would be two worlds.

Type filtering runs on a MIME prefix,`LIKE 'image/%'`. The overview
can also order by `view_count` ... what you actually looked at gets
counted.

## Two gates in one app

Comments and media are two write paths for the foreign. They share a
frame:

| | Comments | Media |
|---|---|---|
| what enters | text | file |
| where | users.db (pending) | filesystem + media.db |
| first defense | rate limit before validation | finfo + content whitelist |
| publishing | moderation in `/mark/comments` | immediate, the whitelist is the gate |
| audit | logs every touch | logs every touch |
| cache | flush after write | flush after write |

Both paths end in the audit log and both flush the cache
(`CacheInvalidator::flush()`), so the rate-limit fallback never serves
pre-write content.

## When the whitelist doesn't hold (the mandatory honesty)

The whitelist is a defense at the entrance, not at the exit.

A file that passed is served under a header the server chooses, and
CSP decides what its content may launch. An uploaded file is therefore
not "safe" ... it's an enemy let inside with its hands tied.

The second honesty: `batchDelete` returns the list of IDs that were
actually deleted. Partial success here is an honest result, not an API
error.

And the third: there is no resize pipeline here. A file keeps the size
it arrived with, if you want thumbnails, upload them or add a
generator.

## Practically

1. **Upload via `/mark/media/upload`** ... the provider choice is in the
   form, and the audit log records the action on its own.
2. **Describe the asset** ... alt text, caption, category and tags are
   the metadata the manager's search grows from.
3. **Let the picker work** ... `/mark/media/json` is the JSON endpoint
   the article editor calls when you pick an image.

The picker then answers the question "which file" ... not "where is it".

## Sources

- [OWASP, File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html) ... the frame where whitelisting and serving belong
- [PHP, finfo](https://www.php.net/manual/en/book.fileinfo.php) ... detecting the type from content, not from the name
- [PHP, move_uploaded_file](https://www.php.net/manual/en/function.move-uploaded-file.php) ... the only legitimate move of an uploaded file
- [Nativa Minimal Documentation](/docs/getting-started) ... this kit's official getting started guide
- [docs/blog-voice.md](/docs/blog-voice) ... the guide where the bonus series lives
