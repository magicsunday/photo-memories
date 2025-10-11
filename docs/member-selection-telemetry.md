# Telemetrievertrag der Mitgliederselektion

Dieses Dokument beschreibt den Aufbau der Selektor-Telemetrie, die unter
`member_selection` in Cluster-Drafts abgelegt wird, sowie das JSONL-Format der
Monitoring-Ausgaben des `FileJobMonitoringEmitter`.

## Member-Selection-Parameter

Jeder kuratierte Draft enthält unter `member_selection` Diagnosewerte zum
Selektionslauf. Die Struktur führt jetzt für jeden Ausschlussgrund des
policy-gesteuerten Selektors einen expliziten Zähler. Ein Beispiel:

```json
{
  "profile": "default",
  "counts": {
    "pre": 96,
    "post": 64,
    "dropped": 32
  },
  "spacing": {
    "average_seconds": 1185.3,
    "samples": [420, 960, 1800],
    "rejections": 7
  },
  "near_duplicates": {
    "blocked": 4,
    "replacements": 1
  },
  "per_day_distribution": {"2024-06-10": 5},
  "per_bucket_distribution": {"2024-06-10#slot_3": 2},
  "options": {
    "selector": "…\\PolicyDrivenMemberSelector",
    "target_total": 72,
    "max_per_day": 6,
    "min_spacing_seconds": 900,
    "phash_min_hamming": 10,
    "max_per_staypoint": 3,
    "enable_people_balance": true,
    "people_balance_weight": 0.4,
    "repeat_penalty": 0.2
  },
  "hash_samples": {"123": "ffeedd"},
  "exclusion_reasons": {
    "time_gap": 9,
    "phash_similarity": 3,
    "staypoint_quota": 2,
    "orientation_balance": 1,
    "scene_balance": 2,
    "people_balance": 4
  },
  "telemetry": {
    "counts": {"pre": 96, "post": 64, "dropped": 32},
    "rejections": {
      "time_gap": 9,
      "phash_similarity": 3,
      "staypoint_quota": 2,
      "orientation_balance": 1,
      "scene_balance": 2,
      "people_balance": 4
    },
    "drops": {"selection": {"spacing_rejections": 7}}
  }
}
```

Die Map `exclusion_reasons` entspricht der Selektor-Telemetrie und führt immer
alle sechs bekannten Gründe (`time_gap`, `phash_similarity`, `staypoint_quota`,
`orientation_balance`, `scene_balance`, `people_balance`). Wenn kein Ausschluss
stattfand, ist der Zähler `0`.

## Monitoring-Log-Payload

Der `FileJobMonitoringEmitter` schreibt JSON-Zeilen nach
`%memories.monitoring.log_path%`. Jede Zeile enthält das Feld
`schema_version`, dessen Standardwert über den Parameter
`memories.monitoring.schema_version` konfiguriert wird.

Phasenmetriken werden in folgende Top-Level-Schlüssel aufgefächert:

- `phase_counts`: verschachtelte Zähler pro Phase und Messgruppe.
- `phase_medians`: Medianwerte der aufgezeichneten Stichproben.
- `phase_percentiles`: Perzentilzusammenfassung (derzeit `p90` und `p99`).
- `phase_durations_ms`: Ausführungszeit je Phase in Millisekunden.

Beispiel für einen Log-Eintrag:

```json
{
  "job": "cluster_member_selection",
  "status": "completed",
  "schema_version": "2024-08",
  "algorithm": "vacation",
  "members_pre": 96,
  "members_post": 64,
  "profile": "default",
  "phase_counts": {
    "filtering": {"members": {"input": 96, "loaded": 92}},
    "selecting": {
      "members": {"pre": 92, "post": 64, "dropped": 28},
      "rejections": {"time_gap": 9, "phash_similarity": 3}
    },
    "consolidating": {
      "media_types": {"photos": 58, "videos": 6}
    }
  },
  "phase_medians": {
    "consolidating": {
      "spacing_seconds": 840.0,
      "phash_hamming": 12.0
    }
  },
  "phase_percentiles": {
    "consolidating": {
      "spacing_seconds": {"p90": 1800.0, "p99": 2400.0}
    }
  },
  "phase_durations_ms": {
    "filtering": 8.4,
    "summarising": 12.6,
    "selecting": 25.1,
    "consolidating": 14.8
  },
  "timestamp": "2024-08-20T17:12:45+00:00"
}
```

Downstream-Auswerter sollten `schema_version` nutzen, um Schemaänderungen zu
erken­nen, und die Phaseninformationen aus den `phase_*`-Feldern extrahieren.
