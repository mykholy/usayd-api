# USYD-T0030: Feature Flags & Kill Switches Architecture
**Jira Key:** [USYD-69](https://mykholy.atlassian.net/browse/USYD-69)  
**External Task ID:** `USYD-T0030`  
**Candidate Sprint:** `S01`  
**Authoritative Specifications:** `IMPLEMENTATION_GATES.md` (Gate B), `DECISION_REQUIRED_FROM_OWNER.md`

---

## 1. Executive Summary & Objective

In accordance with Gate B of the Usayd Academy implementation gates:
> *Modules listed under "BLOCKS ..." in DECISION_REQUIRED_FROM_OWNER.md must remain behind their flags with safe defaults off.*

This capability delivers a database-backed, Redis-cached feature flag and kill switch engine enabling:
1. **Safe-by-Default Operation:** Sensitive features (in-app video, AI audio analysis, session recording, minor paid learning, public pricing) default to `false` until formally approved by the owner/legal counsel.
2. **Instant Kill Switch Execution:** Immediate administrative revocation with 60-second Redis cache invalidation.
3. **Audited Toggling:** All administrative flag updates require `X-Audit-Reason` and generate immutable records in `audit_events`.
4. **RFC 7807/9457 Integration:** Disabled features return structured Problem Details (`503 Service Unavailable` with `FEATURE_DISABLED` or `404 Not Found` for hidden kill switches).
5. **Targeting Rule Support:** Flexible JSON rules evaluating roles, user IDs, countries, and percentage-based rollouts.

---

## 2. Canonical Flags & Safe Default Matrix

| Flag Key | Safe Default | Governing Decision | Target Capability / Impact |
|---|---|---|---|
| `feature_waitlist_only` | `true` | `O-01` | Restricts public registrations to waitlist mode; disables immediate checkout. |
| `feature_paid_learning_minors` | `false` | `O-04` | Restricts direct minor payment enrollment until guardian verification is signed. |
| `feature_in_app_video` | `false` | `O-03` | Gates live video room initialization until conferencing vendor contract is executed. |
| `feature_ai_analysis` | `false` | `O-05` | Disables automated voice recognition and pronunciation analysis. |
| `feature_recording` | `false` | `O-05` | Disables audio/video session recording on servers. |
| `feature_public_pricing` | `false` | `O-02` | Hides hardcoded pricing tiers; renders slots until approved by commercial team. |
| `feature_safeguarding_break_glass` | `false` | `D-39` | Disables emergency safeguarding case override until break-glass policy is ratified. |

---

## 3. Architecture & Core Components

```
Client Request
      │
      ▼
CheckFeatureFlag Middleware
      │
      ├──► FeatureFlagService::isEnabled($key, $user)
      │         │
      │         ├──► Redis Cache ('usayd:feature_flag:{key}') (TTL 60s)
      │         │         │
      │         │    (Cache Miss)
      │         │         ▼
      │         ├──► MySQL 8.4 'feature_flags' table
      │         │         ▼
      │         └──► Rule Engine (Roles, Users, Countries, % Rollout)
      │
      ├── Enabled: Proceed to Next Middleware / Controller
      │
      └── Disabled: Throw ApiException (503 Service Unavailable / 404 Hidden)
                │
                ▼
          RFC 7807 Problem Details Response (traceId correlation)
```

---

## 4. Administrative Endpoints & Audit Trail

### 4.1 List All Flags
- **Route:** `GET /api/v1/admin/feature-flags`
- **Authorization:** `auth:sanctum`, `role:admin`
- **Response:**
  ```json
  {
    "data": [
      {
        "id": 1,
        "key": "feature_in_app_video",
        "name": "الغرف المرئية التفاعلية داخل التطبيق",
        "description": "تفعيل غرف الفيديو المباشرة عبر مزود معتمد (O-03)",
        "is_enabled": false,
        "rules": null,
        "created_at": "2026-11-24T06:00:00.000000Z",
        "updated_at": "2026-11-24T06:00:00.000000Z"
      }
    ]
  }
  ```

### 4.2 Toggle Flag or Update Rules
- **Route:** `PUT /api/v1/admin/feature-flags/{key}`
- **Authorization:** `auth:sanctum`, `role:admin`
- **Mandatory Header:** `X-Audit-Reason: Enable in-app video trial for approved testers`
- **Request Body:**
  ```json
  {
    "is_enabled": true,
    "rules": {
      "roles": ["teacher", "admin"]
    }
  }
  ```
- **Audit Logging:** An immutable record is appended to `audit_events` capturing `actor_id`, `action: 'feature_flag.updated'`, `purpose`, and `metadata`.
