# Release tags are bare, not `v`-prefixed

**Status**: accepted

`CI.md` §Release policy illustrates a protected version tag as `v1.2.3`. The fleet's own release workflows trigger on `tags: ['[0-9]+.[0-9]+.[0-9]+']`, which **excludes that example**. Commerce tags bare — `1.4.0` — matching the triggers rather than the prose.

## Why this is not cosmetic

`module-analytics-core` carries `1.4.0`, `1.4.1` **and `v1.0.4`**. Because `install.yml` and `compatibility.yml` only fire on bare tags, **`v1.0.4` was published having silently skipped both release gates.** A release that looks gated and was not is worse than one that is openly ungated.

## Considered options

Widening the triggers to accept both forms was rejected for exactly that reason: it makes the two formats interchangeable in the tag list while leaving every historical `v`-tag looking as though it passed gates it never ran.

Following `CI.md` and switching to `v`-prefixed tags fleet-wide was rejected as the larger change, made to satisfy an example rather than a rule — §Release policy says *"a protected version tag such as `v1.2.3`"*, which is illustrative phrasing.

## Consequences

One format, enforced by the triggers themselves. An upstream issue against `CI.md`'s example, and the stray `v`-tags in the fleet cleaned up so nothing published claims a gate it skipped.
