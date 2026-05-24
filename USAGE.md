# mongo-php — Usage Guide

A full walkthrough of every screen and feature. If you just want to install the tool, see [README.md](README.md).

## Table of contents

- [Concepts: MongoDB vs SQL terminology](#concepts-mongodb-vs-sql-terminology)
- [Logging in](#logging-in)
- [The Server view (home)](#the-server-view-home)
- [The Database view](#the-database-view)
- [MongoDB migration (export / restore)](#mongodb-migration-export--restore)
- [SQL import / export](#sql-import--export)
- [The Collection tabs](#the-collection-tabs)
  - [Browse](#browse)
  - [Structure](#structure)
  - [Query](#query)
  - [Insert](#insert)
  - [Edit](#edit)
  - [Indexes](#indexes)
  - [Stats](#stats)
  - [Operations](#operations)
- [The Status view](#the-status-view)
- [Extended JSON (EJSON) cheat sheet](#extended-json-ejson-cheat-sheet)
- [Common recipes](#common-recipes)
- [Keyboard / shortcut behaviour](#keyboard--shortcut-behaviour)
- [Troubleshooting](#troubleshooting)
- [FAQ](#faq)

---

## Concepts: MongoDB vs SQL terminology

If you're coming from phpMyAdmin/MySQL, this is the rough mapping:

| SQL world          | MongoDB world                         |
|--------------------|----------------------------------------|
| Database           | Database                               |
| Table              | **Collection**                         |
| Row                | **Document**                           |
| Column             | Field                                  |
| Primary key        | `_id` field (auto-generated ObjectId)  |
| `SELECT ... WHERE` | `find(filter)`                         |
| `JOIN`             | `$lookup` stage in an aggregation      |
| `GROUP BY`         | `$group` stage in an aggregation       |
| `CREATE INDEX`     | `createIndexes` command                |
| Stored procedure   | aggregation pipeline / `runCommand`    |

A key difference: **MongoDB has no fixed schema.** Two documents in the same collection can have totally different fields. mongo-php's *Structure* tab samples documents to give you a best-effort view of what's actually there.

## Logging in

Open `https://your-host/mongo-php/login.php`. Fields:

| Field        | Default     | Notes |
|--------------|-------------|-------|
| Host         | `127.0.0.1` | IP, hostname, or DNS name. For Atlas, use a single host or the SRV hostname (TLS recommended). |
| Port         | `27017`     | |
| Username     | *(blank)*   | Leave blank for a server with no auth. |
| Password     | *(blank)*   | |
| Auth DB      | `admin`     | The database the user was created in. For most setups this is `admin`. |
| Replica Set  | *(blank)*   | Optional. Set to e.g. `rs0` if connecting to a replica set. |
| TLS          | off         | Tick this for Atlas, or any TLS-protected cluster. |
| Allow invalid TLS certs | off | Insecure — use only against staging/dev with self-signed certs. |

Credentials are stored in your PHP session only. Closing the browser, hitting **Logout**, or letting the session expire clears them.

**Connecting to MongoDB Atlas:** use the cluster's primary host (`cluster0-shard-00-00.xxxx.mongodb.net`), port `27017`, your DB user/pass, auth DB `admin`, replica set name as shown in the Atlas UI, and tick **TLS**. Make sure your server's outbound IP is on the Atlas allow-list.

## The Server view (home)

`/index.php` — the landing screen after login. Shows:

- **MongoDB version**, host, current user, auth DB, TLS state.
- **Databases table**: every database with size on disk, collection count, document count.
- **Create database** form at the bottom.

### Creating a database

MongoDB creates databases *lazily* — they don't exist until they have a collection. The form takes a database name **and an initial collection name** so the new database shows up in the sidebar immediately.

```
Database name:      shop
Initial collection: products
```

### Dropping a database

Click **Drop** on the row. You'll be prompted to type the database name exactly — protection against fat-fingered drops. System databases (`admin`, `local`, `config`) cannot be dropped from here.

## The Database view

Click a database name in the table or the left sidebar. You see:

- A stats summary (collections, objects, data/storage/index size).
- The list of collections with per-collection counts and sizes.
- Per-collection action buttons: **Browse**, **Structure**, **Operations**, **Truncate**, **Drop**.
- A **Create collection** form (with capped-collection options).
- An **Export (MongoDB archive)** button and a **Restore MongoDB archive** button — see [MongoDB migration](#mongodb-migration-export--restore).
- An **Export to SQL (MySQL)** button and an **Import MySQL dump** button — see [SQL import / export](#sql-import--export).
- A red **Drop database** button (typed-name confirmation).

### Capped collections

Capped collections are fixed-size, FIFO collections — useful for logs, queues, last-N caches. Tick **Capped**, set the byte size, and optionally a max document count.

```
Name:           events
☑ Capped collection
Size (bytes):   10485760     # 10 MB
Max documents:  100000
```

## MongoDB migration (export / restore)

For moving a database **between MongoDB servers** — a `mongodump`/`mongorestore`-free alternative that runs entirely in PHP. The Database view has two buttons for it.

### Export (MongoDB archive)

**Export (MongoDB archive)** streams the selected database to your browser as a `.mongo.json` download (named `<db>-<timestamp>.mongo.json`). The file is a portable archive:

- It's **line-delimited Extended JSON v2** (NDJSON): a `meta` header line, then for each collection a `collection` line carrying its creation options and index specs, followed by one `doc` line per document.
- **All BSON types round-trip losslessly** — `ObjectId`, `UTCDateTime`, `Decimal128`, `Binary`, `Regex`, `Timestamp`, etc. are preserved exactly, unlike the lossy MySQL export.
- Export walks a server-side cursor and writes incrementally, so exporting a large database doesn't load it all into memory.

System collections (`system.*`) and views are skipped; only real collections are exported.

### Restore MongoDB archive

**Restore MongoDB archive** opens an upload page where you select a `.mongo.json` archive produced by Export on another server (or the same one). Restoring into an **empty** database is recommended (you'll see a warning otherwise, since restore appends and may collide on `_id`).

What restore does:

- Recreates each collection with its original options, then **inserts documents keeping their original `_id`** (in batches, so a large archive doesn't exhaust memory).
- Recreates every non-`_id` index from the archived index specs.
- On `_id` collisions (e.g. restoring into a non-empty DB) the affected batch is reported as a warning rather than aborting the whole restore.

After restore you get a per-collection report: documents restored, indexes created, the source database name, and any warnings. Lines with no preceding collection or an unknown type are counted under "skipped".

> **Typical migration:** on the source server click **Export (MongoDB archive)**; on the target server, create (or select) an empty database and click **Restore MongoDB archive**, then upload the file. The upload is read from PHP's temp directory — no writable project folder is needed, and max size is bounded by your PHP `upload_max_filesize` / `post_max_size`.

## SQL import / export

The Database view has two buttons for moving data between MongoDB and MySQL. Both are zero-dependency and run entirely in PHP.

### Export to SQL (MySQL)

**Export to SQL (MySQL)** streams the selected database as a `mysqldump`-compatible `.sql` file straight to your browser as a download. For each collection it:

- walks the documents to discover the union of fields and their BSON types, then emits a `CREATE TABLE` whose column types are the closest MySQL fit (nested objects/arrays become `JSON` columns);
- emits the data as batched `INSERT` statements;
- recreates MongoDB indexes as `CREATE INDEX` statements (dotted sub-field index keys are skipped — they have no column equivalent).

MongoDB has no relational joins or foreign keys, so no `FOREIGN KEY` clauses are produced; soft references like `customer_id` are left as plain columns.

### Import MySQL dump

**Import MySQL dump** opens an upload page where you select a `mysqldump`-style `.sql` file. Importing into an **empty** database is recommended (you'll see a warning otherwise, since importing appends and may collide on `_id`).

What the importer does:

- **Each table → a collection**, each `INSERT` row → a document.
- **Type mapping** to BSON:

  | MySQL | MongoDB / BSON |
  |---|---|
  | `INT`, `BIGINT`, `SMALLINT`, `YEAR`, `BIT` | int (bigint beyond 64-bit kept as string) |
  | `DECIMAL` / `NUMERIC` | Decimal128 |
  | `FLOAT`, `DOUBLE`, `REAL` | double |
  | `TINYINT(1)`, `BOOL` | bool |
  | `DATE`, `DATETIME`, `TIMESTAMP` | UTCDateTime (interpreted as UTC; `0000-00-00` → null) |
  | `JSON` | nested object / array |
  | `BLOB`, `BINARY`, `VARBINARY` | Binary |
  | everything else (`VARCHAR`, `TEXT`, `ENUM`, …) | string |

- **Primary keys:** a single-column `PRIMARY KEY` is folded into `_id` (so a row with `id = 42` becomes `{ "_id": 42, … }`, and foreign-key values line up with the referenced documents' `_id`). Composite or missing primary keys get a generated `ObjectId` plus a unique index on the original key columns.
- **Indexes:** `UNIQUE KEY` / `KEY` / `INDEX` definitions are recreated as MongoDB indexes.
- **Foreign keys / "joins":** MongoDB doesn't enforce joins, so `FOREIGN KEY`s are **not** recreated as constraints. Instead each foreign-key column is indexed, so application-side `$lookup` joins stay fast (the "keep references + index them" approach).

After import you get a report: per collection it shows rows imported, where `_id` came from, how many indexes and foreign-key indexes were created, and any warnings. Statements with no MongoDB equivalent (triggers, procedures, views, `ALTER`, `LOCK`, …) are skipped and counted.

> **Note:** the upload is read from PHP's temp directory — the app needs no writable folder. Maximum dump size is bounded by your PHP `upload_max_filesize` and `post_max_size`.

## The Collection tabs

Once you pick a collection, you get a tab strip across the top:

```
Browse · Structure · Query · Insert · Indexes · Stats · Operations
```

### Browse

Tabular, paginated view of documents.

- **Filter** — Extended JSON object, e.g. `{"status":"active"}`.
- **Sort** — e.g. `{"_id":-1}` or `{"createdAt":1,"name":1}`.
- **Projection** — limit which fields to fetch, e.g. `{"name":1,"email":1}`.
- **Limit / Skip** — pagination controls.

Columns are auto-derived from the union of top-level keys of the visible documents. Nested objects/arrays are shown as truncated JSON; hover for the full text. `_id` is shown first.

Per-row actions:

- **✎** — open the document in [Edit](#edit).
- **🗑** — delete the document (with confirm).

### Structure

Best-effort schema inference. mongo-php samples N documents (`$sample`) and reports:

- Every top-level field name seen
- What % of sampled documents had that field (presence)
- Which BSON types were observed and how often

Use the **Sample size** input to re-sample with a larger N (up to 1000).

The Structure tab also has a **Rename collection** form. You can rename a collection or move it to a different database. Requires admin privileges.

### Query

Three sub-modes, switchable with the pill-buttons at the top.

#### Find

The same form as Browse, but the result is rendered as **pretty-printed Extended JSON** (good for inspecting nested structures) instead of a flat table.

#### Aggregate

Free-form aggregation pipeline. Default template:

```json
[
  { "$match": {} },
  { "$limit": 50 }
]
```

Example — top 10 customers by lifetime spend:

```json
[
  { "$match": { "status": "active" } },
  { "$group": {
      "_id": "$customer_id",
      "total": { "$sum": "$amount" },
      "orders": { "$sum": 1 }
  }},
  { "$sort": { "total": -1 } },
  { "$limit": 10 }
]
```

Example — `$lookup` (SQL-style join):

```json
[
  { "$match": { "status": "shipped" } },
  { "$lookup": {
      "from": "customers",
      "localField": "customer_id",
      "foreignField": "_id",
      "as": "customer"
  }},
  { "$unwind": "$customer" },
  { "$project": { "_id": 1, "amount": 1, "customer.name": 1, "customer.email": 1 } }
]
```

#### Run command

Run an arbitrary database command against the selected database. Useful examples:

```json
{ "ping": 1 }
{ "buildInfo": 1 }
{ "serverStatus": 1 }
{ "dbStats": 1 }
{ "collStats": "orders" }
{ "currentOp": 1 }
{ "killOp": 1, "op": 1234567 }
{ "validate": "orders" }
{ "explain": { "find": "orders", "filter": { "status": "active" } } }
```

### Insert

JSON editor for a single new document. Extended JSON v2 format. Examples:

Plain document — `_id` will be auto-generated:

```json
{
  "name": "Widget",
  "price": 9.99,
  "tags": ["new", "featured"]
}
```

With explicit types:

```json
{
  "_id":       { "$oid":         "65a1f3c2e8b4d6f0a1234567" },
  "createdAt": { "$date":        "2026-01-01T00:00:00Z"     },
  "qty":       { "$numberLong":  "9999999999"               },
  "price":     { "$numberDecimal": "12.50"                  }
}
```

After insert you're redirected back to the Browse view so you can see the new document.

### Edit

A full-document JSON editor. The `_id` field is preserved on save — even if you accidentally remove or change it in the textarea, mongo-php will put the original back. This prevents broken references.

The Save button performs a **replace** (`replaceOne`), not a `$set` merge — what you see is exactly what's written. To merge instead, use [Operations → Update many](#operations).

A separate **Delete** button below the form removes the document (with confirm).

### Indexes

Lists every index on the collection: name, key spec, options.

**Create index form:**

| Field | Example | Notes |
|---|---|---|
| Key | `{"email": 1}` | JSON. `1` = ascending, `-1` = descending. |
| Name | `email_unique` | Optional. Auto-generated if blank. |
| Unique | ☐ | |
| Sparse | ☐ | Only index documents that have the field. |

More exotic indexes work too — just put the right spec in the **Key** field:

```json
// Compound
{"customer_id": 1, "createdAt": -1}

// Text
{"name": "text", "description": "text"}

// 2dsphere (geo)
{"location": "2dsphere"}

// Hashed (for sharding)
{"_id": "hashed"}

// Wildcard
{"$**": 1}
```

The `_id_` index cannot be dropped — that's enforced by MongoDB itself.

### Stats

Detailed `collStats` output for the collection: document count, average object size, data size, storage size, index sizes, capped status, and the raw stats document.

### Operations

Bulk and destructive operations, grouped on one page.

#### Update many

```
Filter: {"status": "pending"}
Update: {"$set": {"status": "archived", "archivedAt": {"$date": "2026-01-01T00:00:00Z"}}}
☐ Upsert
```

The update document must use operators (`$set`, `$inc`, `$push`, `$unset`, `$rename`, etc.). A plain document like `{"status": "archived"}` would be a **replace**, not an update — mongo-php does not allow that here; use [Edit](#edit) for replaces.

#### Delete many

Filter is required (empty filter is allowed but means *delete everything*). You must type `DELETE` in capitals to confirm.

#### Rename / move

Same form as the Structure tab — rename a collection or move it to a different database.

#### Truncate

Deletes all documents but keeps the collection and its indexes. You must type the exact collection name to confirm.

#### Drop

Removes the collection and all its indexes. Typed-name confirmation.

## The Status view

`/index.php?page=status` — the **Status** link in the top nav.

Shows a summary of `serverStatus`:

- Version, host, uptime
- Current and available connections
- Network bytes in/out
- Op counters (queries, inserts, updates, deletes, getmore, command)

Plus the full raw `serverStatus` output in a collapsible section.

## Extended JSON (EJSON) cheat sheet

mongo-php speaks **MongoDB Extended JSON v2 (canonical)** everywhere a JSON document is expected. EJSON lets plain JSON represent BSON-specific types.

| BSON type     | Extended JSON                                  |
|---------------|------------------------------------------------|
| ObjectId      | `{"$oid":"65a1f3c2e8b4d6f0a1234567"}`          |
| Date          | `{"$date":"2026-01-01T00:00:00Z"}`             |
| Int64         | `{"$numberLong":"9999999999"}`                 |
| Int32         | `{"$numberInt":"42"}`                          |
| Double        | `{"$numberDouble":"3.14"}`                     |
| Decimal128    | `{"$numberDecimal":"12.50"}`                   |
| Binary        | `{"$binary":{"base64":"...","subType":"00"}}`  |
| Regex         | `{"$regularExpression":{"pattern":"^foo","options":"i"}}` |
| Timestamp     | `{"$timestamp":{"t":1735689600,"i":1}}`        |

You only need this when you're providing input. The display side automatically renders these to readable strings (`ObjectId("...")`, `2026-01-01 00:00:00.000Z`, etc.).

## Common recipes

### Find all documents missing a field

```json
{"email": {"$exists": false}}
```

### Find documents matching a regex (case-insensitive)

```json
{"name": {"$regex": "smith", "$options": "i"}}
```

In EJSON form:

```json
{"name": {"$regularExpression": {"pattern": "smith", "options": "i"}}}
```

### Find documents in a date range

```json
{
  "createdAt": {
    "$gte": {"$date": "2026-01-01T00:00:00Z"},
    "$lt":  {"$date": "2026-02-01T00:00:00Z"}
  }
}
```

### Find documents where an array contains a value

```json
{"tags": "featured"}
```

### Find documents where an array contains *all* of these values

```json
{"tags": {"$all": ["featured", "new"]}}
```

### Sort by multiple fields

```json
{"customerId": 1, "createdAt": -1}
```

### Project only specific fields (and drop `_id`)

```json
{"_id": 0, "name": 1, "email": 1}
```

### Increment a counter on many docs

In **Operations → Update many**:

```
Filter: {"status": "active"}
Update: {"$inc": {"loginCount": 1}}
```

### Add an element to an array, avoiding duplicates

```
Update: {"$addToSet": {"tags": "featured"}}
```

### Remove a field from many docs

```
Filter: {}
Update: {"$unset": {"obsoleteField": ""}}
```

### Backfill a missing field

```
Filter: {"role": {"$exists": false}}
Update: {"$set": {"role": "user"}}
```

### Group + count (aggregation)

```json
[
  { "$group": { "_id": "$status", "count": { "$sum": 1 } } },
  { "$sort": { "count": -1 } }
]
```

### Top-N per group (aggregation)

```json
[
  { "$sort":  { "score": -1 } },
  { "$group": { "_id": "$category", "top": { "$push": "$$ROOT" } } },
  { "$project": { "top": { "$slice": ["$top", 3] } } }
]
```

## Keyboard / shortcut behaviour

- **Tab** inside any JSON textarea inserts two spaces (instead of moving focus).
- Confirm dialogs require **exact typed input** (collection name, database name, or `DELETE`) — there's no way to bypass them with the keyboard.
- Forms with `data-confirm="..."` use a standard browser `confirm()` dialog.

## Troubleshooting

### "MongoDB PHP extension not installed"

The `mongodb` PECL extension isn't loaded. See the [Requirements](README.md#requirements) section.

### "Authentication failed."

Check **Username**, **Password**, and **Auth DB** — the user must exist in the database named in *Auth DB* (usually `admin`). To verify from a shell:

```bash
mongosh --host 127.0.0.1 --port 27017 -u myuser -p --authenticationDatabase admin
```

If that works but mongo-php doesn't, double-check the form values.

### "No suitable servers found"

Network/firewall problem, or the host/port is wrong. Try:

```bash
nc -zv your-host 27017
```

For Atlas, make sure your server's outbound IP is on the cluster's IP allow-list.

### "TLS handshake failed"

Either you ticked **TLS** but the server doesn't speak TLS, or the server has TLS but you didn't tick the box, or the certificate isn't trusted. For self-signed certs in dev, tick **Allow invalid TLS certs** (insecure).

### "CSRF check failed"

Your session expired between loading a page and submitting a form. Hit back, reload, and try again. If it keeps happening, check that PHP sessions are working (your web server can write to the session save path).

### "Unknown top level operator: $..."

You're passing an update operator where MongoDB expects a filter, or vice versa. Filters use `$gt`/`$in`/`$regex`/etc., updates use `$set`/`$inc`/`$push`/etc. They are not interchangeable.

### A document won't display

If a document contains binary data, very deeply nested structures, or an invalid UTF-8 string, the display layer may fall back to a generic `(object)` rendering. Open the document in **Edit** to see the raw EJSON.

### The Structure tab shows no fields

The collection is empty, or the `$sample` stage isn't supported on your MongoDB version (very old releases). The tab falls back to a regular `find()` in that case.

## FAQ

**Q: Is this safe to expose on the public internet?**
A: No. Treat mongo-php like a root shell. Put it behind HTTP basic auth, a VPN, or IP allow-listing, and always serve over HTTPS. See [README — Production hardening](README.md#production-hardening).

**Q: Where are my MongoDB credentials stored?**
A: In your PHP session only — never written to disk by mongo-php. Closing the browser, hitting Logout, or letting the session expire clears them.

**Q: Does mongo-php support GridFS?**
A: Not directly in the UI. You can still inspect the underlying `fs.files` / `fs.chunks` collections like any other collection.

**Q: Does it work with MongoDB Atlas?**
A: Yes — use the cluster primary host, port `27017`, your DB user, auth DB `admin`, replica set name from the Atlas UI, and tick **TLS**. Add your server's IP to Atlas' allow-list.

**Q: Can I have multiple connections / saved hosts?**
A: Not currently. The login page remembers the last-used host / port / user / auth DB so you don't have to retype them, but only one connection per session.

**Q: Can I read-only / restrict what users can do?**
A: mongo-php inherits whatever permissions the MongoDB user has. Create a read-only MongoDB role (`read` or custom) and log in with that — the UI will surface "not authorized" errors for actions the user can't perform.

**Q: Does it support transactions?**
A: Not from the UI. You can run multi-statement transactions via the **Query → Run command** tab with `commitTransaction` / `abortTransaction`, but there's no transactional UI workflow.

**Q: Does it support change streams / tailable cursors?**
A: No — those are streaming APIs and don't fit a request/response web UI.
