# 80% coverage floor for modules extracted from this host

**Status**: accepted

`MODULES.md` §24 requires `composer test:coverage` to enforce `--min=100` for every module. Greenfield commerce modules meet that; modules **extracted from this host** cannot, because the host has no meaningful test suite to extract coverage from — the fleet itself spans 7% to 100%. So an extracted module ships with a **floor of 80%**, ratcheting upward, while greenfield modules hold at 100 with no exemption.

## Considered options

Holding every module at 100 was rejected as the choice that actually produces less testing: a module that cannot pass its gate does not get extracted, so the boundary evidence extraction exists to produce is never generated, and the untested code stays in the host indefinitely.

Setting the floor from each module's measured coverage at extraction time was rejected because it makes the gate a measurement rather than a bar — a module with 12% coverage would "pass" at 12%.

## Consequences

A module can be released while below the standard's bar, so the coverage badge on an extracted module means less than the same badge on a greenfield one. The floor is a floor: it never moves down, and an upstream issue against §24 records the divergence rather than hiding it.
