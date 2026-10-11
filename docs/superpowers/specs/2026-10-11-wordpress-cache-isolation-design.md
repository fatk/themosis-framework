# WordPress cache inventory and isolation proposal

Status: inventory verified; proposed policy awaits approval. No cache fix,
drop-in installation, application configuration change, or migration is included.

## Boundaries

Preserve the agreed application settings `CACHE_DRIVER=redis`, `REDIS_DB=0`,
and `WP_REDIS_DATABASE=1`. Preserve existing external drop-ins. Those values
are user requirements, not values newly observed in a deployed application.
The available runtime is the isolated audit fixture; its configuration differs
from those application requirements. Do not infer production wiring from it.

`AUDIT.md` remains untouched and untracked. No flush or retained mutation probe
was executed during this inventory. Retained fixture files and volumes remain.

## Verified inventory, 2026-10-11

| Component | Observed audit runtime |
| --- | --- |
| WordPress site | `https://themosis-audit.local.dev/cms` |
| SQL database / tables | `themosis_audit` / `audit_`, host `mysql` |
| Active WordPress cache | Native `WP_Object_Cache` from WordPress core |
| Installed `content/object-cache.php` | Absent |
| Framework reflection | `/framework/src/Core/Cache/WordPressCacheWrapper.php` |
| Application default store | `file`, `Illuminate\Cache\FileStore` |
| Named `redis` store | Redis connection `cache`; actual Predis client selects DB 1 |
| Redis connection `default` | Configured DB 0 |
| Redis host / prefix | Audit-only `valkey:6379` / `audit_app_` |
| Three agreed environment names | Unset as environment variables and PHP constants in this fixture |
| Valkey exposure | Only `themosis-audit_backend`, no host port bindings |

The repository bundles an optional drop-in. Its `wp_cache_init()` resolves
`app('cache.store')`, not a WordPress-specific store or `WP_REDIS_DATABASE`.
Enabling it unchanged in this fixture would therefore use the application
FileStore. The inventory did not enable it.

`WordPressCacheWrapper::flush()` calls repository `clear()`. The installed
RedisStore flushes its entire selected database regardless of prefix; FileStore
flushes its cache directory. A dedicated prefix alone cannot isolate flushes.
Direct wrapper construction with a shared repository has the same problem.

Other source prerequisites are confirmed: zero TTL is forwarded to repository
set/put, which deletes on nonpositive TTL; add uses a read followed by a write;
repository add with null TTL is also not atomic; RedisStore add with zero TTL
uses at least one second. FileStore's direct add uses an exclusive file lock
and treats zero as forever. Nonpersistent groups are registered but unused;
get performs has/get and does not reset found on a miss.

## Alternatives for approval

| Approach | Isolation | Cost / limitation |
| --- | --- | --- |
| Explicit WordPress store plus namespace generations (recommended) | Physical DB 1 separation when actually wired; scoped logical invalidation even for direct shared-repository wrappers; never store-wide clear | Generation freshness adds a metadata read per persistent operation. Old generations, including forever entries, need later reclamation. |
| Backend-specific scoped deletion | Delete only encoded WordPress keys; preserve other namespaces; ordinary lookup needs one payload read | Redis requires incremental scanning and race handling. FileStore hashes keys, so shared directories require an ownership index and locking. Considerably more implementation and recovery work. |
| Exclusive store with store-wide flush | Simple and fast only when the entire DB/directory belongs to this cache | An assertion of exclusivity cannot protect arbitrary directly supplied shared repositories or unrelated raw keys. Does not meet the shared-store regression requirements alone. |

## Recommended proposed contract

1. The optional bundled drop-in selects a named store through an explicit
   application configuration entry, proposed `cache.wordpress.store`. It must
   not silently fall back to the application default. Missing selection reports
   a clear configuration error when this optional drop-in is used. Existing
   external drop-ins and application Redis settings remain untouched.
2. Deployment wiring, if separately requested, maps that named WordPress store
   to the existing WordPress connection/DB 1; application cache stays on DB 0.
   The framework does not reinterpret or rename the existing environment names.
3. A proposed `cache.wordpress.namespace` identifies one WordPress installation.
   Multisite blogs share the installation namespace, with separate blog/global
   dimensions. Direct construction gains an optional namespace argument without
   changing the existing three arguments; wrappers using the same namespace
   intentionally share entries and scoped flushes.
4. Keys have a new versioned root, with unambiguous encoding of installation,
   generation, group, and blog/global dimensions plus the original key. Empty
   groups normalize to `default`. No old ambiguous keys are read or copied.
5. Scoped flush rotates a randomly unique generation token. Initial generation
   publication is atomic; flush never invokes repository clear, Redis FLUSHDB,
   or directory-wide deletion. Operations read current generation metadata
   rather than retaining a stale token across persistent operations. Operations
   that overlap flush may complete in the old generation, but cannot repopulate
   the current generation with an old token.
6. Migration is a cold cache transition, with no global flush. Old entries
   remain untouched. Forever-entry reclamation needs an explicit later policy;
   this proposal accepts that storage tradeoff only if approved.
7. Null has a distinct stored representation; numeric payloads remain compatible
   with backend atomic increments. Each lookup reads its payload once, resets
   found, and observes deletion/fresh data. Generation metadata is an additional
   backend read: this is an explicit performance tradeoff requiring approval,
   not a claim that total backend operations fall to one.
8. Nonpersistent groups use request-local storage consistently for every cache
   operation, including blog/global handling and flush. Persistent cache fixes
   must not introduce cross-request memoization.

The named configuration entries and generation tradeoff above are proposals,
not existing supported APIs. Backend implementation details and support limits
must be specified in the implementation plan before code is changed. In
particular, forever atomic add must preserve Redis client serialization and
compression; `Repository::add(..., null)` is not an acceptable fallback.

## Verification required by the eventual implementation

Use disposable FileStore directories and separately bootstrapped drop-in
processes. Redis tests must guard an explicitly assigned disposable audit
connection before writes. Do not reuse the retained Valkey probe: it selects
and flushes audit DB 0.

Cover expiry/default/forever and atomic two-writer add; ambiguous group/key
pairs; multiple blogs and global groups; direct shared repositories; same-prefix,
different-prefix, and raw application sentinels; scoped flush across wrappers;
nonpersistent groups; null/false/zero/empty values; found reset; fresh/forced
reads and numeric operations. Report payload and metadata operation counts
separately under the approved design. Existing unit/WordPress suites must remain
green, and no application configuration or installed drop-in changes are part
of framework implementation.

## Approval gate

The audit remediation plan explicitly requires configuration inventory and
isolated-store/namespace migration approval for T9. Inventory is now complete
for the audit fixture. Approve an isolation approach and its migration/performance
tradeoffs before authorizing T8–T11 implementation. Deployment to another site
requires checking that site's effective drop-in and connections separately.
