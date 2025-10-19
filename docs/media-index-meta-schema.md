## Media index meta schema

The `MetaExportStage` consolidates all signals collected during media indexing and writes a
`media_index.meta` JSON document next to each processed asset. The payload is versioned via the
`schema_version` field to allow additive evolution without breaking downstream consumers.

### Location and lifecycle

- File name: `media_index.meta`
- Directory: same folder as the ingested media file (`dirname(Media::getPath())`)
- Creation: after all extractor stages have populated the `Media` entity but before persistence
- Dry-run behaviour: no file is written when the ingestion pipeline runs in dry-run mode
- Overwrite behaviour: each successful run replaces the previous file atomically

### Top-level structure

| Field             | Type                                      | Description |
|-------------------|-------------------------------------------|-------------|
| `schema_version`  | integer                                   | Format revision of the JSON document. Starts at `1`. |
| `identity`        | object                                    | Stable identifiers and binary-level metadata (path, checksums, MIME). |
| `flags`           | object                                    | Boolean switches describing processing state (video/raw/rotation/no-show/geocode). |
| `capture`         | object                                    | Timestamps, timezone information, and sub-second precision. |
| `dimensions`      | object                                    | Width, height, and orientation taken from metadata. |
| `spatial`         | object                                    | GPS coordinates, hashed cells, home distance, resolved location, and heuristic place IDs. |
| `video`           | object                                    | Video-specific metadata such as duration, codec, rotation, stream descriptors, stabilisation, and slow-motion flags. |
| `imaging`         | object                                    | Camera, lens, and exposure properties. |
| `classification`  | object                                    | Content kind, namespaced features, scene tags, and face metrics. |
| `quality`         | object                                    | Aggregated quality scores and threshold flags. |
| `hashes`          | object                                    | Perceptual hashes, fast checksums, and live-photo linkage hashes. |
| `relationships`   | object                                    | Burst grouping metadata and live-photo partner references. |
| `thumbnails`      | object or null                            | Map of generated thumbnails keyed by label. |
| `qa_findings`     | array of objects (may be empty)           | QA findings containing missing feature lists and remediation hints. |

### Field semantics

- **Dates** are emitted in ISO 8601 (`DateTimeInterface::ATOM`) format; missing values are represented as `null`.
- **Floats** retain their precision (`JSON_PRESERVE_ZERO_FRACTION`) to avoid ambiguity between integers and decimal scores.
- **Location payloads** mirror the Doctrine entity properties (`provider`, `display_name`, `country_code`, `bounding_box`, `pois`, etc.).
- **Feature data** inside `classification.features` keeps the namespaced map produced by `MediaFeatureBag::toArray()`.
- **QA findings** are only emitted when `MetadataQaInspectionResult::hasIssues()` returns true. Each entry contains:
  - `missing_features`: list of missing feature keys
  - `suggestions`: list of human-readable remediation hints
- **Video metadata** provides detailed playback characteristics:
  - `video_duration_s`: duration of the primary video stream in seconds (float, may be `null`).
  - `video_fps`: frames per second for the primary stream (float, may be `null`).
  - `video_codec`: codec identifier reported by the probe (string, may be `null`).
  - `video_streams`: normalised ffprobe stream descriptors (array of objects, may be `null`).
  - `video_rotation_deg`: clockwise rotation in degrees required for playback (float, may be `null`).
  - `video_has_stabilization`: whether stabilisation metadata is present (boolean, may be `null`).
  - `is_slow_mo`: indicates slow-motion captures (boolean, may be `null`).

### Example excerpt

```json
{
  "schema_version": 1,
  "identity": {
    "path": "/library/2024/10/05/sample.jpg",
    "created_at": "2024-10-05T12:34:12+00:00",
    "indexed_at": "2024-10-05T12:36:42+00:00",
    "feature_version": 7,
    "checksum_sha256": "…",
    "fast_checksum_xxhash64": "feedfacecafebeef",
    "size_bytes": 1248756,
    "mime": "image/jpeg"
  },
  "flags": {
    "is_video": true,
    "is_raw": true,
    "needs_rotation": false,
    "needs_geocode": false,
    "no_show": false
  },
  "video": {
    "video_duration_s": 9.87,
    "video_fps": 59.94,
    "video_codec": "h264",
    "video_streams": [
      {
        "index": 0,
        "codec_type": "video",
        "codec_name": "h264"
      }
    ],
    "video_rotation_deg": 90,
    "video_has_stabilization": true,
    "is_slow_mo": false
  },
  "qa_findings": [
    {
      "missing_features": ["calendar.daypart"],
      "suggestions": ["Check timezone votes"]
    }
  ]
}
```

### Versioning guidelines

- Increment `schema_version` when adding breaking changes or renaming fields.
- Prefer additive changes (new optional fields) and document them alongside stage updates.
- Downstream consumers should treat unknown fields as optional and only depend on documented keys.

For implementation details see `MetaExportStage` under
`src/Service/Indexing/Stage/MetaExportStage.php`.
