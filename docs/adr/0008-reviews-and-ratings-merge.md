# The reviews/ratings merge keeps moderation and backfills customers

**Status**: accepted

Two parallel review stacks exist, both live, both in the GDPR export and erasure paths, both still under active development. `ProductReview` and `ProductRating` survive the merge. Two details are load-bearing and easy to lose:

- **The `approved` moderation column is ported.** Without it the public review listing becomes unmoderated the moment the merge lands — a content-safety regression disguised as a schema cleanup.
- **Reviews by a `User` with no `Customer` record get a `Customer` backfilled**, rather than being dropped as unmappable. Deleting a customer's published review to simplify a migration is not a migration decision.

## Considered options

Dropping the losing stack outright was rejected on both counts above.

Deferring the merge until after extraction was rejected because the two stacks would then land in different modules, making the merge a cross-module change rather than an in-host one. **All four duplicate-stack merges happen before their module is extracted.**

## Consequences

The merge is the most expensive of the four and touches GDPR paths, so it is rehearsed against production-shaped data rather than run directly.

Reviews and ratings remain genuinely separate concepts afterwards — see [`CONTEXT.md`](../../CONTEXT.md). A rating without a review is normal, not an incomplete record.
