# Data Sync

When two Qbix Servers meet over the mesh, they can bring each other's tables up to date without a central database. The server provides the protocol: finding which records differ, moving them over the encrypted peer session, and resolving conflicts. Your app provides the storage, through three callbacks per table.

This page covers how to register a table, what happens during a sync, how conflicts are resolved, and what the current implementation does not yet do. The wire protocol, identity and routing are specified in [Mesh.md](Mesh.md).

## Registering a table

```php
Q_WebServer_MeshSync::registerTable('notes', array(
    // Return records as key => ['value' => ..., 'version' => int, 'updated' => unix time].
    // $keys = null means "all records updated after $since".
    'getRecords' => function ($keys = null, $since = 0) {
        return Notes::fetch($keys, $since);
    },

    // Store records that won; return how many were written.
    'putRecords' => function (array $records) {
        return Notes::upsert($records);
    },

    // Optional. Called when both sides changed the same record to the same version.
    // Return the record to keep, or null to keep ours.
    'mergeConflict' => function ($key, $local, $remote) {
        return array_merge($local, array(
            'value' => $local['value'] . "\n" . $remote['value'],
            'version' => $local['version'] + 1,
            'updated' => time(),
        ));
    },
));
```

Each record carries a `version` that your code increments on every write, and an `updated` timestamp. Keys are strings and must be the same on every device, so use IDs generated where the record is created (a UUID, or the creating peer's ID plus a counter), not auto-increment row numbers.

## Syncing when a peer appears

```php
Q_WebServer_Transport::onPeerOnline(function ($peer) {
    $since = LastSync::get($peer['peer_id'], 'notes');
    $result = Q_WebServer_MeshSync::syncWithPeer(
        'notes', $peer['peer_id'], $since,
        array('Q_WebServer_Transport', 'requestFromPeer')
    );
    LastSync::set($peer['peer_id'], 'notes', time());
});
```

`syncWithPeer()` returns a summary: how many records the peer was missing and was sent, how many were received, and the merge counts on each side.

## What happens during a sync

1. **Exchange Bloom filters.** Each side builds a Bloom filter of the keys it has changed since `$since`, and fetches the other's from `GET /Q/sync/bloom`. A filter is a compact bit array that answers "is this key in the set?" with no false negatives and occasional false positives. It is sized to the table, at about 1.8 bytes per key (10,000 keys is 18KB), for a false-positive rate near 0.1%.
2. **Send what they lack.** Any of our keys that are definitely absent from their filter are sent to `POST /Q/sync/merge`, where the peer merges them.
3. **Fetch what we lack.** We send our filter to `POST /Q/sync/records`, and the peer returns its records whose keys are absent from it. We merge those locally.

All of these requests travel over the peer's encrypted session (ECDH key agreement, AES-256-GCM), relayed through intermediate peers when there is no direct link.

## How records are merged

For each incoming record:

| Situation | Result |
|---|---|
| We don't have the key | Accept it |
| Their `version` is higher | Accept it |
| Their `version` is lower | Keep ours |
| Same version, same value | Nothing to do |
| Same version, different value | Conflict: call `mergeConflict`, or, if there is none, keep the one with the later `updated` time, breaking exact ties by comparing the values so every peer picks the same winner |

Records that win are passed to your `putRecords` callback in one batch.

## Prolly trees

For large tables, comparing Bloom filters costs space proportional to the table. A prolly tree is a content-addressed search tree whose shape depends only on its contents, so two peers with nearly identical tables have nearly identical trees, and a diff only needs to descend into the branches whose hashes differ. `Q_WebServer_ProllyTree` builds these trees and diffs them. In the test suite, two 10,000-record trees differing in 100 records are diffed in about 1.5ms, touching about 11KB of hashes.

The endpoint `POST /Q/sync/tree` serves a table's root hash and individual nodes. When a sync estimates that more than 500 records are missing, it fetches the peer's root first and skips the transfer entirely if the roots match.

## Current limitations

These are the gaps between the protocol as designed in [Mesh.md](Mesh.md) and what ships in v2.0:

- **The tree walk is not yet done over the wire.** When the roots differ, the sync falls back to the Bloom-filter record fetch described above rather than walking the peer's tree node by node. The tree diff is implemented and tested locally; the remaining work is the request loop.
- **Filters hold keys, not versions.** A record that exists on both sides is in both filters, whatever its version. An edit reaches the other peer only when that peer's copy is older than `$since`, so that its filter leaves the key out. Pass the time of the last successful sync with that peer as `$since`, as in the example above. Two peers that both edit the same record after their last sync will not detect the conflict in the Bloom pass.
- **False positives delay records.** At the ~0.1% rate, roughly one in a thousand missing records is not sent in a given round. It will usually be sent in a later round, once the sets or `$since` have changed. If your app cannot tolerate that, run a sync with `$since = 0` periodically.
- **Multi-hop relay over HTTP is incomplete.** Routing tables, HELLO propagation and forward envelopes are implemented and pass the in-process routing suite, but when a `FORWARD` arrives over HTTP the intermediate node computes the next hop without sending it on, and the destination acknowledges delivery without running the request. Direct peer-to-peer requests over an encrypted session work end to end (`tests/test_mesh_e2e.sh`); syncs between peers that are not directly connected do not yet.
- **BLE payload size.** A single message over BLE GATT is limited to 64KB, which a filter reaches at around 36,000 changed keys. Keep `$since` recent for large tables synced over Bluetooth.

## Endpoints

| Endpoint | Purpose |
|---|---|
| `GET /Q/sync/tables` | Tables registered on this peer |
| `GET /Q/sync/bloom?table=T&since=S` | Bloom filter of keys changed since `S` |
| `POST /Q/sync/records` | Records whose keys are absent from the posted filter |
| `POST /Q/sync/merge` | Merge posted records into a table |
| `POST /Q/sync/tree` | Prolly tree root (`action: root`) or node (`action: node`) |

---
[← Mesh Protocol](Mesh.md) · [iOS & Android →](mobile.md) · [← Back to README](../README.md)
