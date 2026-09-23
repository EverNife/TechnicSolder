# Mods

## POST /api/mod

Create a new mod.

**Permission required:** `mods_create`

### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Slug-style unique identifier (e.g. `buildcraft`). Must be unique. |
| `pretty_name` | string | Yes | Human-readable display name (e.g. `BuildCraft`). |
| `author` | string | No | Mod author name. |
| `description` | string | No | Short description of the mod. |
| `link` | string | No | URL to the mod's homepage. Must be a valid URL or null. |

### Example Request

```bash
curl -X POST https://solder.example.com/api/mod \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "buildcraft",
    "pretty_name": "BuildCraft",
    "author": "SpaceToad",
    "description": "Extending Minecraft with pipes, auto-crafting, and more.",
    "link": "https://www.mod-buildcraft.com"
  }'
```

### Response (201)

```json
{
  "id": 1,
  "name": "buildcraft",
  "pretty_name": "BuildCraft",
  "author": "SpaceToad",
  "description": "Extending Minecraft with pipes, auto-crafting, and more.",
  "link": "https://www.mod-buildcraft.com",
  "created_at": "2026-03-31T12:00:00.000000Z",
  "updated_at": "2026-03-31T12:00:00.000000Z"
}
```

### Error Response (422)

```json
{
  "error": {
    "name": ["The name has already been taken."]
  }
}
```

---

## PUT /api/mod/{slug}

Update an existing mod.

**Permission required:** `mods_manage`

### Path Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `slug` | string | The mod slug (name). |

### Request Body

All fields are optional. Only included fields are updated.

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Slug-style identifier. Must be unique. |
| `pretty_name` | string | Display name. |
| `author` | string | Mod author name. |
| `description` | string | Short description. |
| `link` | string | Homepage URL. Must be a valid URL or null. |

### Example Request

```bash
curl -X PUT https://solder.example.com/api/mod/buildcraft \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "pretty_name": "BuildCraft 2",
    "author": "SpaceToad & Developers"
  }'
```

### Response (200)

```json
{
  "id": 1,
  "name": "buildcraft",
  "pretty_name": "BuildCraft 2",
  "author": "SpaceToad & Developers",
  "description": "Extending Minecraft with pipes, auto-crafting, and more.",
  "link": "https://www.mod-buildcraft.com",
  "created_at": "2026-03-31T12:00:00.000000Z",
  "updated_at": "2026-03-31T12:05:00.000000Z"
}
```

### Error Responses

**Mod not found (404):**

```json
{
  "error": "Mod not found."
}
```

**Validation error (422):**

```json
{
  "error": {
    "name": ["The name has already been taken."]
  }
}
```

---

## DELETE /api/mod/{slug}

Delete a mod and all of its versions.

**Permission required:** `mods_delete`

### Path Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `slug` | string | The mod slug (name). |

### Example Request

```bash
curl -X DELETE https://solder.example.com/api/mod/buildcraft \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Response (200)

```json
{
  "success": "Mod deleted."
}
```

### Error Response (404)

```json
{
  "error": "Mod not found."
}
```

!!! warning
    Deleting a mod also deletes **all of its versions** and detaches them from all builds. This action cannot be undone.

---

## POST /api/mod/{slug}/version

Create a new version for a mod.

**Permission required:** `mods_manage`

### Path Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `slug` | string | The mod slug (name). |

### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `version` | string | Yes | Version string (e.g. `7.1.0`). Must be unique within the mod. |
| `md5` | string | Yes | MD5 hash of the mod archive file. |
| `filesize` | integer | No | File size in bytes. |

### Example Request

```bash
curl -X POST https://solder.example.com/api/mod/buildcraft/version \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "version": "7.1.0",
    "md5": "d41d8cd98f00b204e9800998ecf8427e",
    "filesize": 5242880
  }'
```

### Response (201)

```json
{
  "id": 1,
  "mod_id": 1,
  "version": "7.1.0",
  "md5": "d41d8cd98f00b204e9800998ecf8427e",
  "filesize": 5242880,
  "created_at": "2026-03-31T12:00:00.000000Z",
  "updated_at": "2026-03-31T12:00:00.000000Z"
}
```

### Error Responses

**Mod not found (404):**

```json
{
  "error": "Mod not found."
}
```

**Duplicate version (422):**

```json
{
  "error": "Version already exists for this mod."
}
```

**Validation error (422):**

```json
{
  "error": {
    "version": ["The version field is required."],
    "md5": ["The md5 field is required."]
  }
}
```

---

## POST /api/mod/{slug}/{version}/file

Upload the archive for a mod version. The version is created if it does not exist yet, so one call per file is enough.

The archive is written to `SOLDER_REPO_LOCATION` at `mods/{slug}/{slug}-{version}.zip`, the same place the launcher downloads it from through `SOLDER_MIRROR_URL`, and the version's `md5` and `filesize` are set from the file that landed on disk.

- A `.zip` is stored as is.
- A `.jar` is wrapped into a zip containing `mods/<jar name>`, the layout the Technic launcher extracts.

**Permission required:** `mods_manage`

!!! note
    `SOLDER_REPO_LOCATION` must be a local directory for uploads to work. Files are limited to 100 MB; larger archives still have to be copied to the server and hashed with Rehash.

### Path Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `slug` | string | The mod slug (name). The mod must already exist. |
| `version` | string | The version string. Must not start with `.` or contain `/`, `\` or `..`. |

### Request Body (`multipart/form-data`)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `file` | file | Yes | A `.zip` or `.jar`, at most 100 MB. |
| `replace` | boolean | No | Overwrite an archive that already exists on disk. Without it, an existing archive answers 409. |
| `notes` | string | No | Notes for the version, used only when the version is created. |

### Example Requests

```bash
# upload (or create) version 1.20.1-2.3.0 of "jei" from a jar
curl -X POST https://solder.example.com/api/mod/jei/1.20.1-2.3.0/file \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "file=@jei-1.20.1-forge-15.2.0.jar"

# replace a wrong archive
curl -X POST https://solder.example.com/api/mod/jei/1.20.1-2.3.0/file \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "file=@jei.zip" -F "replace=true"
```

### Response (201 when the version was created, 200 when it already existed)

```json
{
  "id": 12,
  "version": "1.20.1-2.3.0",
  "md5": "3f2a9c6e1b0d4e8f7a6b5c4d3e2f1a0b",
  "filesize": 1543210,
  "url": "https://mods.example.com/mods/jei/jei-1.20.1-2.3.0.zip"
}
```

### Error Responses

**Mod not found (404):**

```json
{
  "error": "Mod not found. Create it first with POST /api/mod."
}
```

**Archive already on disk (409):**

```json
{
  "error": "An archive already exists for jei 1.20.1-2.3.0. Resend with replace=true to overwrite it."
}
```

**Repository is a URL (409):**

```json
{
  "error": "Uploads need SOLDER_REPO_LOCATION to be a local directory; it is set to a URL (https://mods.example.com/). Point it at the directory nginx serves as the mirror root."
}
```

**Validation error (422)** — missing file, file over 100 MB, wrong extension, unreadable zip, or a mod name/version that cannot be used in a path:

```json
{
  "error": {
    "file": ["The file field is required."]
  }
}
```

```json
{
  "error": "The file must be a .zip or a .jar; got \"jei.rar\"."
}
```

### Keeping a folder of mods in sync

For each local archive:

1. Compute the MD5 of the **zip that the server will store**.
2. `GET /api/mod/{slug}/{version}`.
3. If it answers 404, or its `md5` differs, `POST /api/mod/{slug}/{version}/file` — with `replace=true` when the version already existed.

!!! warning
    A `.jar` is wrapped into a new zip on the server, so the MD5 of the local jar never matches the stored `md5`. Either compare against the `md5` returned by your last upload of that jar, or build the zip locally (a single `mods/<jar name>` entry) and upload the zip instead.

---

## DELETE /api/mod/{slug}/{version}

Delete a specific version of a mod.

**Permission required:** `mods_manage`

### Path Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `slug` | string | The mod slug (name). |
| `version` | string | The version string to delete. |

### Example Request

```bash
curl -X DELETE https://solder.example.com/api/mod/buildcraft/7.1.0 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Response (200)

```json
{
  "success": "Mod version deleted."
}
```

### Error Responses

**Mod not found (404):**

```json
{
  "error": "Mod not found."
}
```

**Version not found (404):**

```json
{
  "error": "Mod version not found."
}
```

**Version in use (409):**

```json
{
  "error": "Mod version is in use by 3 build(s) and cannot be deleted."
}
```

!!! note
    A mod version cannot be deleted while it is attached to any builds. Remove it from all builds first, then delete it.
